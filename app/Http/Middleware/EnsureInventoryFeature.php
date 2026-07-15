<?php

namespace App\Http\Middleware;

use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: feature:stock.transfers  (explicit), or bare `feature`
 * (derives the stock.* key from the route name via
 * config('inventory_entitlements.route_features')).
 *
 * This gates paid stock.* features whose surface shares a base/free permission
 * (transfers, PO, GRN, counts, suppliers, barcodes, variants, locations/bins,
 * import/export) — where gating through the permission map would wrongly gate the
 * base capability too. It runs ALONGSIDE perm:, never replacing role auth.
 *
 * DARK-LAUNCH: inert until SOLASTOCK_FEATURE_ENFORCEMENT=true, exactly like the
 * commercial layer in EnsureInventoryPermission.
 */
class EnsureInventoryFeature
{
    public function __construct(
        private InventoryCommercialEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string ...$featureKeys): Response
    {
        if (! (bool) config('inventory_entitlements.feature_enforcement', false)) {
            return $next($request);
        }

        $featureKeys = array_values(array_filter(array_map(
            static fn ($k) => trim((string) $k),
            $featureKeys
        )));

        // Bare `feature` with no arg: derive from the route name.
        if ($featureKeys === []) {
            $name = optional($request->route())->getName();
            $map = (array) config('inventory_entitlements.route_features', []);
            if ($name !== null && isset($map[$name])) {
                $featureKeys = [(string) $map[$name]];
            }
        }

        if ($featureKeys === []) {
            return $next($request);
        }

        foreach ($featureKeys as $featureKey) {
            $commercial = $this->entitlements->checkFeature($featureKey);

            if (! $commercial['allowed']) {
                if ($request->expectsJson() || $request->is('*api/*')) {
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
        }

        return $next($request);
    }
}
