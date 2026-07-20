<?php

namespace App\Actions\Atividade;

use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\CausaNaoCumprimento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarcarNaoConcluido
{
    public function execute(Atividade $atividade, string $descricaoCausa): void
    {
        if (blank($descricaoCausa)) {
            throw ValidationException::withMessages([
                'descricao_causa' => 'É obrigatório informar a causa do não cumprimento.',
            ]);
        }

        DB::transaction(function () use ($atividade, $descricaoCausa) {
            $atividade->update(['status' => StatusAtividade::NaoConcluido]);

            CausaNaoCumprimento::create([
                'atividade_id' => $atividade->id,
                'descricao' => $descricaoCausa,
            ]);
        });
    }
}
