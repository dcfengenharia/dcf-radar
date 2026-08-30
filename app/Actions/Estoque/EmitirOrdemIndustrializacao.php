<?php

namespace App\Actions\Estoque;

use App\Exceptions\OrdemIndustrializacaoInvalidaException;
use App\Models\OrdemIndustrializacao;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — transição Rascunho→Emitida. Mesmo padrão de
 * numeração já usado em RC/Pedido/GRD/RP: lock em linha estável
 * (`Work`), `MAX(numero)+1`, `UNIQUE(obra_id, numero)` como defesa
 * final. Exige pelo menos 1 Produto previsto (uma Ordem sem produto
 * não tem o que fabricar).
 */
class EmitirOrdemIndustrializacao
{
    public function execute(OrdemIndustrializacao $ordem, User $usuario): OrdemIndustrializacao
    {
        return DB::transaction(function () use ($ordem, $usuario) {
            $ordemTravada = OrdemIndustrializacao::whereKey($ordem->id)->lockForUpdate()->firstOrFail();

            if (! $ordemTravada->estaRascunho()) {
                throw new OrdemIndustrializacaoInvalidaException('Esta Ordem já foi emitida.');
            }

            if ($ordemTravada->produtos()->count() === 0) {
                throw new OrdemIndustrializacaoInvalidaException('Adicione ao menos um produto previsto antes de emitir a Ordem.');
            }

            Work::whereKey($ordemTravada->obra_id)->lockForUpdate()->first();

            $proximoNumero = (int) (OrdemIndustrializacao::where('obra_id', $ordemTravada->obra_id)->max('numero') ?? 0) + 1;

            $ordemTravada->update([
                'status' => 'emitida',
                'numero' => $proximoNumero,
                'emitida_em' => now(),
                'emitida_por' => $usuario->id,
            ]);

            return $ordemTravada->fresh();
        });
    }
}
