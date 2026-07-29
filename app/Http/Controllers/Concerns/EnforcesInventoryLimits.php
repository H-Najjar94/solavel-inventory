<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Entitlements\InventoryLimitService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The grandfathered plan-limit gate for resource-creating controllers.
 *
 * A limited resource (items, warehouses) may be created only while the org is
 * below its plan ceiling. A tenant already AT or OVER the ceiling keeps and fully
 * uses every existing record — the gate blocks only the NEXT create. Nothing is
 * deleted or disabled on a downgrade; growth stops, data stays. This is the
 * SolaBooks canCreate() contract, and it is why enforcement is safe to switch on
 * for the live over-limit tenants (clients 2 & 18 already exceed the Free
 * warehouse cap).
 *
 * DARK-LAUNCH: no-op unless SOLASTOCK_FEATURE_ENFORCEMENT is on.
 */
trait EnforcesInventoryLimits
{
    /**
     * Abort with a 402 commercial envelope if creating one more of $featureKey's
     * resource would exceed the plan limit. $currentCount is the org's existing
     * active count (already org-scoped by the model's global scope).
     */
    protected function enforceLimit(string $featureKey, int $currentCount): void
    {
        if (! (bool) config('inventory_entitlements.feature_enforcement', false)) {
            return;
        }

        $limits = app(InventoryLimitService::class);

        if ($limits->canCreate($featureKey, $currentCount)) {
            return;
        }

        $usage = $limits->usage($featureKey, $currentCount);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => 'plan_limit_reached',
                'message' => __('inventory.common.plan_limit'),
                'reason_code' => 'feature_not_in_plan',
                'feature' => $featureKey,
                'limit' => $usage['limit'],
                'used' => $usage['used'],
                'remaining' => $usage['remaining'],
            ],
        ], 402));
    }
}
