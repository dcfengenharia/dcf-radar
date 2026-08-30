<?php

namespace App\Actions\Suprimentos;

use App\Enums\StatusRequisicaoCompra;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\RequisicaoCompra;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.4 — cria o Rascunho de uma RC pra um Pacote. RC
 * nunca cruza Pacotes: `item_suprimento_id` é fixo desde a criação.
 *
 * **Seção 43 do pedido — disciplina de lock repetida PROATIVAMENTE**:
 * trava `ItemSuprimento::lockForUpdate()` (mesma linha/mesmo mecanismo
 * já usado por `AlocarRequisicaoAoPacote`) ANTES de criar a RC —
 * serializa contra um `delete()` concorrente do Pacote
 * (`⚡suprimentos.blade.php::excluirItem()` já trava essa mesma linha
 * antes de chamar `delete()`), fechando de origem a mesma classe de
 * corrida já corrigida reativamente em 19.2.CORREÇÃO/19.3.CORREÇÃO.
 */
class CriarRequisicaoCompra
{
    public function execute(ItemSuprimento $pacote, ?FluxoSuprimento $fluxo, ?string $observacao, User $usuario): RequisicaoCompra
    {
        return DB::transaction(function () use ($pacote, $fluxo, $observacao, $usuario) {
            $pacote = ItemSuprimento::whereKey($pacote->id)->lockForUpdate()->firstOrFail();

            return RequisicaoCompra::create([
                'obra_id' => $pacote->obra_id,
                'item_suprimento_id' => $pacote->id,
                'status' => StatusRequisicaoCompra::Rascunho,
                'fluxo_suprimento_id' => $fluxo?->id,
                'observacao' => $observacao,
                'created_by_id' => $usuario->id,
            ]);
        });
    }
}
