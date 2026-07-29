<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetInventoryLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('locale')
            ?? $request->header('X-Locale')
            ?? $request->session()->get('solastock_locale')
            ?? $request->header('Accept-Language');

        $locale = is_string($requested)
            ? strtolower(substr(trim($requested), 0, 2))
            : null;

        if (! in_array($locale, ['en', 'ar'], true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);
        $request->session()->put('solastock_locale', $locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
