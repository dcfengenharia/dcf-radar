<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if ($tenantId = TenantContext::currentId()) {
                $model->tenant_id = $tenantId;
            }
        });
    }
}
