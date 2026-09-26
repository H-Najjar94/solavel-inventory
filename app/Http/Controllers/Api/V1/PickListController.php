<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\PickList;
use App\Models\Tenant\SalesOrder;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\PickListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PickListController extends ApiController
{
    public function __construct(private PickListService $service, private WarehouseAccessService $warehouseAccess) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = PickList::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', (int) $request->query('sales_order_id')))
            ->orderByDesc('id');
        $this->warehouseAccess->scope($query);
        $allowed = $this->warehouseAccess->allowedIds();
        if ($allowed !== null) {
            $query->whereIn('sales_order_id', SalesOrder::query()->select('id')->whereIn('warehouse_id', $allowed));
            $query->whereDoesntHave('lines', fn ($lines) => $lines->whereNotNull('warehouse_id')->whereNotIn('warehouse_id', $allowed));
        }

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(PickList $pick_list): JsonResponse
    {
        $this->assertPickListScope($pick_list);
        return $this->success(['pick_list' => $pick_list->load('lines')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sales_order_id' => ['required','integer'],
            'pick_number' => ['required','string','max:50'],
            'warehouse_id' => ['nullable','integer'],
            'notes' => ['nullable','string'],
        ]);
        $so = SalesOrder::query()->findOrFail($data['sales_order_id']);
        $this->warehouseAccess->assertAllowed((int) $so->warehouse_id);
        if (! empty($data['warehouse_id'])) {
            $this->warehouseAccess->assertAllowed((int) $data['warehouse_id']);
        }
        foreach ($so->lines()->get(['warehouse_id']) as $line) {
            $this->warehouseAccess->assertAllowed((int) ($line->warehouse_id ?: $so->warehouse_id));
        }
        try {
            $pl = $this->service->createFromSalesOrder($so, collect($data)->except('sales_order_id')->toArray());
        } catch (RuntimeException $e) {
            return $this->error('pick_list_create_failed', $e->getMessage(), 422);
        }

        return $this->success($pl, 201);
    }

    public function update(Request $request, PickList $pick_list): JsonResponse
    {
        $this->assertPickListScope($pick_list);
        $data = $request->validate(['picks' => ['required','array']]);
        try {
            $pl = $this->service->updatePicks($pick_list, $data['picks']);
        } catch (RuntimeException $e) {
            return $this->error('pick_update_failed', $e->getMessage(), 422);
        }

        return $this->success($pl);
    }

    public function markPicked(PickList $pick_list): JsonResponse
    {
        $this->assertPickListScope($pick_list);
        try { $pl = $this->service->markPicked($pick_list); }
        catch (RuntimeException $e) { return $this->error('pick_finalize_failed', $e->getMessage(), 422); }

        return $this->success($pl);
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
