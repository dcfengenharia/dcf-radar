<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\ContagemInventario;
use App\Models\InventarioItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — registra UMA contagem/recontagem (Seção 10:
 * append-only, nunca sobrescreve a anterior). Permitida tanto durante
 * EmContagem (contagem inicial) quanto EmAnalise (recontagem — mesma
 * ação física, mesma permissão `estoque.inventario|criar`).
 */
class RegistrarContagemInventario
{
    public function execute(
        InventarioItem $item,
        float $quantidadeContada,
        \DateTimeInterface $contadoEm,
        User $contador,
        ?string $observacao = null,
    ): ContagemInventario {
        return DB::transaction(function () use ($item, $quantidadeContada, $contadoEm, $contador, $observacao) {
            if ($quantidadeContada < 0) {
                throw new InventarioEstoqueInvalidoException('A quantidade contada não pode ser negativa.');
            }

            $inventario = $item->inventario()->firstOrFail();
            if (! in_array($inventario->status, [StatusInventarioEstoque::EmContagem, StatusInventarioEstoque::EmAnalise], true)) {
                throw new InventarioEstoqueInvalidoException('Este Inventário não está em contagem/análise — não é possível registrar contagem.');
            }

            $dataContagem = Carbon::parse($contadoEm)->startOfDay();
            if ($dataContagem->gt(Carbon::today())) {
                throw new InventarioEstoqueInvalidoException('A data da contagem não pode estar no futuro.');
            }

            return ContagemInventario::create([
                'inventario_item_id' => $item->id,
                'quantidade_contada' => $quantidadeContada,
                'contado_em' => $dataContagem,
                'contador_id' => $contador->id,
                'observacao' => $observacao,
            ]);
        });
    }
}
