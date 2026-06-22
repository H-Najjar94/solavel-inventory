<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function (): void {
            // Web routes (session-stateful).
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/web.php');

            // SolaStock JSON API. Apache's `Alias /inventory/` strips the
            // /inventory prefix before Laravel sees the request (same as the web
            // routes, which are prefix-less), so the in-app prefix is just `api`.
            // Live URL = solavel.com/inventory/api/v1/...  →  Laravel sees api/v1/...
            //
            // The SPA is same-origin and session-authenticated, so the API uses the
            // `web` middleware group (cookies + session + CSRF) — the standard
            // stateful-SPA pattern. This also makes $request->session() available
            // to ResolveInventoryTenant.
            \Illuminate\Support\Facades\Route::middleware('web')
                ->prefix('api')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SolaStock middleware aliases (mirrors Finance naming).
        $middleware->alias([
            'perm' => \App\Http\Middleware\EnsureInventoryPermission::class,
            'inv.tenant' => \App\Http\Middleware\ResolveInventoryTenant::class,
        ]);

        // SSO, the Solavel way: consume a handoff token minted by the central app
        // and, if still unauthenticated, bounce to the parent's /sso/inventory.
        // Web group only (HTML navigations) — API calls receive clean JSON states.
        // These are APPENDED (not prepended) and ordered via priority() below so
        // they run AFTER StartSession — otherwise session writes + Auth::login()
        // in the handoff have no started session and are silently lost.
        $middleware->web(append: [
            \App\Http\Middleware\AuthenticateFromInventoryHandoff::class,
            \App\Http\Middleware\BounceToParentForSso::class,
        ]);

        // The API routes group ('api' middleware) — this app was not scaffolded
        // with install:api, so define the group explicitly. SPA requests use the
        // web/session guard. (A named 'api' rate limiter can be added later via
        // RateLimiter::for('api', ...); omitted here to avoid an undefined-limiter
        // boot failure.)
        $middleware->group('api', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Ensure the tenant is resolved (or a clean 409 returned) BEFORE route
        // model binding runs — otherwise binding queries the tenant DB with no
        // database selected and 500s instead of returning the no-tenant 409.
        $middleware->priority([
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // SSO must run AFTER the session is started so its session writes +
            // Auth::login() persist and a session cookie is set on the redirect.
            \App\Http\Middleware\AuthenticateFromInventoryHandoff::class,
            \App\Http\Middleware\BounceToParentForSso::class,
            \App\Http\Middleware\ResolveInventoryTenant::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureInventoryPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
