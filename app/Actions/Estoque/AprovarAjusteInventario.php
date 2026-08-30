<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\AjusteInventarioInvalidoException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\InventarioAjuste;
use App\Models\InventarioItem;
use App\Models\LocalEstoque;
use App\Models\MovimentacaoEstoque;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — Ajuste é um EVENTO DE LEDGER (princípio central
 * do pedido): nunca UPDATE de saldo/movimentação/snapshot — sempre uma
 * MovimentacaoEstoque NOVA (Entrada se a divergência foi positiva, Saida
 * se negativa), reaproveitando os tipos existentes (mesmo padrão de
 * Transferência/Remessa de Industrialização, decisão do usuário via
 * STOP-and-ask) + um `InventarioAjuste` correlato.
 *
 * **Concorrência (Seção 22, decisão do usuário — Seção 16)**: a
 * divergência exibida é sempre "contado vs. snapshot" (histórico,
 * imutável) — mas a APROVAÇÃO sempre revalida o saldo FRESCO sob lock no
 * momento de aprovar, nunca o snapshot antigo. Um ajuste NEGATIVO que
 * produziria saldo fisicamente impossível (porque uma Saída/Transferência
 * aconteceu no meio do caminho) é recusado aqui — nunca aprovado
 * cegamente contra o snapshot.
 *
 * **Aprovação em UMA única ação (decisão do usuário)**: sem estágio
 * "Proposto" persistido separado — análise+justificativa+aprovação
 * acontecem nesta única chamada/transação, mesmo padrão de
 * `App\Models\PlanoAcao::transformarEmRestricoes()` (Ciclo 11): a dupla
 * autorização (estoque.inventario|editar + estoque.movimentacao|editar)
 * é checada no CHAMADOR (Livewire), nunca dentro desta Action.
 *
 * **Serial inesperado (Seção 8)**: um item sem UnidadeEstoque resolvida
 * (`InventarioItem::ehSerialInesperado()`) nunca gera Ajuste automático
 * por aqui — resolver de verdade é sempre manual, fora deste fluxo.
 */
class AprovarAjusteInventario
{
    public function execute(InventarioItem $item, string $justificativa, User $usuario): InventarioAjuste
    {
        return DB::transaction(function () use ($item, $justificativa, $usuario) {
            $justificativa = trim($justificativa);
            if (mb_strlen($justificativa) < 5) {
                throw new AjusteInventarioInvalidoException('Informe uma justificativa com pelo menos 5 caracteres antes de aprovar o Ajuste.');
            }

            $itemTravado = InventarioItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $inventario = $itemTravado->inventario()->lockForUpdate()->firstOrFail();

            if ($inventario->status !== StatusInventarioEstoque::EmAnalise) {
                throw new AjusteInventarioInvalidoException('Só é possível aprovar Ajuste com o Inventário Em Análise.');
            }

            if ($itemTravado->ehSerialInesperado()) {
                throw new AjusteInventarioInvalidoException(
                    'Um item de serial inesperado não pode ser ajustado automaticamente — resolva manualmente via Entrada/Transferência antes.'
                );
            }

            if (InventarioAjuste::where('inventario_item_id', $itemTravado->id)->exists()) {
                throw new AjusteInventarioInvalidoException('Este item já possui um Ajuste aprovado.');
            }

            $ultimaContagem = $itemTravado->ultimaContagem();
            if (! $ultimaContagem) {
                throw new AjusteInventarioInvalidoException('Este item ainda não foi contado — não é possível aprovar um Ajuste sem contagem.');
            }

            $diferenca = round((float) $ultimaContagem->quantidade_contada - (float) $itemTravado->quantidade_sistema_snapshot, 3);
            if (abs($diferenca) <= 0.0005) {
                throw new AjusteInventarioInvalidoException('Não há divergência a ajustar neste item.');
            }

            $local = $inventario->localEstoque()->firstOrFail();

            // Recurso físico travado ANTES de revalidar o saldo fresco —
            // mesmo total order já usado em toda a Etapa 20.
            if ($itemTravado->unidade_estoque_id) {
                $unidadeTravada = UnidadeEstoque::whereKey($itemTravado->unidade_estoque_id)->lockForUpdate()->firstOrFail();
                $saldoFresco = SaldoEstoque::porUnidadeLocal($unidadeTravada, $local);
            } else {
                LocalEstoque::whereKey($local->id)->lockForUpdate()->firstOrFail();
                $unidadeTravada = null;
                $saldoFresco = SaldoEstoque::porMaterialLocal($itemTravado->material()->firstOrFail(), $local);
            }

            $tipo = $diferenca > 0 ? TipoMovimentacaoEstoque::Entrada : TipoMovimentacaoEstoque::Saida;
            $quantidadeAjuste = abs($diferenca);

            if ($tipo === TipoMovimentacaoEstoque::Saida && $quantidadeAjuste > $saldoFresco + 0.0005) {
                throw new SaldoFisicoInsuficienteException(
                    "O saldo físico mudou desde a contagem — hoje há apenas {$saldoFresco} disponível, insuficiente para este ajuste negativo de {$quantidadeAjuste}.",
                    $saldoFresco,
                    $quantidadeAjuste
                );
            }

            $movimentacao = MovimentacaoEstoque::create([
                'obra_id' => $local->obra_id,
                'tipo' => $tipo,
                'material_id' => $itemTravado->material_id,
                'local_estoque_id' => $local->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'quantidade' => $quantidadeAjuste,
                'ocorrido_em' => now(),
                'registrado_por' => $usuario->id,
                'observacao' => "Ajuste de Inventário #{$inventario->numero}",
            ]);

            return InventarioAjuste::create([
                'inventario_item_id' => $itemTravado->id,
                'movimentacao_estoque_id' => $movimentacao->id,
                'quantidade' => $quantidadeAjuste,
                'justificativa' => $justificativa,
                'aprovado_por' => $usuario->id,
            ]);
        });
    }
}
