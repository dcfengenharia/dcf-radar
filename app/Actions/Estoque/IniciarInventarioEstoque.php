<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\SaldoEstoque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo 20, Etapa 20.7 — transição Rascunho→EmContagem, o instante em
 * que o snapshot é tirado (Seção 3 do pedido: "Ao iniciar formalmente o
 * inventário: registrar snapshot do saldo sistêmico relevante naquele
 * momento" — nunca recalculado depois).
 *
 * Numeração: mesmo padrão exato já usado 5x no projeto (Grd/
 * RequisicaoPlanejamento/RequisicaoCompra/PedidoCompra/
 * OrdemIndustrializacao) — lock na linha do Work + MAX(numero)+1,
 * Rascunho nunca consome número.
 *
 * Popula `inventario_itens` em LOTE via `SaldoEstoque::posicoesNoLocal()`
 * (1 query de leitura + 1 insert em lote — nunca 1 insert por posição em
 * loop, Seção 25 do pedido).
 */
class IniciarInventarioEstoque
{
    public function execute(InventarioEstoque $inventario, User $usuario): InventarioEstoque
    {
        return DB::transaction(function () use ($inventario, $usuario) {
            Work::whereKey($inventario->obra_id)->lockForUpdate()->firstOrFail();
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if ($inventarioTravado->status !== StatusInventarioEstoque::Rascunho) {
                throw new InventarioEstoqueInvalidoException('Este Inventário já foi iniciado — só um Inventário em Rascunho pode ser iniciado.');
            }

            $local = $inventarioTravado->localEstoque()->firstOrFail();
            $posicoes = SaldoEstoque::posicoesNoLocal($local);

            $agora = now();
            $linhas = $posicoes->map(fn (array $posicao) => [
                'id' => (string) Str::ulid(),
                'tenant_id' => $inventarioTravado->tenant_id,
                'inventario_estoque_id' => $inventarioTravado->id,
                'material_id' => $posicao['material_id'],
                'unidade_estoque_id' => $posicao['unidade_estoque_id'],
                'quantidade_sistema_snapshot' => $posicao['saldo'],
                'serial_texto_inesperado' => null,
                'created_at' => $agora,
            ])->all();

            if (! empty($linhas)) {
                foreach (array_chunk($linhas, 500) as $lote) {
                    InventarioItem::insert($lote);
                }
            }

            // InventarioEstoque nunca é soft-deletado (Seção 20: cancelamento
            // é status, nunca DELETE) — sem SoftDeletes, sem withTrashed().
            $proximoNumero = (int) InventarioEstoque::where('obra_id', $inventarioTravado->obra_id)->max('numero') + 1;

            $inventarioTravado->update([
                'status' => StatusInventarioEstoque::EmContagem,
                'numero' => $proximoNumero,
                'iniciado_em' => $agora,
                'iniciado_por' => $usuario->id,
            ]);

            return $inventarioTravado->fresh();
        });
    }
}
