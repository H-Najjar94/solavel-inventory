<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventoryCurrencyRate;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\InventoryUserWarehouse;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBrand;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseReorderRule;
use App\Models\Tenant\WarehouseZone;
use App\Services\Replenishment\SafetyStockCalculator;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Inventory settings + master-data CRUD (units, conversions, categories, brands,
 * zones, bins) and policy settings (costing method, negative-stock, numbering,
 * barcode, approval rules, reason codes). Thin; validation inline for brevity.
 */
class SettingsController extends ApiController
{
    public function __construct(private OrganizationContext $context, private SafetyStockCalculator $safetyStock) {}

    public function show(): JsonResponse
    {
        $orgId = $this->context->idOrFail();

        return $this->success([
            'settings' => InventorySetting::query()->firstOrNew(['organization_id' => $orgId]),
            'units' => Unit::query()->orderBy('name')->get(),
            'unit_conversions' => UnitConversion::query()
                ->with(['item:id,sku,name', 'fromUnit:id,code,name,symbol', 'toUnit:id,code,name,symbol'])
                ->orderBy('id')->get(),
            'categories' => ItemCategory::query()->with('children:id,parent_id,name,level,is_active')->orderBy('name')->get(),
            'brands' => ItemBrand::query()->orderBy('name')->get(),
            'items' => Item::query()->select('id', 'sku', 'name')->where('item_type', 'inventory')->orderBy('sku')->limit(500)->get(),
            'warehouses' => Warehouse::query()->select('id', 'code', 'name')->orderBy('name')->get(),
            'zones' => WarehouseZone::query()->get(),
            'bins' => WarehouseBin::query()->get(),
            'warehouse_reorder_rules' => WarehouseReorderRule::query()
                ->with(['item:id,sku,name', 'warehouse:id,code,name'])
                ->orderByDesc('id')
                ->limit(250)
                ->get(),
            'currency_rates' => InventoryCurrencyRate::query()
                ->orderBy('currency_code')
                ->orderByDesc('effective_date')
                ->limit(250)
                ->get(),
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
            'taxes' => ['nullable', 'array'],
        ]);

        $settings = InventorySetting::query()->updateOrCreate(['organization_id' => $orgId], $data);

        return $this->success($settings);
    }

