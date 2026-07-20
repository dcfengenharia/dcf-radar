<?php

namespace App\Http\Middleware;

use App\Models\Plano;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * O endpoint /livewire/upload-file (do próprio pacote Livewire) valida
 * tamanho de arquivo só com config('livewire.temporary_file_upload.rules')
 * — um valor global, sem noção de tenant/componente. Esse middleware
 * sobrescreve esse config em runtime, por requisição, com o limite do
 * plano do tenant atual, ANTES desse endpoint validar (ele também está no
 * grupo 'web', então é coberto automaticamente).
 */
class DefinirLimiteUploadDoTenant
{
    public function handle(Request $request, Closure $next)
    {
        $mb = Plano::LIMITE_UPLOAD_PADRAO_MB;

        if (auth()->check()) {
            $tenantId = TenantContext::currentId();
            if ($tenantId) {
                $mb = Tenant::find($tenantId)?->limiteUploadMb() ?? $mb;
            }
        }

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:' . ($mb * 1024)]]);

        return $next($request);
    }
}
