<?php

use App\Http\Middleware\AuthenticateFromInventoryHandoff;
use App\Http\Middleware\BounceToParentForSso;
use App\Http\Middleware\EnsureIntegrationSetupCapability;
use App\Http\Middleware\EnsureInventoryFeature;
use App\Http\Middleware\EnsureInventoryPermission;
use App\Http\Middleware\ResolveInventoryTenant;
use App\Http\Middleware\SetInventoryLocale;
use App\Http\Middleware\VerifySolaposSignature;
use App\Http\Middleware\VerifySolavelSyncSignature;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function (): void {
            // Web routes (session-stateful).
            Route::middleware('web')
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
            Route::middleware('web')
                ->prefix('api')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SolaStock middleware aliases (mirrors Finance naming).
        $middleware->alias([
            'perm' => EnsureInventoryPermission::class,
            'integration.setup' => EnsureIntegrationSetupCapability::class,
            'feature' => EnsureInventoryFeature::class,
            'inv.tenant' => ResolveInventoryTenant::class,
            'sync.signature' => VerifySolavelSyncSignature::class,
            'solapos.signature' => VerifySolaposSignature::class,
        ]);

        // Central sync posts are authenticated by the HMAC signature middleware,
        // not by the browser session CSRF token used by the same-origin SPA API.
        $middleware->validateCsrfTokens(except: [
            'api/tenancy/sync/events',
            // SolaPOS → SolaStock retail consumption: authenticated by the SolaPOS
            // HMAC signature middleware (Phase 6), never by a browser session.
            'api/v1/integration/solapos/consumptions',
        ]);

        // SSO, the Solavel way: consume a handoff token minted by the central app
        // and, if still unauthenticated, bounce to the parent's /sso/inventory.
        // Web group only (HTML navigations) — API calls receive clean JSON states.
        // These are APPENDED (not prepended) and ordered via priority() below so
        // they run AFTER StartSession — otherwise session writes + Auth::login()
        // in the handoff have no started session and are silently lost.
        $middleware->web(append: [
            SetInventoryLocale::class,
            AuthenticateFromInventoryHandoff::class,
            BounceToParentForSso::class,
        ]);

        // The API routes group ('api' middleware) — this app was not scaffolded
        // with install:api, so define the group explicitly. SPA requests use the
        // web/session guard. (A named 'api' rate limiter can be added later via
        // RateLimiter::for('api', ...); omitted here to avoid an undefined-limiter
        // boot failure.)
        $middleware->group('api', [
            SubstituteBindings::class,
        ]);

        // Ensure the tenant is resolved (or a clean 409 returned) BEFORE route
        // model binding runs — otherwise binding queries the tenant DB with no
        // database selected and 500s instead of returning the no-tenant 409.
        $middleware->priority([
            EncryptCookies::class,
            StartSession::class,
            SetInventoryLocale::class,
            // SSO must run AFTER the session is started so its session writes +
            // Auth::login() persist and a session cookie is set on the redirect.
            AuthenticateFromInventoryHandoff::class,
            BounceToParentForSso::class,
            ResolveInventoryTenant::class,
            SubstituteBindings::class,
            EnsureInventoryPermission::class,
            EnsureIntegrationSetupCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('inventory/api/*')) {
                return response()->json(['message' => __('inventory.common.resource_not_found')], 404);
            }
        });
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('inventory/api/*')) {
                return response()->json(['message' => __('inventory.common.resource_not_found')], 404);
            }
        });
    })->create();
