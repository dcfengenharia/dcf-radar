<?php

namespace App\Support\Gestao;

use App\Models\UltimoAcessoHome;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;

/**
 * Home Executiva (Ciclo 25, Fechamento — Seção 3/4/6/7) — captura o
 * cursor ANTERIOR de acesso à Home (por obra + usuário) e só DEPOIS o
 * avança para agora. A ordem importa: se avançássemos antes de ler, "O
 * que mudou desde sua última visita" ficaria sempre vazio.
 *
 * Chamado UMA vez por navegação real (dentro de `mount()` do componente
 * Livewire da Home, nunca em re-render/polling) — é o próprio ciclo de
 * vida do Livewire que garante isso: `mount()` só roda quando o
 * componente é instanciado do zero (page load / redirect completo),
 * nunca em uma atualização parcial (`wire:model`, `wire:poll`) do mesmo
 * componente já montado.
 */
class UltimoAcessoHomeTracker
{
    /**
     * @return Carbon|null o cursor ANTERIOR (null = primeiro acesso desta
     *                      obra por este usuário)
     */
    public static function capturarEAvancar(Work $obra, User $user): ?Carbon
    {
        $registro = UltimoAcessoHome::query()
            ->where('obra_id', $obra->id)
            ->where('user_id', $user->id)
            ->first();

        $anterior = $registro?->ultimo_acesso_em;

        if ($registro) {
            $registro->update(['ultimo_acesso_em' => now()]);
        } else {
            UltimoAcessoHome::create([
                'obra_id' => $obra->id,
                'user_id' => $user->id,
                'ultimo_acesso_em' => now(),
            ]);
        }

        return $anterior;
    }
}
