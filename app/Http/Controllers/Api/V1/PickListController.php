<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\PickList;
use App\Models\Tenant\SalesOrder;
use App\Services\Documents\PickListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PickListController extends ApiController
{
    public function __construct(private PickListService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = PickList::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', (int) $request->query('sales_order_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(PickList $pick_list): JsonResponse
    {
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
        try {
            $pl = $this->service->createFromSalesOrder($so, collect($data)->except('sales_order_id')->toArray());
        } catch (RuntimeException $e) {
            return $this->error('pick_list_create_failed', $e->getMessage(), 422);
        }

        return $this->success($pl, 201);
    }

    public function update(Request $request, PickList $pick_list): JsonResponse
    {
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
        try { $pl = $this->service->markPicked($pick_list); }
        catch (RuntimeException $e) { return $this->error('pick_finalize_failed', $e->getMessage(), 422); }

        return $this->success($pl);
    }
}
