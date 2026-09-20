<?php
namespace App\Http\Middleware;

use App\Services\Access\CentralAppAccess;
use App\Services\Tenancy\LiveTenantResolver;
use Closure;
use Illuminate\Http\Request;

/** Runs before HTML, bootstrap data and tenant APIs, including existing sessions. */
class EnsureInventoryAppAccess
{
    public function handle(Request $request, Closure $next)
    {
        $authority = app(CentralAppAccess::class);
        $user = $request->user();
        $orgId = app(LiveTenantResolver::class)->organizationId($request);
        $userId = (int) ($user?->central_user_id ?: $user?->id);
        $decision = $authority->decision($userId, (int) $orgId, 'inventory');
        if (! ($decision['allowed'] ?? false)) return $authority->deny($request, $decision, 'inventory');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
