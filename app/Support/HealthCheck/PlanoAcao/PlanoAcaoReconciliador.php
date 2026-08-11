<?php

namespace App\Support\HealthCheck\PlanoAcao;

use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoReconciliacao;
use App\Support\HealthCheck\HealthCheckEngine;
use App\Support\HealthCheck\HealthCheckFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia ações abertas de uma obra contra o Health Check de uma nova
 * importação — nunca reexecuta nenhuma regra, nunca recalcula Score, nunca
 * altera cronograma_importacao_health_checks (só lê `->resultado()`).
 *
 * NÃO integrado a ImportarCronogramaJob nesta fase (Fase 4.1, Parte I) —
 * deliberadamente isolado e testável sozinho.
 *
 * Identidade entre importações (Fase 4 diagnóstico, Etapa 5): mesmo
 * `regra_id` + sobreposição de `uid` (UidExtractor), sem threshold
 * numérico. Classificação (derivada literalmente dos exemplos do usuário):
 * - nenhuma sobreposição com nenhum finding daquela regra -> Resolvido;
 * - sobreposição, e NADA do conjunto anterior sumiu -> Persistente (se o
 *   conjunto atual é idêntico) ou Agravado (se o conjunto atual é maior,
 *   só cresceu);
 * - sobreposição, mas algo do conjunto anterior sumiu (mesmo que uids
 *   novos tenham entrado) -> Alterado, NUNCA resolvido automaticamente
 *   (exige revisão humana).
 *
 * `impacto_anterior`/`impacto_atual` ficam sempre `null` nesta fase — ver
 * CLAUDE.md ("Health Check — Fase 4.1") pra a limitação exata que impede
 * calculá-los com confiança sem alterar ScoreCalculator/AcaoRecomendada.
 */
/**
 * Fase 4.2: `final` removido (era `final class` na Fase 4.1) — decisão
 * adicional necessária pra permitir mock via Mockery em teste de rollback
 * (Mockery não consegue gerar um double de uma classe final sem interface).
 * Nenhum comportamento muda; a classe continua sem ser estendida em
 * lugar nenhum do código de produção.
 */
class PlanoAcaoReconciliador
{
    /**
     * Default via "new in initializers" (PHP 8.1+, já exigido pelo
     * composer.json) — preserva `new PlanoAcaoReconciliador()` sem
     * argumentos (uso direto em `tests/Unit/PlanoAcaoReconciliadorTest.php`)
     * ao mesmo tempo que permite resolução normal via container
     * (`app(PlanoAcaoReconciliador::class)`), já que `HealthCheckEngine`
     * também só tem parâmetros opcionais.
     */
    public function __construct(
        private readonly HealthCheckEngine $engine = new HealthCheckEngine(),
    ) {}

    /**
     * @param Collection<int, PlanoAcao> $acoesAbertas ações da MESMA obra da $novaImportacao — não valida isso aqui (responsabilidade do chamador)
     * @param ?TipoCronogramaImportacao $tipo Quando Baseline, ações cuja regra é de natureza Execucao permanecem
     *        intocadas (não reconciliadas) — o Health Check de uma Baseline nunca avalia essas regras (Ciclo 2), então
     *        a ausência do finding correspondente NÃO pode ser interpretada como "resolvido". `null` preserva o
     *        comportamento legado (reconcilia todas as ações abertas, igual a Avanco/Ambos).
     * @return Collection<int, PlanoAcaoReconciliacao> um evento por ação processada (ações não-Aberta ou de Execucao numa Baseline são ignoradas, nunca produzem evento)
     */
    public function reconciliar(
        Collection $acoesAbertas,
        CronogramaImportacao $novaImportacao,
        CronogramaImportacaoHealthCheck $healthCheck,
        ?TipoCronogramaImportacao $tipo = null,
    ): Collection {
        $findingsPorRegra = $this->agruparFindingsPorRegra($healthCheck->resultado()->findings);

        return DB::transaction(function () use ($acoesAbertas, $novaImportacao, $findingsPorRegra, $tipo) {
            $eventos = new Collection();

            foreach ($acoesAbertas as $acao) {
                // Defesa em profundidade: mesmo que o chamador passe uma ação
                // já Resolvida/Cancelada por engano, ela nunca é reconciliada
                // de novo (nunca "reaberta" automaticamente).
                if (! $acao->status->estaAberta()) {
                    continue;
                }

                // Baseline nunca avalia regras de Execução (Ciclo 2) — a
                // ausência do finding aqui não é evidência de resolução.
                // A ação permanece exatamente como estava: sem evento, sem
                // mudança de status, sem tocar uids_referencia.
                if ($tipo === TipoCronogramaImportacao::Baseline
                    && $this->engine->naturezaDaRegra($acao->regra_id) === HealthCheckNaturezaRegra::Execucao) {
                    continue;
                }

                $eventos->push($this->reconciliarUma($acao, $novaImportacao, $findingsPorRegra));
            }

            return $eventos;
        });
    }

