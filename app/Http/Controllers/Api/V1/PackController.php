<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\Pack;
use App\Models\Tenant\PickList;
use App\Models\Tenant\SalesOrder;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\PackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PackController extends ApiController
{
    public function __construct(private PackService $service, private WarehouseAccessService $warehouseAccess) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Pack::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', (int) $request->query('sales_order_id')))
            ->orderByDesc('id');
        $allowed = $this->warehouseAccess->allowedIds();
        if ($allowed !== null) {
            $query->whereIn('sales_order_id', SalesOrder::query()->select('id')->whereIn('warehouse_id', $allowed));
            $query->where(function ($packs) use ($allowed) {
                $packs->whereNull('pick_list_id')->orWhereIn('pick_list_id', PickList::query()
                    ->select('id')->whereIn('warehouse_id', $allowed)
                    ->whereDoesntHave('lines', fn ($lines) => $lines->whereNotNull('warehouse_id')->whereNotIn('warehouse_id', $allowed)));
            });
        }

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Pack $pack): JsonResponse
    {
        $this->assertPackScope($pack);
        return $this->success(['pack' => $pack->load('lines')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pick_list_id' => ['required','integer'],
            'pack_number' => ['required','string','max:50'],
            'package_count' => ['nullable','integer','min:1'],
            'carrier' => ['nullable','string','max:100'],
            'tracking_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);
        $pl = PickList::query()->findOrFail($data['pick_list_id']);
        $this->assertPickListScope($pl);
        try {
            $pack = $this->service->createFromPickList($pl, collect($data)->except('pick_list_id')->toArray());
        } catch (RuntimeException $e) {
            return $this->error('pack_create_failed', $e->getMessage(), 422);
        }

        return $this->success($pack, 201);
    }

    public function update(Request $request, Pack $pack): JsonResponse
    {
        $this->assertPackScope($pack);
        $data = $request->validate([
            'packs' => ['nullable','array'],
            'package_count' => ['nullable','integer','min:1'],
            'package_weight' => ['nullable','numeric','min:0'],
            'carrier' => ['nullable','string','max:100'],
            'tracking_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);
        try {
            $updated = $this->service->updatePacks(
                $pack, $data['packs'] ?? [],
                collect($data)->except('packs')->toArray()
            );
        } catch (RuntimeException $e) {
            return $this->error('pack_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function markPacked(Pack $pack): JsonResponse
    {
        $this->assertPackScope($pack);
        try { $packed = $this->service->markPacked($pack); }
        catch (RuntimeException $e) { return $this->error('pack_finalize_failed', $e->getMessage(), 422); }

        return $this->success($packed);
    }

    private function assertPackScope(Pack $pack): void
    {
        $order = SalesOrder::query()->findOrFail($pack->sales_order_id);
        $this->warehouseAccess->assertAllowed((int) $order->warehouse_id);
        foreach ($order->lines()->distinct()->pluck('warehouse_id') as $warehouseId) {
            $this->warehouseAccess->assertAllowed((int) ($warehouseId ?: $order->warehouse_id));
        }
        if ($pack->pick_list_id) {
            $pickList = PickList::query()->findOrFail($pack->pick_list_id);
            $this->assertPickListScope($pickList);
        }
    }

    private function assertPickListScope(PickList $pickList): void
    {
        $this->warehouseAccess->assertAllowed((int) $pickList->warehouse_id);
        $order = SalesOrder::query()->findOrFail($pickList->sales_order_id);
        $this->warehouseAccess->assertAllowed((int) $order->warehouse_id);
        foreach ($pickList->lines()->distinct()->pluck('warehouse_id') as $warehouseId) {
            $this->warehouseAccess->assertAllowed((int) ($warehouseId ?: $pickList->warehouse_id));
        }
    }
}
