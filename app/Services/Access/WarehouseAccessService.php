<?php

namespace App\Services\Access;

use App\Models\Tenant\InventoryUserWarehouse;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/** Resolves the active user's explicit warehouse assignment within the active org. */
class WarehouseAccessService
{
    public function __construct(private OrganizationContext $context) {}

    /** null means unrestricted for this role; an empty array means no warehouse access. */
    public function allowedIds(?int $userId = null): ?array
    {
        $userId ??= (int) Auth::id();
        if ($userId <= 0 || ! $this->context->has()) {
            return [];
        }

        // Keep existing tenants readable until this tenant migration is applied.
        try {
            if (! Schema::connection(config('tenancy.tenant_connection', 'tenant'))->hasTable('inventory_user_warehouses')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // Owners and inventory administrators retain organization-wide access.
        if (app(InventoryPermissionService::class)->can(Auth::user(), 'inventory.manage_settings')) {
            return null;
        }

        $ids = InventoryUserWarehouse::query()
            ->where('user_id', $userId)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->values()->all();

        // Existing operational users without an assignment remain organization-wide
        // until an administrator explicitly assigns a restricted scope.
        return $ids === [] ? null : $ids;
    }

    public function assertAllowed(int $warehouseId): void
    {
        $allowed = $this->allowedIds();
        if ($allowed !== null && ! in_array($warehouseId, $allowed, true)) {
            throw new AuthorizationException('You are not assigned to this warehouse.');
        }
    }

    public function scope(Builder $query, string $column = 'warehouse_id'): Builder
    {
        $allowed = $this->allowedIds();

        return $allowed === null ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $allowed);
    }

    public function scopeTransfer(Builder $query, string $from = 'from_warehouse_id', string $to = 'to_warehouse_id'): Builder
    {
        $allowed = $this->allowedIds();
        if ($allowed === null) {
            return $query;
        }
        $model = $query->getModel();

        return $query->whereIn($model->qualifyColumn($from), $allowed)
            ->whereIn($model->qualifyColumn($to), $allowed);
    }
}
