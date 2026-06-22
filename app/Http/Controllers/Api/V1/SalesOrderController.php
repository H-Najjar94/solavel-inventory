<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreSalesOrderRequest;
use App\Models\Tenant\SalesOrder;
use App\Services\Documents\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalesOrderController extends ApiController
{
    public function __construct(private SalesOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = SalesOrder::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', '%'.$request->query('q').'%')
                ->orWhere('customer_name', 'like', '%'.$request->query('q').'%')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(SalesOrder $sales_order): JsonResponse
    {
        return $this->success(['sales_order' => $sales_order->load('lines')]);
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $so = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

        return $this->success($so, 201);
    }

    public function update(StoreSalesOrderRequest $request, SalesOrder $sales_order): JsonResponse
    {
        try {
            $data = $request->validated();
            $so = $this->service->updateDraft($sales_order, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('sales_order_update_failed', $e->getMessage(), 422);
        }

        return $this->success($so);
    }

    public function confirm(SalesOrder $sales_order): JsonResponse
    {
        try { $so = $this->service->confirm($sales_order); }
        catch (RuntimeException $e) { return $this->error('sales_order_confirm_failed', $e->getMessage(), 422); }

        return $this->success($so);
    }

    public function reserve(SalesOrder $sales_order): JsonResponse
    {
        try { $so = $this->service->reserve($sales_order); }
        catch (RuntimeException $e) { return $this->error('reservation_failed', $e->getMessage(), 422); }

        return $this->success($so);
    }

    public function releaseReservation(SalesOrder $sales_order): JsonResponse
    {
        try { $so = $this->service->releaseReservation($sales_order); }
        catch (RuntimeException $e) { return $this->error('release_failed', $e->getMessage(), 422); }

        return $this->success($so);
    }

    public function cancel(SalesOrder $sales_order): JsonResponse
    {
        try { $so = $this->service->cancel($sales_order); }
        catch (RuntimeException $e) { return $this->error('sales_order_cancel_failed', $e->getMessage(), 422); }

        return $this->success($so);
    }
}
