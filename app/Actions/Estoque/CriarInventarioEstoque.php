<?php

namespace App\Actions\Estoque;

use App\Enums\StatusInventarioEstoque;
use App\Exceptions\InventarioEstoqueInvalidoException;
use App\Models\InventarioEstoque;
use App\Models\LocalEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.7 — cria o Inventário em Rascunho (sem snapshot
 * ainda — o snapshot só nasce em `IniciarInventarioEstoque`).
 *
 * **Decisão do usuário (STOP-and-ask)**: Local tipo Terceiro é bloqueado
 * — Inventário só em Locais PRÓPRIOS nesta etapa (mesma decisão já
 * tomada pra Transferência genérica, 20.6).
 */
class CriarInventarioEstoque
{
    public function execute(
        LocalEstoque $local,
        User $usuario,
        ?string $titulo = null,
        bool $contagemCega = false,
        ?string $observacao = null,
    ): InventarioEstoque {
        return DB::transaction(function () use ($local, $usuario, $titulo, $contagemCega, $observacao) {
            if (! $local->ativo) {
                throw new InventarioEstoqueInvalidoException('Este Local de Estoque está inativo — não é possível abrir um Inventário nele.');
            }

            if ($local->ehTerceiro()) {
                throw new InventarioEstoqueInvalidoException(
                    'Inventário não é permitido em Locais tipo Terceiro nesta etapa — a custódia em fornecedor é tratada pelo fluxo de Industrialização.'
                );
            }

            return InventarioEstoque::create([
                'obra_id' => $local->obra_id,
                'local_estoque_id' => $local->id,
                'status' => StatusInventarioEstoque::Rascunho,
                'titulo' => $titulo,
                'contagem_cega' => $contagemCega,
                'observacao' => $observacao,
            ]);
        });
    }
}
