<?php

namespace App\Actions\Estoque;

use App\Enums\StatusReservaEstoque;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Models\ReservaEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.2 — Seção 22: liberar uma reserva é uma transição
 * de domínio, NUNCA delete (histórico preservado). Transição única e
 * irreversível (Ativa -> Liberada, mesmo espírito enxuto de
 * StatusReservaEstoque — sem reabertura nesta fase).
 *
 * Update condicional atômico (`WHERE status = 'ativa'`, mesmo idioma já
 * usado por App\Actions\InconsistenciaAvanco\TratarInconsistenciaAvanco)
 * — nunca lockForUpdate()+leitura+decisão separados: 0 linhas afetadas
 * significa "já não estava ativa" (liberada 2x, ou nunca existiu como
 * ativa), tratado como erro didático, nunca sobrescreve
 * liberado_em/liberado_por/motivo já gravados por uma primeira chamada.
 */
class LiberarReservaEstoque
{
    public function execute(ReservaEstoque $reserva, User $usuario, ?string $motivo = null): ReservaEstoque
    {
        return DB::transaction(function () use ($reserva, $usuario, $motivo) {
            $linhasAfetadas = ReservaEstoque::where('id', $reserva->id)
                ->where('status', StatusReservaEstoque::Ativa->value)
                ->update([
                    'status' => StatusReservaEstoque::Liberada->value,
                    'liberado_em' => now(),
                    'liberado_por' => $usuario->id,
                    'motivo_liberacao' => $motivo,
                ]);

            if ($linhasAfetadas === 0) {
                throw new ReservaEstoqueInvalidaException('Esta reserva já não está ativa — não é possível liberá-la novamente.');
            }

            return $reserva->fresh();
        });
    }
}
