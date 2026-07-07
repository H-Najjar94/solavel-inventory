<?php

namespace App\Http\Middleware;

use App\Services\Access\InventoryPermissionService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: perm:inventory.view_items
 * Mirrors solavel-finance's EnsureFinancePermission shorthand. Returns the JSON
 * error envelope for API requests and a 403 view for web requests.
 */
class EnsureInventoryPermission
{
    public function __construct(
        private InventoryPermissionService $permissions,
        private InventoryCommercialEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->permissions->can($request->user(), $permission)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'permission_denied', 'message' => "Missing permission: {$permission}"],
                ], 403);
            }

            abort(403, "Missing permission: {$permission}");
        }

        $commercial = $this->entitlements->checkPermission($permission);
        if (! $commercial['allowed']) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'commercial_entitlement_required',
                        'message' => 'This SolaStock feature is not available for the current plan or entitlement state.',
                        'reason_code' => $commercial['reason_code'],
                        'access_mode' => $commercial['access_mode'],
                        'tier' => $commercial['tier'],
                        'feature' => $commercial['feature'],
                        'snapshot' => $commercial['snapshot'],
                    ],
                ], $commercial['status']);
            }

            abort($commercial['status'], 'This SolaStock feature is not available for the current plan or entitlement state.');
        }

        return $next($request);
    }
}
