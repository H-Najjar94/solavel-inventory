<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreShipmentRequest;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shipment;
use App\Services\Documents\ShipmentService;
use App\Services\Shipping\CarrierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ShipmentController extends ApiController
{
    use \App\Http\Controllers\Api\Concerns\ResolvesTraceOverrides;

    public function __construct(private ShipmentService $service, private CarrierService $carriers) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = Shipment::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('sales_order_id'), fn ($q) => $q->where('sales_order_id', (int) $request->query('sales_order_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(Shipment $shipment): JsonResponse
    {
        $shipment->load('lines');
        $ledger = \App\Models\Tenant\StockLedger::query()
            ->where('source_type', Shipment::class)->where('source_id', $shipment->id)->get();

        return $this->success(['shipment' => $shipment, 'ledger' => $ledger]);
    }

    public function rates(Shipment $shipment): JsonResponse
    {
        return $this->success(['rates' => $this->carriers->rates($shipment)]);
    }

    public function label(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'service_code' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->success(['label' => $this->carriers->generateLabel($shipment, $data['service_code'] ?? null)]);
    }

    public function tracking(Shipment $shipment): JsonResponse
    {
        return $this->success(['tracking' => $this->carriers->tracking($shipment)]);
    }

    /** Build a draft shipment payload from a sales order's outstanding lines. */
    public function fromSalesOrder(SalesOrder $sales_order): JsonResponse
    {
        return $this->success([
            'sales_order' => $sales_order->only(['id', 'order_number', 'warehouse_id', 'customer_name']),
            'lines' => $this->service->fromSalesOrder($sales_order),
        ]);
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $shipment = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

        return $this->success($shipment, 201);
    }

    public function update(StoreShipmentRequest $request, Shipment $shipment): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($shipment, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('shipment_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(Request $request, Shipment $shipment): JsonResponse
    {
        try { $posted = $this->service->post($shipment, $this->resolveTraceOverrides($request)); }
        catch (RuntimeException $e) { return $this->error('shipment_post_failed', $e->getMessage(), 422); }

        return $this->success($posted->fresh('lines'));
    }
}
