<?php

namespace App\Console\Commands;

use App\Enums\StatusPedidoCompra;
use App\Enums\StatusRequisicaoCompra;
use App\Models\ItemSuprimento;
use App\Models\PedidoCompra;
use App\Models\Tenant;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use App\Support\Suprimentos\AlertaCadeiaSuprimento;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Ciclo 19, Etapa 19.7 — roda diariamente: pega a deriva pura do
 * calendário (necessidade vencida, Pedido atrasado — nenhum model precisa
 * mudar pra essas condições ficarem verdadeiras) pra toda a cadeia formal
 * de Suprimentos (RP→Pacote→RC→Pedido→Recebimento). Rede de segurança —
 * os eventos diretos (emissão de Pedido, recebimento, reimportação de
 * cronograma) já ressincronizam pontualmente via
 * `SincronizarRestricaoCadeiaSuprimento::aplicarParaAtividades()`.
 *
 * **Isolamento por Pacote/Pedido** — uma falha num não pode abortar os
 * demais (mesmo padrão de `RecalcularStatusSuprimentos`/
 * `NotificarPendenciasGedCommand`): cada iteração tem seu próprio
 * try/catch, erro vai pra `report()`, nunca interrompe o loop.
 *
 * **Execução de sistema, sem usuário** (`$userId = null` pra
 * `SincronizarRestricaoCadeiaSuprimento`) — `TenantContext::actingAs()`
 * garante o tenant correto em cada Restrição/notificação sem depender de
 * `Auth`, mesmo padrão já validado em todo comando agendado do projeto.
 */
class SincronizarCadeiaSuprimentoCommand extends Command
{
    protected $signature = 'suprimentos:sincronizar-cadeia-formal';

    protected $description = 'Sincroniza Restrições automáticas e alertas da cadeia formal de Suprimentos (RP/Pacote/RC/Pedido/Recebimento) de todos os tenants.';

    public function handle(): int
    {
        $alerta = new AlertaCadeiaSuprimento();

        Tenant::query()->each(function (Tenant $tenant) use ($alerta) {
            TenantContext::actingAs($tenant, function () use ($tenant, $alerta) {
                $pacotes = ItemSuprimento::whereHas('requisicoesCompra', function ($query) {
                    $query->whereIn('status', [
                        StatusRequisicaoCompra::Emitida->value,
                        StatusRequisicaoCompra::Concluida->value,
                    ]);
                })->with('atividades')->get();

                foreach ($pacotes as $pacote) {
                    try {
                        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote, null);
                        $alerta->dispararRiscoProjetado($pacote->fresh(['atividades']));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                $pedidosEmitidos = PedidoCompra::where('status', StatusPedidoCompra::Emitido->value)
                    ->whereNotNull('data_prevista_entrega')
                    ->get();

                foreach ($pedidosEmitidos as $pedido) {
                    try {
                        $alerta->dispararPedidoAtrasado($pedido);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }

                if ($pacotes->isNotEmpty() || $pedidosEmitidos->isNotEmpty()) {
                    $this->info("Tenant {$tenant->id}: {$pacotes->count()} pacote(s) e {$pedidosEmitidos->count()} pedido(s) verificado(s).");
                }
            });
        });

        return self::SUCCESS;
    }
}
