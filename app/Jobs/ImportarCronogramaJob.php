<?php

namespace App\Jobs;

use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

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
    ) {}

    public function handle(ImportadorCronograma $importer): void
    {
        try {
            TenantContext::actingAs($this->obra->tenant, function () use ($importer) {
                $plano = $importer->analisar($this->caminhoArquivo, $this->obra, $this->tipo);
                $importer->aplicar(
                    $plano,
                    $this->obra,
                    $this->userId,
                    basename($this->caminhoArquivo),
                    $this->tipo,
                );
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
