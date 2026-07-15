<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreWarehouseRequest;
use App\Http\Requests\Api\UpdateWarehouseRequest;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Services\Documents\SourceDocumentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
    use \App\Http\Controllers\Concerns\EnforcesInventoryLimits;

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Warehouse::query()
            ->with('primaryImage:id,warehouse_id,is_primary')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name');

        return $this->paginated(
            $query->paginate($perPage)->withQueryString()->through(function ($w) {
                $w->setAttribute('primary_image_url',
                    $w->primaryImage ? "/inventory/api/v1/warehouse-images/{$w->primaryImage->id}" : null);
                $w->unsetRelation('primaryImage');

                return $w;
            })
        );
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $zones = WarehouseZone::query()->where('warehouse_id', $warehouse->id)->get();
        $bins = WarehouseBin::query()->where('warehouse_id', $warehouse->id)->get();
        $balances = StockBalance::query()->with(['item:id,name,sku'])->where('warehouse_id', $warehouse->id)->get()
            ->map(function (StockBalance $balance) {
                $balance->setAttribute('item_name', $balance->item?->name);
                $balance->setAttribute('item_sku', $balance->item?->sku);
                return $balance;
            });
        $lowStock = $balances->filter(fn ($b) => (float) $b->available_qty <= 0)->count();
        $recent = StockLedger::query()->with(['item:id,name,sku', 'warehouse:id,name,code'])->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id')->limit(20)->get();
        $recent = SourceDocumentPresenter::decorateRows($recent)
            ->map(fn (StockLedger $row) => StockLedgerController::movementRow($row))
            ->values();

        // Private, org-scoped serve URLs (never public file URLs).
        $images = $warehouse->images()->orderByDesc('is_primary')->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($img) => [
                'id' => $img->id,
                'is_primary' => (bool) $img->is_primary,
                'url' => "/inventory/api/v1/warehouse-images/{$img->id}",
            ])->all();
        $primary = collect($images)->firstWhere('is_primary', true);

        return $this->success([
            'warehouse' => $warehouse,
            'zones' => $zones,
            'bins' => $bins,
            'stock' => $balances,
            'low_stock_count' => $lowStock,
            'recent_movements' => $recent,
            'images' => $images,
            'primary_image_url' => $primary['url'] ?? null,
        ]);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        // Grandfathered warehouse ceiling: block only new warehouses past the
        // plan limit. Existing warehouses stay fully usable — clients 2 & 18 are
        // already over the Free cap of 1 and must not be locked out of theirs.
        $this->enforceLimit('stock.max_warehouses', Warehouse::query()->count());

        return $this->success(Warehouse::create($request->validated())->fresh(), 201);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse->update($request->validated());

        return $this->success($warehouse->fresh());
    }
}
