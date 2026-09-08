<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{Unit,ItemCategory,InventorySetting};
use App\Services\Reports\{InventoryReportService,ReportFilters};
use Illuminate\Http\Request;

final class FinanceWorkspaceSupportController extends ApiController
{
    public function warehouse(Request $request, \App\Models\Tenant\Warehouse $warehouse)
    {
        app(\App\Services\Access\WarehouseAccessService::class)->assertAllowed((int) $warehouse->id);
        if ($request->boolean('reference_only')) return $this->success(['warehouse' => $warehouse->only(['id', 'name', 'code'])]);
        // The Finance selector and operational forms need location references, not
        // every balance, image and movement in the native warehouse detail page.
        return $this->success([
            'warehouse' => $warehouse,
            'zones' => \App\Models\Tenant\WarehouseZone::query()->where('warehouse_id', $warehouse->id)->orderBy('id')->get(),
            'bins' => \App\Models\Tenant\WarehouseBin::query()->where('warehouse_id', $warehouse->id)->orderBy('id')->get(),
        ]);
    }

    public function lot(Request $request, \App\Models\Tenant\Lot $lot)
    {
        app(\App\Services\Access\WarehouseAccessService::class)->assertLotAllowed((int) $lot->id);
        return $this->trace($request, $lot, 'lot', 'lot_id');
    }

    public function serial(Request $request, \App\Models\Tenant\SerialNumber $serial)
    {
        app(\App\Services\Access\WarehouseAccessService::class)->assertSerialAllowed((int) $serial->id);
        return $this->trace($request, $serial, 'serial', 'serial_id');
    }

    private function trace(Request $request, $record, string $key, string $column)
    {
        $access = app(\App\Services\Access\WarehouseAccessService::class);
        $query = \App\Models\Tenant\StockLedger::query()->with(['item:id,name,sku','warehouse:id,name,code'])->where($column, $record->id)->orderByDesc('moved_at')->orderByDesc('id');
        $access->scope($query);
        $page = $query->paginate(25);
        $page->setCollection(\App\Services\Documents\SourceDocumentPresenter::decorateRows($page->getCollection()));
        $rows = $page->getCollection()->map(fn ($row) => StockLedgerController::movementRow($row))->values();
        return $this->success([$key => $record->load('item:id,name,sku'), 'movements' => $rows,
            'movements_meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function dashboard(Request $request, \App\Services\Access\WarehouseAccessService $access)
    {
        $warehouse = $request->integer('warehouse_id') ?: null;
        if ($warehouse) $access->assertAllowed($warehouse);
        $scope = function ($query) use ($warehouse, $access) { $access->scope($query); if ($warehouse) $query->where('warehouse_id', $warehouse); return $query; };
        $balances = $scope(\App\Models\Tenant\StockBalance::query());
        $counts = $scope(\App\Models\Tenant\StockCount::query());
        $transfers = \App\Models\Tenant\StockTransfer::query();
        $access->scopeTransfer($transfers);
        if ($warehouse) $transfers->where(fn ($q) => $q->where('from_warehouse_id', $warehouse)->orWhere('to_warehouse_id', $warehouse));
        return $this->success([
            'inventory_value' => (string) (clone $balances)->sum('total_value'),
            'active_items' => (clone $balances)->distinct()->count('item_id'),
            'out_of_stock' => (clone $balances)->whereRaw('(on_hand_qty - reserved_qty) <= 0')->count(),
            'movements_today' => $scope(\App\Models\Tenant\StockLedger::query())->whereDate('moved_at', now()->toDateString())->count(),
            'pending_transfers' => $transfers->whereIn('status', ['draft','in_transit'])->count(),
            'pending_counts' => $counts->whereIn('status', ['draft','counting','review'])->count(),
        ]);
    }
    public function rules(Request $request, \App\Services\Access\WarehouseAccessService $access)
    {
        $query = \App\Models\Tenant\WarehouseReorderRule::query()->with(['item:id,name,sku','warehouse:id,name'])->orderBy('id');
        $access->scope($query);
        if ($request->filled('warehouse_id')) { $access->assertAllowed($request->integer('warehouse_id')); $query->where('warehouse_id', $request->integer('warehouse_id')); }
        return $this->paginated($query->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }
    public function showRule(\App\Models\Tenant\WarehouseReorderRule $rule)
    {
        return $this->success($rule->load(['item:id,name,sku','warehouse:id,name']));
    }
    public function updateRule(Request $request, \App\Models\Tenant\WarehouseReorderRule $rule)
    {
        $data = $request->validate(collect(['reorder_point','reorder_qty','min_stock','max_stock','safety_stock'])->mapWithKeys(fn ($key) => [$key => 'nullable|numeric|min:0'])->all());
        $rule->update($data);
        return $this->success($rule->fresh());
    }
    public function lookups(Request $request)
    {
        return $this->success([
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id','name','symbol']),
            'categories' => ItemCategory::query()->where('is_active', true)->orderBy('name')->limit(500)->get(['id','name']),
            'adjustment_reason_codes' => InventorySetting::query()->first()?->adjustment_reason_codes ?? [],
        ]);
    }
    public function reorder(Request $request, InventoryReportService $reports)
    {
        return $this->success($reports->run('low-stock', ReportFilters::fromRequest($request)));
    }
}
