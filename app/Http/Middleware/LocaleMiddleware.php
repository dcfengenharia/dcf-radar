<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocaleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale');
        if ($locale && in_array($locale, ['en', 'fr', 'ar', 'de'])) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
