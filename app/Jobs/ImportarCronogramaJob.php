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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportarCronogramaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

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
    ) {}

    public function handle(ImportadorCronograma $importer, ScoreCalculator $scoreCalculator, PlanoAcaoReconciliador $planoAcaoReconciliador): void
    {
        try {
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
            });

            $this->marcarStatus('concluido');
        } catch (\Throwable $e) {
            $this->marcarStatus('erro', $e->getMessage());
            throw $e;
        }
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
}
