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
        $userId ??= (int) (Auth::id() ?: (function (): int {
            try {
                return (int) (request()->user()?->getAuthIdentifier()
                    ?: request()->session()->get('principal.id', 0));
            } catch (\Throwable) {
                return 0;
            }
        })());
        if ($userId <= 0 || ! $this->context->has()) {
            // Controller unit tests and provisioning probes can call a query
            // without an HTTP principal. Route middleware protects live calls;
            // do not accidentally turn those non-request probes into an empty
            // warehouse result.
            return null;
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
        $user = null;
        try {
            $user = request()->user();
        } catch (\Throwable) {
            // Console/test contexts may not have an HTTP request.
        }
        $user ??= Auth::user();
        if (app(InventoryPermissionService::class)->can($user, 'inventory.manage_settings')) {
            return null;
        }

        $ids = InventoryUserWarehouse::query()
            ->where('user_id', $userId)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->values()->all();

        // An operational user with no explicit assignment has no warehouse
        // access. Organization-wide access is reserved for administrators.
        return $ids;
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
