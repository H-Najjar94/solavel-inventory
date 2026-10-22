<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\ItemSupplier;
use App\Services\Access\InventoryPermissionService;
use Illuminate\Validation\ValidationException;

/** A supplier blocked for an item cannot be ordered from unless the user may approve purchase orders. */
final class BlockedSupplierGuard
{
    public const OVERRIDE_PERMISSION = 'inventory.approve_purchase_orders';

    public function assertAllowed(?int $supplierId, array $lines): void
    {
        if (! $supplierId || ! (bool) config('supplier_terms.enabled', true)
            || ! \Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('item_suppliers')) {
            return; // Never block purchase orders on a tenant whose additive migration is still pending.
        }
        $itemIds = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['item_id'] ?? 0), $lines))));
        if ($itemIds === []) {
            return;
        }
        $blocked = ItemSupplier::query()->where('supplier_id', $supplierId)->where('status', 'blocked')
            ->whereIn('item_id', $itemIds)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        if ($blocked === []) {
            return;
        }
        $user = request()->user();
        if ($user && app(InventoryPermissionService::class)->can($user, self::OVERRIDE_PERMISSION)) {
            return;
        }
        $messages = [];
        foreach ($lines as $index => $line) {
            if (in_array((int) ($line['item_id'] ?? 0), $blocked, true)) {
                $messages["lines.$index.item_id"] = __('inventory.supplier_terms.supplier_blocked_for_item');
            }
        }

        throw ValidationException::withMessages($messages);
    }
}
