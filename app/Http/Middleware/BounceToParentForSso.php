<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * If a user reaches SolaStock without a session and no handoff is in flight,
 * bounce them to the central app's /sso/inventory?to=<this-url>. The central app
 * mints a handoff token (after /login if needed) and redirects back here with
 * ?handoff=… which AuthenticateFromInventoryHandoff consumes.
 *
 * Mirrors Finance's BounceToParentForSso. Skips the tenant-selection + SSO
 * routes themselves, anything already carrying a handoff / _sso_tried marker,
 * and (importantly) does NOT bounce when demo mode is selected — so the safe
 * demo preview keeps working without a central login.
 */
class BounceToParentForSso
{
    /** Path fragments (under the /inventory mount) that must never bounce. */
    private const SKIP = ['api/v1/tenant', 'sso', 'up', 'health'];

    /** One-shot cookie that prevents an SSO bounce loop (TTL minutes). */
    private const TRIED_COOKIE = 'inv_sso_tried';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }
        // Handoff round-trip in flight, or already tried once this request — pass.
        if ($request->has('handoff') || $request->has('_sso_tried')) {
            // Persist the "tried" marker so a return trip that lands WITHOUT a
            // token (declined / not-provisioned / login failed) does NOT bounce
            // again — the SPA renders and shows the real tenant state instead.
            return $this->passWithTriedCookie($next, $request);
        }
        // If we already bounced once recently, never bounce again — let the SPA
        // load so the app ALWAYS opens (no central round-trip dead-end).
        if ($request->cookie(self::TRIED_COOKIE)) {
            return $next($request);
        }
        // Operator-selected demo preview must not require a central login.
        if ($request->hasSession() && $request->session()->get('inventory_demo_tenant')) {
            return $next($request);
        }
        // Only bounce top-level navigations (HTML), never JSON/XHR API calls —
        // those return a clean 401/409 the SPA handles.
        if ($request->expectsJson() || $request->ajax()) {
            return $next($request);
        }
        foreach (self::SKIP as $frag) {
            if ($request->is("*{$frag}*")) {
                return $next($request);
            }
        }
        // SSO must be explicitly enabled (off by default so local/dev and the
        // demo flow are unaffected until the central /sso/inventory endpoint exists).
        if (! (bool) config('inventory.sso.enabled', env('INVENTORY_SSO_ENABLED', false))) {
            return $next($request);
        }

        $base = rtrim((string) config('tenancy.parent_base_url', 'https://solavel.com'), '/');
        $path = (string) config('tenancy.sso_path', '/sso/inventory');
        $to = urlencode($request->fullUrl());

        // Mark that we attempted SSO so the return trip can't loop.
        return redirect()->away("{$base}{$path}?to={$to}")
            ->withCookie(cookie(self::TRIED_COOKIE, '1', 5));
    }

    private function passWithTriedCookie(Closure $next, Request $request): Response
    {
        $response = $next($request);
        // Queue the cookie so the next direct navigation doesn't bounce.
        return $response->withCookie(cookie(self::TRIED_COOKIE, '1', 5));
    }
}
