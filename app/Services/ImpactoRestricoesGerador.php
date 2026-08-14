<?php

namespace App\Services;

use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\ReportDesvioRestricao;
use App\Models\Restricao;
use Illuminate\Support\Carbon;

/**
 * Fase 5, Etapa C2 — Impacto de Restrições. Gera, para CADA linha de
 * App\Models\ReportDesvio já existente do Report, um snapshot congelado
 * de quais Restricao ABERTAS estão no mesmo escopo de pacote — via
 * Atividade->pacote_trabalho_id + PacoteTrabalho::descendantIds(), o
 * MESMO algoritmo de escopo já aprovado em causasDoDesvio() (Etapa B),
 * nunca duplicado/alterado aqui, só reaplicado a outra fonte de dado.
 *
 * NÃO é chamado por App\Services\ReportGerador (decisão explícita, Opção
 * B aprovada — ReportGerador permanece intocado nesta etapa). É chamado
 * pelos 2 únicos pontos que invocam ReportGerador::gerarRascunho():
 * ⚡relatorio-novo.blade.php e App\Console\Commands\
 * GerarReportsAutomaticoCommand. Ambos os chamadores envolvem esta
 * chamada em try/catch — uma falha aqui NUNCA derruba a criação do
 * Report em si (degradação graciosa, mesmo padrão já usado quando um
 * Report não tem Health Check/Score disponível).
 *
 * Congela tudo que a tela precisa exibir SEM depender do estado vivo de
 * Restricao depois (que é mutável: reabertura apaga resolvida_em, status
 * muda livremente) — nunca FK viva pra exibição histórica, mesma
 * filosofia já usada em ReportIndicadorSemana.
 *
 * NUNCA usa linguagem causal — o snapshot só registra "restrições
 * abertas associadas ao escopo desta linha", nunca "restrição causou o
 * desvio" (a existência de uma restrição não prova causalidade).
 */
class ImpactoRestricoesGerador
{
    /**
     * Risco Alto = probabilidade × impacto >= 50 — mesmo limiar já usado
     * em ⚡relatorios-restricoes.blade.php::riscoDistribuicao(), nenhum
     * limiar novo inventado nesta etapa.
     */
    private const RISCO_ALTO_MINIMO = 50;

    public function gerar(Report $report): void
    {
        // 'curvas.desvios.pacoteTrabalho' — o pacote da LINHA de desvio
        // (usado pra linhas filhas) é distinto de 'curvas.pacoteTrabalho'
        // (o pacote da CURVA, usado pro nível pai) — os 2 call sites de
        // gerarRascunho() nunca carregam nenhum dos dois, então sem este
        // loadMissing() explícito aqui a leitura de $desvio->pacoteTrabalho
        // dispararia lazy loading (proibido fora de produção).
        $report->loadMissing(['curvas.desvios.pacoteTrabalho', 'curvas.pacoteTrabalho']);

        foreach ($report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $this->gerarParaDesvio($report, $curva, $desvio);
            }
        }
    }

    private function gerarParaDesvio(Report $report, ReportCurva $curva, ReportDesvio $desvio): void
    {
        // Escopo de pacote — EXATAMENTE o mesmo algoritmo de
        // causasDoDesvio() (Etapa B): nível pai usa o pacote da própria
        // curva (null = obra inteira, todos os pacotes); linha filha usa
        // o pacote da própria linha de desvio. Sempre pacote +
        // descendantIds(), pra alcançar atividades em níveis netos.
        $pacote = $desvio->eh_nivel_pai ? $curva->pacoteTrabalho : $desvio->pacoteTrabalho;

        $pacoteIds = $pacote
            ? [$pacote->id, ...$pacote->descendantIds()]
            : PacoteTrabalho::where('obra_id', $report->obra_id)->pluck('id')->all();

        $atividadeIds = Atividade::where('obra_id', $report->obra_id)
            ->whereIn('pacote_trabalho_id', $pacoteIds)
            ->pluck('id');

        // Vínculo exclusivo Restricao -> Atividade -> PacoteTrabalho ->
        // ReportDesvio — nenhuma aproximação textual. Só restrições
        // ABERTAS (não resolvidas) contam pra este indicador.
        $restricoes = Restricao::whereIn('atividade_id', $atividadeIds)
            ->where('status', '!=', StatusRestricao::Resolvida->value)
            ->with(['categoria', 'responsavel'])
            ->get();

        $hoje = Carbon::today();

        $detalhes = $restricoes->map(function (Restricao $r) use ($hoje) {
            $vencida = $r->prazo_limite !== null && $r->prazo_limite->lt($hoje);

            // Se probabilidade OU impacto forem nulos, o risco nunca é
            // classificado como baixo — vira "nao_classificado", nunca
            // um valor inventado.
            $risco = ($r->probabilidade !== null && $r->impacto !== null)
                ? $r->probabilidade * $r->impacto
                : null;

            $classificacaoRisco = match (true) {
                $risco === null => 'nao_classificado',
                $risco >= self::RISCO_ALTO_MINIMO => 'alto',
                default => 'normal',
            };

            return [
                'descricao' => $r->descricao,
                'status' => $r->status->label(),
                'categoria_nome' => $r->categoria?->nome,
                // responsavel_externo como fallback quando não há responsável interno.
                'responsavel_nome' => $r->responsavel
                    ? trim("{$r->responsavel->first_name} {$r->responsavel->last_name}")
                    : ($r->responsavel_externo ?: null),
                'prazo_limite' => $r->prazo_limite?->toDateString(),
                'bloqueante' => $r->bloqueante,
                'probabilidade' => $r->probabilidade,
                'impacto' => $r->impacto,
                'vencida' => $vencida,
                'classificacao_risco' => $classificacaoRisco,
            ];
        })->values();

        // Ordenação: vencidas primeiro, depois maior risco, depois prazo
        // mais próximo. Comparação por array (PHP compara elemento a
        // elemento) — nunca closures encadeadas ambíguas.
        $detalhesOrdenados = $detalhes
            ->sortBy(fn (array $item) => [
                $item['vencida'] ? 0 : 1,
                $item['classificacao_risco'] === 'alto' ? 0 : 1,
                $item['prazo_limite'] ?? '9999-12-31',
            ])
            ->values()
            ->all();

        ReportDesvioRestricao::create([
            'report_desvio_id' => $desvio->id,
            'total_abertas' => $detalhes->count(),
            'total_vencidas' => $detalhes->where('vencida', true)->count(),
            'total_criticas' => $detalhes->where('classificacao_risco', 'alto')->count(),
            'detalhes' => $detalhesOrdenados,
        ]);
    }
}
