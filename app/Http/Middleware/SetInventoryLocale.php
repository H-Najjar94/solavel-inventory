<?php

namespace App\Http\Middleware;

use App\Services\Preferences\AccountPreferenceSync;
use App\Services\Preferences\CentralDisplayPreferences;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetInventoryLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = AccountPreferenceSync::LOCALES;
        $normalize = static function ($value) use ($supported): ?string {
            $locale = is_string($value) ? strtolower(substr(trim($value), 0, 2)) : null;
            return in_array($locale, $supported, true) ? $locale : null;
        };
        $beforeSync = $request->session()->get('solastock_locale');
        if ($request->isMethod('GET')) {
            app(AccountPreferenceSync::class)->sync($request);
        }
        $afterSync = $request->session()->get('solastock_locale');
        $accountChanged = $afterSync !== $beforeSync && $normalize($afterSync) !== null;
        $incoming = $normalize($request->query('locale'));
        $saved = $normalize($request->cookie('solavel_locale'));
        // A previous page's unfinished API request can save an older file-backed
        // session. The browser preference survives that race and repairs the next
        // navigation. Genuine account changes and authenticated handoffs still win.
        $locale = $incoming ?? $normalize($request->header('X-Locale'))
            ?? ($accountChanged ? $normalize($afterSync) : null)
            ?? $saved ?? $normalize($afterSync)
            ?? $normalize($request->header('Accept-Language')) ?? 'en';
        app()->setLocale($locale);
        // An old tab's X-Locale describes that page only; it must not undo the
        // browser's chosen language or the shared account preference.
        if ($incoming !== null || $saved !== null || ! $request->session()->has('solastock_locale')) {
            $request->session()->put('solastock_locale', $incoming ?? ($accountChanged ? $normalize($afterSync) : null) ?? $saved ?? $locale);
        }

        // Save an explicit default even when it is already the displayed language.
        if ($request->isMethod('GET') && is_string($request->query('locale')) && $request->query('remember') === '1') {
            $centralUserId = CentralDisplayPreferences::centralUserId($request->user());
            if ($centralUserId > 0) {
                app(CentralDisplayPreferences::class)->save($centralUserId, ['preferred_locale' => $locale]);
            }
        }

        // A picker choice is a one-time action. Leaving it in the URL lets a
        // refresh overwrite a language subsequently received from the account.
        if ($request->isMethod('GET') && is_string($request->query('locale')) && ! $request->expectsJson()) {
            $query = $request->query->all();
            unset($query['locale'], $query['remember']);
            $response = redirect($request->url().($query ? '?'.http_build_query($query) : ''));
        } else {
            $response = $next($request);
        }
        $selected = $normalize($request->session()->get('solastock_locale'));
        if ($selected !== null && $selected !== $saved) {
            $host = $request->getHost();
            $domain = ($host === 'solavel.com' || str_ends_with($host, '.solavel.com')) ? '.solavel.com' : null;
            $response->headers->setCookie(cookie('solavel_locale', $selected, 60 * 24 * 365, '/', $domain, $request->isSecure(), true, false, 'lax'));
        }
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
