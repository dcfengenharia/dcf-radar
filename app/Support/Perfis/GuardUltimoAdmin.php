<?php

namespace App\Support\Perfis;

use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2C, Seção 27 — "a UX deve antecipar o guard [de último Admin] ...
 * Backend continua sendo autoridade. Não depender apenas da UI." Extraído
 * de `⚡obra-detalhe.blade.php` (que introduziu esta trava na Fase 2B)
 * pra ser reaproveitado também pela Matriz de Acessos — a mesma regra de
 * segurança nunca deveria ter 2 implementações independentes; drift entre
 * elas é exatamente a classe de bug que a Fase 2B.CORREÇÃO passou a
 * sessão inteira consertando (união permanente/"revogação fantasma").
 *
 * `⚡obra-detalhe.blade.php::removeriaOUltimoAdminComNovosPerfis()`
 * DELEGA pra cá — nunca duplica.
 */
class GuardUltimoAdmin
{
    /**
     * @param  array<int, string>  $novosPerfilIds
     */
    public static function removeriaOUltimoAdmin(Work $obra, string $userId, array $novosPerfilIds): bool
    {
        $perfilAdmin = Perfil::porSlugPadrao($obra->tenant, 'admin');
        if (! $perfilAdmin) {
            return false;
        }

        $membro = User::find($userId);
        $eraAdminNestaObra = $membro && $membro->temPerfilNaObra($obra, 'admin');
        $continuaAdmin = in_array($perfilAdmin->id, $novosPerfilIds, true);

        if (! $eraAdminNestaObra || $continuaAdmin) {
            return false;
        }

        $temOutroAdminNoTenant = DB::table('obra_user')
            ->join('works', 'works.id', '=', 'obra_user.work_id')
            ->leftJoin('obra_user_perfil', function ($join) {
                $join->on('obra_user_perfil.work_id', '=', 'obra_user.work_id')
                    ->on('obra_user_perfil.user_id', '=', 'obra_user.user_id');
            })
            ->where('works.tenant_id', $obra->tenant_id)
            ->where('obra_user.user_id', '!=', $userId)
            ->where(function ($q) use ($perfilAdmin) {
                $q->where('obra_user.perfil_id', $perfilAdmin->id)
                    ->orWhere('obra_user_perfil.perfil_id', $perfilAdmin->id);
            })
            ->exists();

        return ! $temOutroAdminNoTenant;
    }
}
