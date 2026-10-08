<?php

namespace App\Services\Warehouses;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Warehouse;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Organization bootstrap metadata only; never assigns access or creates stock. */
final class DefaultWarehouseService
{
    private function settings(int $organization): InventorySetting
    {
        abort_unless(app(OrganizationContext::class)->idOrFail() === $organization, 403);
        InventorySetting::withoutGlobalScopes()->firstOrCreate(['organization_id' => $organization]);

        return InventorySetting::withoutGlobalScopes()->where('organization_id', $organization)->lockForUpdate()->firstOrFail();
    }

    public function ensure(int $organization, bool $apply = true): array
    {
        abort_unless(app(OrganizationContext::class)->idOrFail() === $organization, 403);
        $db = DB::connection('tenant');
        $result = ['organization_id' => $organization, 'default_warehouse_id' => null, 'created' => false];
        if (! $apply) {
            $default = InventorySetting::withoutGlobalScopes()->where('organization_id', $organization)->value('default_warehouse_id');
            $result['default_warehouse_id'] = $default ? (int) $default : null;
            $exists = $db->table('warehouses')->where('organization_id', $organization)->exists();

            return $result + ['status' => $default ? 'existing_default' : ($exists ? 'existing_warehouses_preserved' : 'would_create'),
                'active_warehouse_available' => $db->table('warehouses')->where('organization_id', $organization)->whereNull('deleted_at')->where('is_active', true)->exists()];
        }

        return $db->transaction(function () use ($organization, $result, $db) {
            $settings = $this->settings($organization);
            if ($settings->default_warehouse_id) {
                $result['default_warehouse_id'] = (int) $settings->default_warehouse_id;
                $result['status'] = 'existing_default';
            } elseif ($db->table('warehouses')->where('organization_id', $organization)->exists()) {
                // A disabled/deleted warehouse is an intentional customer decision.
                $result['status'] = 'existing_warehouses_preserved';
            } else {
                $warehouse = Warehouse::create(['organization_id' => $organization, 'code' => 'MAIN',
                    'name' => __('inventory.warehouse_setup.default_name'), 'type' => 'warehouse', 'is_active' => true]);
                $settings->update(['default_warehouse_id' => $warehouse->id]);
                $result += ['status' => 'created'];
                $result['created'] = true;
                $result['default_warehouse_id'] = (int) $warehouse->id;
            }
            $result['active_warehouse_available'] = $db->table('warehouses')->where('organization_id', $organization)
                ->whereNull('deleted_at')->where('is_active', true)->exists();

            return $result;
        });
    }

    /** Shares the bootstrap lock so first manual creation cannot race initialization. */
    public function create(array $attributes): Warehouse
    {
        $organization = app(OrganizationContext::class)->idOrFail();

        return DB::connection('tenant')->transaction(function () use ($organization, $attributes) {
            $settings = $this->settings($organization);
            $first = ! DB::connection('tenant')->table('warehouses')->where('organization_id', $organization)->exists();
            $warehouse = Warehouse::create(array_replace($attributes, ['organization_id' => $organization]));
            if ($first && ! $settings->default_warehouse_id) {
                $settings->update(['default_warehouse_id' => $warehouse->id]);
            }

            return $warehouse;
        });
    }

    public function present(InventorySetting $settings): InventorySetting
    {
        $settings->setAttribute('default_warehouse_id', $this->authorizedId());

        return $settings;
    }

    /** Read-only; global native organization and warehouse scopes remain enforced. */
    public function authorizedId(): ?int
    {
        $id = InventorySetting::query()->value('default_warehouse_id');

        return $id && Warehouse::query()->where('is_active', true)->whereKey($id)->exists() ? (int) $id : null;
    }
}
