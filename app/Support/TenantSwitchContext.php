<?php

namespace App\Support;

use App\Models\Tenant;

class TenantSwitchContext
{
    private const SESSION_KEY = 'tenant_switch_id';

    public static function set(Tenant $tenant): void
    {
        session([self::SESSION_KEY => $tenant->id]);

        // Obra selecionada não faz sentido sob outro tenant.
        ObraContext::clear();
    }

    public static function currentId(): ?string
    {
        return session(self::SESSION_KEY);
    }

    public static function current(): ?Tenant
    {
        $id = static::currentId();

        return $id ? Tenant::find($id) : null;
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
