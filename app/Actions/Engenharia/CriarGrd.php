<?php

namespace App\Actions\Engenharia;

use App\Enums\StatusGrd;
use App\Models\Grd;
use App\Models\User;
use App\Models\Work;

/**
 * Ciclo 18, Etapa 18.5.1 — cria uma Grd em Rascunho. `numero` nasce NULL
 * (não consome sequência — só é atribuído na emissão, ver
 * App\Actions\Engenharia\EmitirGrd).
 */
class CriarGrd
{
    public function execute(Work $obra, ?User $usuario, ?string $observacao = null): Grd
    {
        return Grd::create([
            'obra_id' => $obra->id,
            'numero' => null,
            'status' => StatusGrd::Rascunho,
            'criado_por' => $usuario?->id,
            'observacao' => $observacao,
        ]);
    }
}
