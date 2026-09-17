<?php

namespace App\Support\Perfis;

/**
 * FASE 2C, Seção 28/50 — quantos usuários ÚNICOS e quantas obras ÚNICAS
 * seriam afetados por uma alteração num Perfil. "N usuários em M obras"
 * significa exatamente isso: N usuários distintos (nunca conta 2x um
 * usuário que tem o mesmo Perfil em 2 obras diferentes) e M obras
 * distintas — nunca um produto cartesiano usuário×obra, e nunca uma
 * contagem inflada por múltiplos perfis do mesmo usuário na mesma obra.
 */
class ImpactoPerfilCalculator
{
    /**
     * @return array{usuarios: int, obras: int, pares: \Illuminate\Support\Collection}
     */
    public static function calcular(string $tenantId, string $perfilId): array
    {
        $pares = ResolverPerfisEfetivos::paresComPerfil($tenantId, $perfilId);

        return [
            'usuarios' => $pares->pluck('user_id')->unique()->count(),
            'obras' => $pares->pluck('work_id')->unique()->count(),
            'pares' => $pares,
        ];
    }
}
