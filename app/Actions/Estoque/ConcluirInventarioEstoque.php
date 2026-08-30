<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — EmAnalise→Concluido. Não exige que toda
 * divergência tenha um Ajuste aprovado — deixar uma divergência
 * documentada e sem correção é um desfecho válido (a diferença não
 * significa automaticamente uma causa que precise ser corrigida, Seção
 * 11 do pedido).
 */
class ConcluirInventarioEstoque
{
    public function execute(InventarioEstoque $inventario, User $usuario): InventarioEstoque
    {
        return DB::transaction(function () use ($inventario, $usuario) {
            $inventarioTravado = InventarioEstoque::whereKey($inventario->id)->lockForUpdate()->firstOrFail();

            if ($inventarioTravado->status !== StatusInventarioEstoque::EmAnalise) {
                throw new InventarioEstoqueInvalidoException('Só um Inventário Em Análise pode ser concluído.');
            }

            $inventarioTravado->update([
                'status' => StatusInventarioEstoque::Concluido,
                'concluido_em' => now(),
                'concluido_por' => $usuario->id,
            ]);

            return $inventarioTravado->fresh();
        });
    }
}
