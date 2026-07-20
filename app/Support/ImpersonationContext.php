<?php

namespace App\Support;

use App\Models\Impersonacao;
use App\Models\Tenant;

class ImpersonationContext
{
    private const SESSION_KEY = 'impersonating_tenant_id';

    private const LOG_KEY = 'impersonation_log_id';

    public static function start(Tenant $tenant): void
    {
        $log = Impersonacao::create([
            'admin_user_id' => auth()->id(),
            'tenant_id' => $tenant->id,
            'iniciado_em' => now(),
            'ip' => request()->ip(),
        ]);

        session([self::SESSION_KEY => $tenant->id, self::LOG_KEY => $log->id]);
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
