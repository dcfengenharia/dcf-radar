<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CobrancaScheduler;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Roda diariamente: pra cada tenant, gera a próxima cobrança Pix/Boleto
 * quando a assinatura vigente está perto de vencer
 * (App\Services\CobrancaScheduler). Execução de sistema, sem usuário.
 */
class ProcessarAssinaturas extends Command
{
    protected $signature = 'assinaturas:processar';

    protected $description = 'Gera a próxima cobrança Pix/Boleto pra assinaturas perto de vencer, de todos os tenants.';

    public function handle(CobrancaScheduler $scheduler): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($scheduler) {
            TenantContext::actingAs($tenant, function () use ($tenant, $scheduler) {
                $assinatura = $tenant->assinaturaAtual();
                if (! $assinatura) {
                    return;
                }

                $fatura = $scheduler->gerarProximaCobrancaSeNecessario($assinatura);
                if ($fatura) {
                    $this->info("Tenant {$tenant->id}: nova cobrança {$fatura->metodo_pagamento} gerada (vencimento {$fatura->vencimento->format('d/m/Y')}).");
                }

                $inadimplente = $scheduler->verificarInadimplenciaSeNecessario($assinatura);
                if ($inadimplente) {
                    $this->warn("Tenant {$tenant->id}: marcado como Inadimplente.");
                }
            });
        });

        return self::SUCCESS;
    }
}
