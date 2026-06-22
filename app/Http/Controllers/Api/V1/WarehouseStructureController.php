<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Zone & bin management under a warehouse. Enforces code uniqueness and blocks
 * deactivation when stock exists. Bins carry a type (receiving/storage/picking/…)
 * and capacity. Never writes stock.
 */
class WarehouseStructureController extends ApiController
{
    public function __construct(private OrganizationContext $context) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    // ── Zones ──
    public function storeZone(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:191'],
            'keeper_user_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);

        $dupe = WarehouseZone::query()->where('warehouse_id', $warehouse->id)->where('code', $data['code'])->exists();
        if ($dupe) {
            return $this->error('zone_code_taken', 'Zone code must be unique within the warehouse.', 422);
        }

        $zone = $warehouse->organization_id
            ? WarehouseZone::create($data + ['warehouse_id' => $warehouse->id])
            : null;

        return $this->success($zone, 201);
    }

    public function updateZone(Request $request, int $zone): JsonResponse
    {
        $zone = WarehouseZone::query()->findOrFail($zone);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $this->zoneHasStock($zone)) {
            return $this->error('zone_has_stock', 'Cannot deactivate a zone that still holds stock.', 422);
        }

        $zone->update($data);

        return $this->success($zone->fresh());
    }

    // ── Bins ──
    public function storeBin(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:191'],
            'bin_type' => ['nullable', Rule::in(['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'damaged'])],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $dupe = WarehouseBin::query()->where('warehouse_id', $warehouse->id)->where('code', $data['code'])->exists();
        if ($dupe) {
            return $this->error('bin_code_taken', 'Bin code must be unique within the warehouse.', 422);
        }

        // bin_type/barcode are stored in coords json if columns are absent (schema-light).
        $coords = array_filter([
            'bin_type' => $data['bin_type'] ?? null,
            'barcode' => $data['barcode'] ?? null,
        ]);
        $bin = WarehouseBin::create([
            'warehouse_id' => $warehouse->id,
            'zone_id' => $data['zone_id'],
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'coords' => $coords ?: null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($bin, 201);
    }

    public function updateBin(Request $request, int $bin): JsonResponse
    {
        $bin = WarehouseBin::query()->findOrFail($bin);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $this->binHasStock($bin)) {
            return $this->error('bin_has_stock', 'Cannot deactivate a bin that still holds stock.', 422);
        }

        $bin->update($data);

        return $this->success($bin->fresh());
    }

    private function zoneHasStock(WarehouseZone $zone): bool
    {
        $binIds = WarehouseBin::query()->where('zone_id', $zone->id)->pluck('id');

        return $binIds->isNotEmpty()
            && StockBalance::query()->whereIn('bin_id', $binIds)->where('on_hand_qty', '>', 0)->exists();
    }

    private function binHasStock(WarehouseBin $bin): bool
    {
        return StockBalance::query()->where('bin_id', $bin->id)->where('on_hand_qty', '>', 0)->exists();
    }
}
