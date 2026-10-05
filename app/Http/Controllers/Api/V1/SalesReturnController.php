<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreSalesReturnRequest;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Access\WarehouseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SalesReturnController extends ApiController
{
    public function __construct(
        private SalesReturnService $service,
        private InventoryReversalService $reversals,
        private WarehouseAccessService $warehouseAccess,
    ) {}

    /** A return is visible/actionable only when its header and every line warehouse are assigned. */
    private function assertReturnScope(SalesReturn $return): void
    {
        $this->warehouseAccess->assertAllowed((int) $return->warehouse_id);
        foreach ($return->lines()->whereNotNull('warehouse_id')->distinct()->pluck('warehouse_id') as $warehouseId) {
            $this->warehouseAccess->assertAllowed((int) $warehouseId);
        }
    }

    private function assertPayloadScope(array $data): void
    {
        $this->warehouseAccess->assertAllowed((int) $data['warehouse_id']);
        if (! empty($data['shipment_id']) && ($shipment = \App\Models\Tenant\Shipment::query()->find((int) $data['shipment_id']))) {
            $this->warehouseAccess->assertAllowed((int) $shipment->warehouse_id);
        }
        foreach ($data['lines'] ?? [] as $line) {
            if (! empty($line['warehouse_id'])) {
                $this->warehouseAccess->assertAllowed((int) $line['warehouse_id']);
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = SalesReturn::query()
            ->with(['customer:id,code,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('shipment_id'), fn ($q) => $q->where('shipment_id', (int) $request->query('shipment_id')))
            ->orderByDesc('id');
        $this->warehouseAccess->scope($query);
        $allowed = $this->warehouseAccess->allowedIds();
        if ($allowed !== null) {
            $query->whereDoesntHave('lines', fn ($lines) => $lines->whereNotNull('warehouse_id')->whereNotIn('warehouse_id', $allowed));
        }

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (SalesReturn $return) {
            $return->setAttribute('customer_name', $return->customer?->name ?? $return->customer_name);

            return $return;
        }));
    }

    public function show(SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        $sales_return->load(['lines.item:id,name,sku', 'customer:id,code,name,contact', 'shipment:id,shipment_number,reversed_at']);
        $sales_return->setAttribute('customer_name', $sales_return->customer?->name ?? $sales_return->customer_name);
        $ledger = StockLedger::query()
            ->where('source_type', SalesReturn::class)->where('source_id', $sales_return->id)->get();

        return $this->success(['sales_return' => $sales_return, 'ledger' => $ledger]);
    }

    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $this->assertPayloadScope($data);
            $return = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('sales_return_create_failed', $e->getMessage(), 422);
        }

        return $this->success($return, 201);
    }

    public function update(StoreSalesReturnRequest $request, SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        try {
            $data = $request->validated();
            $this->assertPayloadScope($data);
            $updated = $this->service->updateDraft($sales_return, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('sales_return_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        try {
            $posted = $this->service->post($sales_return);
        } catch (RuntimeException $e) {
            return $this->error('sales_return_post_failed', $e->getMessage(), 422);
        }

        return $this->success($posted->fresh('lines'));
    }

    public function authorizeReturn(SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        try {
            $return = $this->service->authorizeReturn($sales_return);
        } catch (RuntimeException $e) {
            return $this->error('sales_return_authorize_failed', $e->getMessage(), 422);
        }

        return $this->success($return);
    }

    public function inspect(Request $request, SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        try {
            $return = $this->service->inspect($sales_return, $request->input('notes'));
        } catch (RuntimeException $e) {
            return $this->error('sales_return_inspect_failed', $e->getMessage(), 422);
        }

        return $this->success($return);
    }

    public function cancel(SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        return $this->success($this->service->cancel($sales_return));
    }

    public function reverse(Request $request, SalesReturn $sales_return): JsonResponse
    {
        $this->assertReturnScope($sales_return);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        return $this->success($this->reversals->reverseSalesReturn($sales_return, $input['reason']));
    }
}
