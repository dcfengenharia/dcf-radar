<?php

namespace App\Support;

use App\Models\Impersonacao;
use App\Models\Tenant;

class ImpersonationContext
{
    private const SESSION_KEY = 'impersonating_tenant_id';

    private const LOG_KEY = 'impersonation_log_id';

    public static function start(Tenant $tenant, ?string $motivo = null): void
    {
        $log = Impersonacao::create([
            'admin_user_id' => auth()->id(),
            'tenant_id' => $tenant->id,
            'motivo' => $motivo,
            'iniciado_em' => now(),
            'ip' => request()->ip(),
        ]);

        session([self::SESSION_KEY => $tenant->id, self::LOG_KEY => $log->id]);
    }

    /**
     * Pré-produção, Etapa 2 (seção 10/11) — único ponto usado pra decidir
     * se um bypass administrativo de acesso a dados de obra é legítimo:
     * só conta como "impersonando" quando a sessão já tem uma
     * impersonation ATIVA pro MESMO tenant dono da obra em questão — nunca
     * só por `is_platform_admin` (isso é o bug que esta etapa fecha).
     */
    public static function impersonandoTenant(string $tenantId): bool
    {
        return static::currentTenantId() === $tenantId;
    }

    public static function currentTenantId(): ?string
    {
        return session(self::SESSION_KEY);
    }

    public static function current(): ?Tenant
    {
        $id = static::currentTenantId();

        return $id ? Tenant::find($id) : null;
    }

    public static function stop(): void
    {
        if ($logId = session(self::LOG_KEY)) {
            Impersonacao::whereNull('finalizado_em')->find($logId)?->update(['finalizado_em' => now()]);
        }

        session()->forget([self::SESSION_KEY, self::LOG_KEY]);
    }
}
