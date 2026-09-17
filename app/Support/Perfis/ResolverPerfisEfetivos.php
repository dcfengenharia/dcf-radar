<?php

namespace App\Support\Perfis;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2C — resolução em LOTE de "quais perfis o usuário tem
 * efetivamente nesta obra", pra telas que precisam disso pra MUITOS
 * pares (obra, usuário) ao mesmo tempo (Matriz de Acessos, cálculo de
 * impacto de um Perfil) — nunca N chamadas a `$user->perfisNaObra($obra)`
 * (que seria N+1 real pra uma matriz com centenas de linhas).
 *
 * Replica EXATAMENTE a mesma regra de autoridade de
 * `App\Models\Concerns\HasObraPapel::perfisIdsNaObra()` (Fase
 * 2B.CORREÇÃO, Seção 5/6): a nova pivot (`obra_user_perfil`), uma vez
 * POPULADA pra um par, é autoridade completa; só quando ela está
 * TOTALMENTE VAZIA pra aquele par o espelho legado
 * (`obra_user.perfil_id`) entra como fallback de compatibilidade. Nunca
 * uma união permanente das duas fontes (a "revogação fantasma" corrigida
 * na Fase 2B.CORREÇÃO). Deliberadamente NÃO reaproveita `HasObraPapel`
 * por instância (evitaria N+1 na Matriz) — a consistência entre esta
 * classe e o resolver por instância é garantida por teste dedicado
 * (`ResolverPerfisEfetivosTest::test_consistente_com_hasobrapapel`),
 * nunca só por inspeção visual do código.
 */
class ResolverPerfisEfetivos
{
    /**
     * @return Collection<int, object{work_id: string, user_id: string, perfil_ids: array<int, string>}>
     */
    public static function paraTenant(string $tenantId): Collection
    {
        $membresias = DB::table('obra_user')
            ->join('works', 'works.id', '=', 'obra_user.work_id')
            ->where('works.tenant_id', $tenantId)
            ->select('obra_user.work_id', 'obra_user.user_id', 'obra_user.perfil_id as legado_id')
            ->get();

        if ($membresias->isEmpty()) {
            return collect();
        }

        $workIds = $membresias->pluck('work_id')->unique()->values();

        $novaPivotPorPar = DB::table('obra_user_perfil')
            ->whereIn('work_id', $workIds)
            ->get(['work_id', 'user_id', 'perfil_id'])
            ->groupBy(fn ($linha) => $linha->work_id.'|'.$linha->user_id);

        return $membresias->map(function ($membresia) use ($novaPivotPorPar) {
            $chave = $membresia->work_id.'|'.$membresia->user_id;
            $daPivot = $novaPivotPorPar->get($chave);

            $perfilIds = $daPivot !== null && $daPivot->isNotEmpty()
                ? $daPivot->pluck('perfil_id')->unique()->values()->all()
                : ($membresia->legado_id !== null ? [$membresia->legado_id] : []);

            return (object) [
                'work_id' => $membresia->work_id,
                'user_id' => $membresia->user_id,
                'perfil_ids' => $perfilIds,
            ];
        })->values();
    }

    /**
     * Só os pares (obra, usuário) onde `$perfilId` está entre os perfis
     * efetivos — usado pelo cálculo de impacto (Seção 28/50).
     *
     * @return Collection<int, object{work_id: string, user_id: string, perfil_ids: array<int, string>}>
     */
    public static function paresComPerfil(string $tenantId, string $perfilId): Collection
    {
        return self::paraTenant($tenantId)
            ->filter(fn ($par) => in_array($perfilId, $par->perfil_ids, true))
            ->values();
    }

    /**
     * Todos os pares (obra, usuário) efetivos de UM usuário específico —
     * usado pela vitrine "Obras e Perfis" (Seção 25).
     *
     * @return Collection<int, object{work_id: string, user_id: string, perfil_ids: array<int, string>}>
     */
    public static function paresDoUsuario(string $tenantId, string $userId): Collection
    {
        return self::paraTenant($tenantId)
            ->filter(fn ($par) => $par->user_id === $userId)
            ->values();
    }
}