    private function reconciliarUma(PlanoAcao $acao, CronogramaImportacao $novaImportacao, array $findingsPorRegra): PlanoAcaoReconciliacao
    {
        $uidsAnteriores = $acao->uids_referencia;
        $findingsDaRegra = $findingsPorRegra[$acao->regra_id] ?? [];

        $uidsAtuais = $this->uidsComSobreposicao($findingsDaRegra, $uidsAnteriores);
        $resultado = $this->classificar($uidsAnteriores, $uidsAtuais);

        $statusAnterior = $acao->status;
        $statusNovo = $resultado === ResultadoReconciliacaoPlanoAcao::Resolvido
            ? StatusPlanoAcao::Resolvida
            : StatusPlanoAcao::Aberta;

        $reconciliacao = PlanoAcaoReconciliacao::create([
            'plano_acao_id' => $acao->id,
            'cronograma_importacao_id' => $novaImportacao->id,
            'resultado' => $resultado,
            'status_anterior' => $statusAnterior,
            'status_novo' => $statusNovo,
            'uids_anteriores' => $uidsAnteriores,
            'uids_atuais' => $uidsAtuais,
            'quantidade_anterior' => count($uidsAnteriores),
            'quantidade_atual' => count($uidsAtuais),
            'impacto_anterior' => null,
            'impacto_atual' => null,
        ]);

        // Resolvido: nunca apaga a ação, nunca limpa uids_referencia — mantém
        // a última referência conhecida (o conjunto que existia antes de
        // desaparecer), pra auditoria futura continuar fazendo sentido.
        $acao->update([
            'status' => $statusNovo,
            'uids_referencia' => $resultado === ResultadoReconciliacaoPlanoAcao::Resolvido ? $uidsAnteriores : $uidsAtuais,
            'resolvida_em' => $resultado === ResultadoReconciliacaoPlanoAcao::Resolvido ? now() : $acao->resolvida_em,
        ]);

        return $reconciliacao;
    }

    /**
     * União dos uids de TODOS os findings daquela regra que têm QUALQUER
     * sobreposição com o conjunto anterior — nunca olha pra findings da
     * mesma regra que não compartilham nenhum uid com esta ação específica
     * (esses são "problema novo", tratados por uma ação separada, nunca
     * associados a esta).
     *
     * @param HealthCheckFinding[] $findingsDaRegra
     * @param string[] $uidsAnteriores
     * @return string[]
     */
    private function uidsComSobreposicao(array $findingsDaRegra, array $uidsAnteriores): array
    {
        $uniao = [];

        foreach ($findingsDaRegra as $finding) {
            $uidsDoFinding = UidExtractor::extrair($finding->atividades);

            if (SobreposicaoUid::temSobreposicao($uidsDoFinding, $uidsAnteriores)) {
                $uniao = array_merge($uniao, $uidsDoFinding);
            }
        }

        return array_values(array_unique($uniao));
    }

    /**
     * @param string[] $uidsAnteriores
     * @param string[] $uidsAtuais
     */
    private function classificar(array $uidsAnteriores, array $uidsAtuais): ResultadoReconciliacaoPlanoAcao
    {
        if (empty($uidsAtuais)) {
            return ResultadoReconciliacaoPlanoAcao::Resolvido;
        }

        $saidas = array_diff($uidsAnteriores, $uidsAtuais);

        if (! empty($saidas)) {
            return ResultadoReconciliacaoPlanoAcao::Alterado;
        }

        // Aqui: nada do conjunto anterior sumiu (uidsAnteriores ⊆ uidsAtuais).
        $entraram = array_diff($uidsAtuais, $uidsAnteriores);

        return empty($entraram) ? ResultadoReconciliacaoPlanoAcao::Persistente : ResultadoReconciliacaoPlanoAcao::Agravado;
    }

    /**
     * @param HealthCheckFinding[] $findings
     * @return array<string, HealthCheckFinding[]> chave = regra_id
     */
    private function agruparFindingsPorRegra(array $findings): array
    {
        $agrupado = [];

        foreach ($findings as $finding) {
            $agrupado[$finding->regraId][] = $finding;
        }

        return $agrupado;
    }
}
