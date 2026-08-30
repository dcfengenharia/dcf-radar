<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — EmContagem→EmAnalise. Não exige que TODOS os
 * itens já tenham sido contados (sem regra rígida sem necessidade
 * confirmada) — a UI pode alertar sobre itens pendentes, mas o avanço
 * não é bloqueado.
 */
class MoverInventarioParaAnalise
{
    public function execute(InventarioEstoque $inventario, User $usuario): InventarioEstoque
    {
        return DB::transaction(function () use ($inventario) {
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if ($inventarioTravado->status !== StatusInventarioEstoque::EmContagem) {
                throw new InventarioEstoqueInvalidoException('Só um Inventário Em Contagem pode ser movido para Análise.');
            }

            $inventarioTravado->update(['status' => StatusInventarioEstoque::EmAnalise]);

            return $inventarioTravado->fresh();
        });
    }
}
