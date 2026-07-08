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
            ->with(['warehouse:id,name,code', 'customer:id,code,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', '%'.$request->query('q').'%')
                ->orWhere('customer_name', 'like', '%'.$request->query('q').'%')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (SalesOrder $order) {
            $order->setAttribute('warehouse_name', $order->warehouse?->name);
            $order->setAttribute('warehouse_code', $order->warehouse?->code);
            $order->setAttribute('customer_name', $order->customer?->name ?? $order->customer_name);
            return $order;
        }));
    }

    public function show(SalesOrder $sales_order): JsonResponse
    {
        $sales_order = $this->service->expireOverdueReservations($sales_order);
        // Eager-load names (org-scoped) so the detail page shows names, not raw #ids.
        // customer_name is already a denormalized string on the header (no customer table).
        $sales_order->load(['lines.item:id,name,sku', 'warehouse:id,name,code', 'customer:id,code,name,contact', 'reservations.item:id,name,sku', 'reservations.warehouse:id,name,code']);
        $sales_order->setAttribute('warehouse_name', $sales_order->warehouse?->name);
        $sales_order->setAttribute('customer_name', $sales_order->customer?->name ?? $sales_order->customer_name);

        return $this->success(['sales_order' => $sales_order]);
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['order_number']);
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

    public function reserve(Request $request, SalesOrder $sales_order): JsonResponse
    {
        $data = $request->validate([
            'expires_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        try { $so = $this->service->reserve($sales_order, $data); }
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