    public function updateTaxes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'taxes' => ['array'],
            'taxes.*.name' => ['required', 'string', 'max:100'],
            'taxes.*.code' => ['required', 'string', 'max:50'],
            'taxes.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'taxes.*.treatment' => ['required', 'in:standard,zero,exempt'],
            'taxes.*.active' => ['boolean'],
            'taxes.*.purchase' => ['boolean'],
            'taxes.*.sales' => ['boolean'],
        ]);
        $codes = collect($data['taxes'] ?? [])->pluck('code');
        abort_if($codes->duplicates()->isNotEmpty(), 422, 'Tax codes must be unique.');
        $settings = InventorySetting::query()->updateOrCreate(
            ['organization_id' => $this->context->idOrFail()], ['taxes' => array_values($data['taxes'] ?? [])]
        );
        InventoryAuditLog::create([
            'organization_id' => $this->context->id(), 'actor_user_id' => auth()->id(),
            'action' => 'inventory.tax_settings.updated', 'entity_type' => 'inventory_settings',
            'entity_id' => $settings->id, 'after' => ['tax_codes' => $codes->values()->all()], 'created_at' => now(),
        ]);

        return $this->success($settings);
    }

    public function warehouseAssignments(int $userId): JsonResponse
    {
        abort_unless(Schema::hasTable('inventory_user_warehouses'), 503, 'Warehouse assignment migration is pending.');
        $rows = InventoryUserWarehouse::query()->where('user_id', $userId)->get();

        return $this->success(['user_id' => $userId, 'assignments' => $rows]);
    }

    public function allWarehouseAssignments(): JsonResponse
    {
        abort_unless(Schema::hasTable('inventory_user_warehouses'), 503, 'Warehouse assignment migration is pending.');

        return $this->success(InventoryUserWarehouse::query()->orderBy('user_id')->orderBy('warehouse_id')->get());
    }

    public function syncWarehouseAssignments(Request $request, int $userId): JsonResponse
    {
        abort_unless(Schema::hasTable('inventory_user_warehouses'), 503, 'Warehouse assignment migration is pending.');
        $data = $request->validate(['warehouse_ids' => ['array'], 'warehouse_ids.*' => ['integer']]);
        $ids = collect($data['warehouse_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $valid = Warehouse::query()->whereIn('id', $ids)->pluck('id');
        if ($valid->count() !== $ids->count()) {
            return $this->error('invalid_warehouse_scope', 'Every assigned warehouse must belong to the active organization.', 422);
        }
        InventoryUserWarehouse::query()->where('user_id', $userId)->delete();
        foreach ($valid as $warehouseId) {
            InventoryUserWarehouse::create(['user_id' => $userId, 'warehouse_id' => $warehouseId, 'assigned_by' => auth()->id()]);
        }

        return $this->warehouseAssignments($userId);
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
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('organization_id', $orgId)],
        ]);

        $parent = ! empty($data['parent_id']) ? ItemCategory::query()->findOrFail($data['parent_id']) : null;
        $data['parent_id'] = $parent?->id;
        $data['level'] = $parent ? ((int) $parent->level + 1) : 0;

        return $this->success(ItemCategory::create($data)->fresh(), 201);
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $category = ItemCategory::query()->findOrFail($category);
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('organization_id', $orgId)],
            'is_active' => ['boolean'],
        ]);

        $parent = ! empty($data['parent_id']) ? ItemCategory::query()->findOrFail($data['parent_id']) : null;
        if ($parent && (int) $parent->id === (int) $category->id) {
            return $this->error('invalid_category_parent', 'A category cannot be its own parent.', 422);
        }
        for ($node = $parent; $node; $node = $node->parent) {
            if ((int) $node->id === (int) $category->id) {
                return $this->error('invalid_category_parent', 'A category cannot be moved under one of its children.', 422);
            }
        }

        $category->fill([
            'name' => $data['name'],
            'parent_id' => $parent?->id,
            'level' => $parent ? ((int) $parent->level + 1) : 0,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ])->save();
        $this->refreshCategoryChildLevels($category);

        return $this->success($category->fresh(['children:id,parent_id,name,level,is_active']));
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        return $this->success(ItemBrand::create($data)->fresh(), 201);
    }

    public function updateBrand(Request $request, int $brand): JsonResponse
    {
        $brand = ItemBrand::query()->findOrFail($brand);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'is_active' => ['boolean'],
        ]);

        $brand->fill([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $brand->is_active,
        ])->save();

        return $this->success($brand->fresh());
    }

    private function refreshCategoryChildLevels(ItemCategory $category): void
    {
        $children = ItemCategory::query()->where('parent_id', $category->id)->get();
        foreach ($children as $child) {
            $child->forceFill(['level' => ((int) $category->level + 1)])->save();
            $this->refreshCategoryChildLevels($child);
        }
    }

    public function storeAdjustmentReasonCode(Request $request): JsonResponse
    {
        $orgId = $this->context->idOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $code = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['code']), '_'));
        if ($code === '') {
            return $this->error('invalid_reason_code', 'Reason code must contain at least one letter or number.', 422);
        }

        $settings = InventorySetting::query()->firstOrCreate(['organization_id' => $orgId]);
        $codes = collect($settings->adjustment_reason_codes ?? [])
            ->reject(fn ($row) => ($row['code'] ?? null) === $code)
            ->values()
            ->all();

        $codes[] = [
            'code' => $code,
            'label' => $data['label'] ?: str_replace('_', ' ', ucfirst($code)),
            'active' => true,
        ];

        $settings->forceFill(['adjustment_reason_codes' => $codes])->save();

        return $this->success($settings->fresh(), 201);
    }

    public function storeCurrencyRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency_code' => ['required', 'string', 'size:3'],
            'rate_to_base' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date'],
        ]);

        $rate = InventoryCurrencyRate::query()->updateOrCreate(
            [
                'currency_code' => strtoupper($data['currency_code']),
                'effective_date' => $data['effective_date'],
            ],
            ['rate_to_base' => $data['rate_to_base']],
        );

        return $this->success($rate->fresh(), 201);
    }

    public function storeUnitConversion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'from_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'to_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'factor' => ['required', 'numeric', 'gt:0'],
        ]);

        if ((int) $data['from_unit_id'] === (int) $data['to_unit_id']) {
            return $this->error('same_unit_conversion', 'From and to units must be different.', 422);
        }

        $conversion = UnitConversion::query()->updateOrCreate(
            [
                'item_id' => $data['item_id'] ?? null,
                'from_unit_id' => $data['from_unit_id'],
                'to_unit_id' => $data['to_unit_id'],
            ],
            ['factor' => $data['factor']]
        );

        return $this->success($conversion->fresh(['item:id,sku,name', 'fromUnit:id,code,name,symbol', 'toUnit:id,code,name,symbol']), 201);
    }

    public function storeWarehouseReorderRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        Item::query()->whereKey($data['item_id'])->where('item_type', 'inventory')->firstOrFail();
        Warehouse::query()->whereKey($data['warehouse_id'])->firstOrFail();

        $rule = WarehouseReorderRule::query()->updateOrCreate(
            [
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
            ],
            [
                'reorder_point' => $data['reorder_point'] ?? null,
                'reorder_qty' => $data['reorder_qty'] ?? null,
                'min_stock' => $data['min_stock'] ?? null,
                'max_stock' => $data['max_stock'] ?? null,
                'safety_stock' => $data['safety_stock'] ?? null,
            ],
        );

        return $this->success($rule->fresh(['item:id,sku,name', 'warehouse:id,code,name']), 201);
    }

    public function calculateWarehouseReorderRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'lookback_days' => ['nullable', 'integer', 'min:7', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'service_factor' => ['nullable', 'numeric', 'min:0', 'max:3'],
        ]);

        Item::query()->whereKey($data['item_id'])->where('item_type', 'inventory')->firstOrFail();
        Warehouse::query()->whereKey($data['warehouse_id'])->firstOrFail();

        return $this->success($this->safetyStock->calculate(
            (int) $data['item_id'],
            (int) $data['warehouse_id'],
            (int) ($data['lookback_days'] ?? 30),
            (int) ($data['lead_time_days'] ?? 14),
            (float) ($data['service_factor'] ?? 1.65),
        ));
    }
}
