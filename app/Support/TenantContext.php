<?php

namespace App\Support;

use App\Models\Tenant;

class TenantContext
{
    protected static ?string $override = null;

    public static function currentId(): ?string
    {
        if (static::$override !== null) {
            return static::$override;
        }

        if (auth()->check() && auth()->user()->is_platform_admin) {
            $tenantImpersonado = ImpersonationContext::currentTenantId();
            if ($tenantImpersonado !== null) {
                return $tenantImpersonado;
            }
        }

        if (auth()->check()) {
            $tenantEscolhido = TenantSwitchContext::currentId();
            if ($tenantEscolhido !== null) {
                return $tenantEscolhido;
            }
        }

        return auth()->check() ? auth()->user()->tenant_id : null;
    }

    public static function actingAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = static::$override;
        static::$override = $tenant->id;
        try {
            return $callback();
        } finally {
            static::$override = $previous;
        }
    }
}
