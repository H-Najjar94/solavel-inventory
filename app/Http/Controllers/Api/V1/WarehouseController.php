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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
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
        $balances = StockBalance::query()->where('warehouse_id', $warehouse->id)->get();
        $lowStock = $balances->filter(fn ($b) => (float) $b->available_qty <= 0)->count();
        $recent = StockLedger::query()->where('warehouse_id', $warehouse->id)
            ->orderByDesc('id')->limit(20)->get();

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
        return $this->success(Warehouse::create($request->validated())->fresh(), 201);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse->update($request->validated());

        return $this->success($warehouse->fresh());
    }
}
