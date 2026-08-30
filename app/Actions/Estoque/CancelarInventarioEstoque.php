<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — Cancelamento (Seção 20 do pedido): não apaga
 * histórico, não gera Ajuste, não altera saldo — é SEMPRE uma transição
 * de status, nunca DELETE (garantido estruturalmente por
 * `App\Observers\InventarioEstoqueObserver::deleting()`). Só permitido a
 * partir de Rascunho/EmContagem/EmAnalise — nunca a partir de Concluido.
 */
class CancelarInventarioEstoque
{
    public function execute(InventarioEstoque $inventario, string $motivo, User $usuario): InventarioEstoque
    {
        return DB::transaction(function () use ($inventario, $motivo, $usuario) {
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if (! $inventarioTravado->status->estaAberto()) {
                throw new InventarioEstoqueInvalidoException('Este Inventário já está finalizado — não pode mais ser cancelado.');
            }

            $motivo = trim($motivo);
            if ($motivo === '') {
                throw new InventarioEstoqueInvalidoException('Informe o motivo do cancelamento.');
            }

            $inventarioTravado->update([
                'status' => StatusInventarioEstoque::Cancelado,
                'cancelado_em' => now(),
                'cancelado_por' => $usuario->id,
                'motivo_cancelamento' => $motivo,
            ]);

            return $inventarioTravado->fresh();
        });
    }
}
