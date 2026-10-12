<?php

namespace App\Http\Middleware;

use App\Services\Preferences\CentralDisplayPreferences;
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

        // Language picker with "Save as my default language" ON: also store the
        // choice as the account default on Central (best effort), then drop the
        // flag from the URL so a refresh does not save again.
        if ($request->isMethod('GET') && is_string($request->query('locale')) && $request->query('remember') === '1') {
            $centralUserId = CentralDisplayPreferences::centralUserId($request->user());
            if ($centralUserId > 0) {
                app(CentralDisplayPreferences::class)->save($centralUserId, ['preferred_locale' => $locale]);
            }
            $query = $request->except(['remember']);

            return redirect($request->url().($query ? '?'.http_build_query($query) : ''));
        }

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
