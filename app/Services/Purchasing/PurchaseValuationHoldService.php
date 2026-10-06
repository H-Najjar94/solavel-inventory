<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\Item;
use App\Models\Tenant\PurchaseValuationHold;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PurchaseValuationHoldService
{
    /** Item locks serialize provenance quotes with every native physical ledger path. */
    public function lockItems(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            Item::query()->where('organization_id', app(OrganizationContext::class)->idOrFail())->whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    public function assertMovable(int $itemId, int $warehouseId, ?array $allowedHold = null): void
    {
        if (! Schema::connection('tenant')->hasTable('purchase_valuation_holds')) {
            return; // Rolling compatibility before the additive capability exists.
        }
        $query = PurchaseValuationHold::query()->where('organization_id', app(OrganizationContext::class)->idOrFail())
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->where('state', 'active');
        foreach ($query->get() as $hold) {
            if ($allowedHold && isset($allowedHold['settlement_uuid']) && $hold->settlement_uuid === $allowedHold['settlement_uuid']
                && $hold->purpose === $allowedHold['purpose'] && (int) $hold->plan_revision === (int) $allowedHold['plan_revision']
                && hash_equals($hold->plan_fingerprint, $allowedHold['plan_fingerprint'])) {
                continue;
            }
            if (($allowedHold['purpose'] ?? null) === 'reverse' && isset($allowedHold['group_bill_id'])
                && $hold->purpose === 'reverse' && (int) $hold->source_bill_id === (int) $allowedHold['group_bill_id']) {
                continue;
            }
            throw ValidationException::withMessages(['item_id' => __('receiving.valuation_pending')]);
        }
    }

    public function acquire(array $scope, string $fingerprint): PurchaseValuationHold
    {
        $existing = PurchaseValuationHold::query()->where('organization_id', app(OrganizationContext::class)->idOrFail())->where('settlement_uuid', $scope['settlement_uuid'])
            ->where('purpose', $scope['purpose'])->where('plan_revision', $scope['plan_revision'])->lockForUpdate()->first();
        if ($existing) {
            abort_unless(hash_equals($existing->plan_fingerprint, $fingerprint), 409, __('receiving.valuation_changed'));
            abort_unless($existing->state === 'active', 409, __('receiving.valuation_changed'));

            return $existing;
        }
        $this->assertMovable($scope['item_id'], $scope['warehouse_id'], $scope['purpose'] === 'reverse'
            ? ['purpose' => 'reverse', 'group_bill_id' => $scope['source_bill_id']] : null);

        return PurchaseValuationHold::query()->create($scope + ['organization_id' => app(OrganizationContext::class)->idOrFail(),
            'plan_fingerprint' => $fingerprint, 'state' => 'active']);
    }
}
