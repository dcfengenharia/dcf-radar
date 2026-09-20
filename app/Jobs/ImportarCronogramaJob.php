<?php

namespace App\Jobs;

use App\Enums\StatusPlanoAcao;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\CronogramaImportacaoHealthCheck;
use App\Models\PlanoAcao;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckResultado;
use App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador;
use App\Support\HealthCheck\Score\ScoreCalculator;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditoria Pré-Produção A2, Seção 6 (Double Execution) — `ShouldBeUnique`
 * (mesmo mecanismo já usado por `SincronizarSituacaoObraJob`, Ciclo 21.4)
 * protege contra a MESMA entrega de fila sendo processada por 2 workers ao
 * mesmo tempo — cenário real dado o achado da Seção 5 (retry_after do
 * Redis, 90s, era MENOR que este Job's $timeout de 600s: o Redis podia
 * reentregar o job pra outro worker enquanto o primeiro ainda processava
 * legitimamente). Nunca protege contra "o mesmo comando de negócio
 * acontecendo duas vezes de propósito" (reimportar o MESMO arquivo é uma
 * ação legítima e repetível, cada uma vira sua própria
 * CronogramaImportacao histórica) — só contra a fila entregar A MESMA
 * mensagem duas vezes. `uniqueId()` é `$chaveUnicidade`, computada UMA VEZ
 * no construtor (nunca em uniqueId() em si, que precisa ser estável
 * através do ciclo serializar→enfileirar→desserializar→executar) — usa o
 * trackingId real (ULID novo por clique, gerado nos 2 pontos de dispatch
 * em produção) quando presente, ou um ULID de fallback pra qualquer
 * dispatch de teste/futuro sem trackingId, nunca colidindo entre
 * dispatches distintos.
 */
class ImportarCronogramaJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    private readonly string $chaveUnicidade;

    public function __construct(
        private readonly Work $obra,
        private readonly string $caminhoArquivo,
        private readonly ?string $userId,
        private readonly TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline,
        private readonly ?string $trackingId = null,
        /**
         * Resultado do Health Check já calculado e exibido ao usuário na
         * prévia (App\Support\HealthCheck\HealthCheckResultado::toArray()) —
         * nunca recalculado aqui, só persistido. Ver CLAUDE.md, seção
         * "Importação Segura — Health Check (Fase 1)".
         */
        private readonly ?array $healthCheckSerializado = null,
    ) {
        $this->chaveUnicidade = $trackingId ?? (string) Str::ulid();
    }

    public function uniqueId(): string
    {
        return $this->chaveUnicidade;
    }

    public function handle(ImportadorCronograma $importer, ScoreCalculator $scoreCalculator, PlanoAcaoReconciliador $planoAcaoReconciliador): void
    {
        // Auditoria Pré-Produção A2, Seção 7 (Importação Presa) — achado
        // real: a versão anterior tinha um try/catch aqui que chamava
        // marcarStatus('erro', ...) em QUALQUER exceção, inclusive numa
        // tentativa INTERMEDIÁRIA (este Job não define $tries, então usa
        // o --tries=3 do worker) — um retry automático que teria sucesso
        // na 2ª/3ª tentativa deixava o usuário vendo "Erro" na tela por
        // engano, mesmo a importação tendo terminado bem pouco depois
        // (Seção 7: "retry intermediário → não marcar falha
        // prematuramente"). Removido — a exceção agora propaga livre pro
        // mecanismo de retry do Laravel; só failed() (chamado só depois
        // de esgotar TODAS as tentativas, ou por timeout/kill definitivo)
        // marca o estado de erro. Sucesso continua marcando 'concluido'
        // no fim do bloco, sem mudança.
        TenantContext::actingAs($this->obra->tenant, function () use ($importer, $scoreCalculator, $planoAcaoReconciliador) {
                // Envolve analisar()+aplicar() (que já abre sua própria
                // transação interna) numa transação externa só pra poder
                // persistir o Health Check como parte do MESMO commit —
                // Laravel/PDO tratam isso como savepoint aninhado, então uma
                // falha aqui desfaz a importação inteira também. Nenhuma
                // mudança em MsProjectImporter::aplicar() foi necessária.
                DB::transaction(function () use ($importer, $scoreCalculator, $planoAcaoReconciliador) {
                    $plano = $importer->analisar($this->caminhoArquivo, $this->obra, $this->tipo);
                    $importacao = $importer->aplicar(
                        $plano,
                        $this->obra,
                        $this->userId,
                        basename($this->caminhoArquivo),
                        $this->tipo,
                    );

                    if ($this->healthCheckSerializado !== null) {
                        // Score calculado aqui — não em analisar() do Livewire,
                        // não numa fila separada. Reidrata EXATAMENTE o
                        // HealthCheckResultado já mostrado na prévia (mesmo
                        // JSON serializado) e reaproveita o $plano que o
                        // próprio Job já parseou pra aplicar() — nenhum parse
                        // novo, nenhuma segunda análise. ScoreCalculator
                        // continua sendo a ÚNICA fonte de verdade do cálculo.
                        $healthCheckResultado = HealthCheckResultado::fromArray($this->healthCheckSerializado);
                        $scoreResultado = $scoreCalculator->calcular($healthCheckResultado, $plano);

                        $healthCheck = CronogramaImportacaoHealthCheck::create(
                            ['cronograma_importacao_id' => $importacao->id]
                            + CronogramaImportacaoHealthCheck::camposParaPersistir($this->healthCheckSerializado)
                            + CronogramaImportacaoHealthCheck::camposDeScoreParaPersistir($scoreResultado)
                        );

                        // Fase 4.2 — reconciliação do Plano de Ação, dentro da
                        // MESMA transação (uma falha aqui desfaz a importação
                        // inteira, mesmo risco já aceito pra Health Check/Score).
                        // Roda pra Baseline E Avanço (decisão do usuário: o
                        // Plano de Ação acompanha o PROBLEMA TÉCNICO, não o
                        // tipo de importação — diferente da Evolução do Score,
                        // que continua exigindo mesmo tipo). $this->tipo é
                        // repassado (Ciclo 3, separação Planejamento/Execução):
                        // numa Baseline, ações originadas de regra Execucao
                        // ficam intocadas — o Health Check da Baseline nunca
                        // avalia essas regras (Ciclo 2), então a ausência do
                        // finding não pode significar "resolvido". Nunca
                        // recalcula regra nenhuma, nunca toca $healthCheck
                        // depois de criado — só lê via $healthCheck->resultado().
                        $acoesAbertas = PlanoAcao::where('obra_id', $this->obra->id)
                            ->where('status', StatusPlanoAcao::Aberta->value)
                            ->get();

                        if ($acoesAbertas->isNotEmpty()) {
                            $planoAcaoReconciliador->reconciliar($acoesAbertas, $importacao, $healthCheck, $this->tipo);
                        }
                    }
                });
            }
        );

        $this->marcarStatus('concluido');

        // Auditoria Pré-Produção A2.1, Seção 17 — achado real (não
        // teórico): storage/app/imports/temp acumulava o XML de TODA
        // importação bem-sucedida indefinidamente (só cancelar() na tela,
        // ANTES do dispatch, apagava o arquivo — handle()/failed() nunca
        // limpavam nada). Confirmado em produção/dev: 8.832 arquivos /
        // 3,5 GB acumulados, a maioria com mais de 24h. Removido só AQUI
        // (sucesso definitivo) — nunca durante uma tentativa intermediária
        // que ainda vai relançar a exceção pro Laravel tentar de novo (o
        // próximo retry precisa reler o MESMO arquivo).
        $this->removerArquivoTemporario();
    }

    /**
     * Auditoria Pré-Produção A2, Seção 7 (Importação Presa) — chamado pelo
     * Laravel só depois de esgotar TODAS as tentativas (--tries=3 do
     * worker) ou por timeout/kill definitivo — nunca numa tentativa
     * intermediária. `report($exception)` garante que o erro real fica
     * registrado (canal de log local já existente, nunca silencioso —
     * Sentry fica pra fase própria) sem expor a mensagem técnica crua ao
     * usuário (Seção 7: "não expor stack trace ao usuário") — o cache
     * grava só um texto genérico e seguro; verificarStatusImportacao()
     * exibe exatamente isso, nunca $exception->getMessage().
     */
    public function failed(\Throwable $exception): void
    {
        report($exception);

        $this->marcarStatus(
            'erro',
            'Não foi possível concluir a importação. Tente novamente ou contate o suporte se o problema persistir.'
        );

        // Auditoria Pré-Produção A2.1, Seção 17 — mesma limpeza do
        // caminho de sucesso, aqui no caminho de FALHA DEFINITIVA (todas
        // as tentativas já esgotadas, ou timeout/kill — failed() nunca é
        // chamado numa tentativa intermediária, ver docblock da classe).
        // Nenhum retry futuro vai precisar reler este arquivo.
        $this->removerArquivoTemporario();
    }

    private function marcarStatus(string $status, ?string $erro = null): void
    {
        if ($this->trackingId === null) {
            return;
        }

        Cache::put(
            "cronograma-importacao-status:{$this->trackingId}",
            ['status' => $status, 'erro' => $erro],
            now()->addMinutes(30)
        );
    }

    /**
     * Auditoria Pré-Produção A2.1, Seção 17 — `$this->caminhoArquivo` é
     * sempre um path ABSOLUTO (`storage_path('app/'.$path)`, gravado
     * pelos 2 componentes Livewire que despacham este Job) — nunca um
     * path relativo de disco Storage. `@unlink` + `file_exists()` é o
     * MESMO idioma já usado por `cancelar()` nos 2 componentes (nunca
     * lança se o arquivo já não existir, ex.: dois workers processando a
     * mesma unicidade por engano — cenário já protegido por
     * `ShouldBeUnique`, mas defensivo mesmo assim).
     */
    private function removerArquivoTemporario(): void
    {
        if ($this->caminhoArquivo !== '' && file_exists($this->caminhoArquivo)) {
            @unlink($this->caminhoArquivo);
        }
    }
}
