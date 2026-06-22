<?php

namespace App\Http\Middleware;

use App\Services\Access\InventoryPermissionService;
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
    public function __construct(private InventoryPermissionService $permissions) {}

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

        return $next($request);
    }
}
