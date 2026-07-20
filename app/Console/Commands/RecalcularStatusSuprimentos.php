<?php

namespace App\Console\Commands;

use App\Enums\StatusItemSuprimento;
use App\Models\ItemSuprimento;
use App\Models\Tenant;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Roda diariamente: recalcula Tendência/status/restrição de todo item de
 * suprimento ainda não concluído — pega a deriva pura do calendário (uma
 * etapa que venceu só porque o tempo passou, sem nenhuma reimportação de
 * cronograma no meio). Execução de sistema, sem usuário (userId = null).
 */
class RecalcularStatusSuprimentos extends Command
{
    protected $signature = 'suprimentos:recalcular-status';

    protected $description = 'Recalcula Tendência/status/restrição de todos os itens de suprimento não concluídos, de todos os tenants.';

    public function handle(): int
    {
        Tenant::query()->each(function (Tenant $tenant) {
            TenantContext::actingAs($tenant, function () use ($tenant) {
                $itens = ItemSuprimento::whereHas('atividades')
                    ->where('status', '!=', StatusItemSuprimento::Concluido->value)
                    ->get();

                foreach ($itens as $item) {
                    SincronizarRestricaoSuprimento::sincronizarItem($item, null);
                }

                if ($itens->isNotEmpty()) {
                    $this->info("Tenant {$tenant->id}: {$itens->count()} item(ns) recalculado(s).");
                }
            });
        });

        return self::SUCCESS;
    }
}
