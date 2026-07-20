<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && Gate::allows('acessar-admin-plataforma'), 403);

        return $next($request);
    }
}
