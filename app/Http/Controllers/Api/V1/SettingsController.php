<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\ItemBrand;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory settings + master-data CRUD (units, conversions, categories, brands,
 * zones, bins) and policy settings (costing method, negative-stock, numbering,
 * barcode, approval rules, reason codes). Thin; validation inline for brevity.
 */
class SettingsController extends ApiController
{
    public function __construct(private OrganizationContext $context) {}

    public function show(): JsonResponse
    {
        $orgId = $this->context->idOrFail();

        return $this->success([
            'settings' => InventorySetting::query()->firstOrNew(['organization_id' => $orgId]),
            'units' => Unit::query()->orderBy('name')->get(),
            'unit_conversions' => UnitConversion::query()->get(),
            'categories' => ItemCategory::query()->orderBy('name')->get(),
            'brands' => ItemBrand::query()->orderBy('name')->get(),
            'zones' => WarehouseZone::query()->get(),
            'bins' => WarehouseBin::query()->get(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'default_costing_method' => ['nullable', 'in:average,fifo,standard'],
            'allow_negative_stock' => ['boolean'],
            'picking_policy' => ['nullable', 'in:manual,fifo,fefo'],
            'value_tolerance' => ['nullable', 'numeric', 'min:0'],
            'numbering' => ['nullable', 'array'],
            'barcode' => ['nullable', 'array'],
            'approvals' => ['nullable', 'array'],
        ]);

        $settings = InventorySetting::query()->updateOrCreate(['organization_id' => $orgId], $data);

        return $this->success($settings);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'kind' => ['nullable', 'in:count,weight,volume,length'],
        ]);

        return $this->success(Unit::create($data)->fresh(), 201);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        return $this->success(ItemCategory::create($data)->fresh(), 201);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        return $this->success(ItemBrand::create($data)->fresh(), 201);
    }
}
