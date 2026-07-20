<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\ResolvesTraceOverrides;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransfer;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferController extends ApiController
{
    use ResolvesTraceOverrides;

    public function __construct(
        private StockTransferService $service,
        private WarehouseAccessService $warehouseAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = StockTransfer::query()
            ->with(['fromWarehouse:id,name,code', 'toWarehouse:id,name,code'])
            ->tap(fn ($q) => $this->warehouseAccess->scopeTransfer($q))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (StockTransfer $transfer) {
            $transfer->setAttribute('from_warehouse_name', $transfer->fromWarehouse?->name);
            $transfer->setAttribute('from_warehouse_code', $transfer->fromWarehouse?->code);
            $transfer->setAttribute('to_warehouse_name', $transfer->toWarehouse?->name);
            $transfer->setAttribute('to_warehouse_code', $transfer->toWarehouse?->code);

            return $transfer;
        }));
    }

    public function show(StockTransfer $stock_transfer): JsonResponse
    {
        $this->warehouseAccess->assertTransferAllowed((int) $stock_transfer->from_warehouse_id, (int) $stock_transfer->to_warehouse_id);
        // Eager-load names (org-scoped) so the detail page shows names, not raw #ids.
        $stock_transfer->load(['lines.item:id,name,sku', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code']);
        $stock_transfer->setAttribute('from_warehouse_name', $stock_transfer->fromWarehouse?->name);
        $stock_transfer->setAttribute('to_warehouse_name', $stock_transfer->toWarehouse?->name);
        $ledger = StockLedger::query()->where('source_type', StockTransfer::class)->where('source_id', $stock_transfer->id)->get();

        return $this->success(['transfer' => $stock_transfer, 'ledger' => $ledger]);
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['transfer_number']);
        try {
            $t = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('transfer_create_failed', $e->getMessage(), 422);
        }

        return $this->success($t, 201);
    }

    public function update(StoreStockTransferRequest $request, StockTransfer $stock_transfer): JsonResponse
    {
        $this->warehouseAccess->assertTransferAllowed((int) $stock_transfer->from_warehouse_id, (int) $stock_transfer->to_warehouse_id);
        try {
            $data = $request->validated();
            $t = $this->service->updateDraft($stock_transfer, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('transfer_update_failed', $e->getMessage(), 422);
        }

        return $this->success($t);
    }

    public function post(Request $request, StockTransfer $stock_transfer): JsonResponse
    {
        $this->warehouseAccess->assertTransferAllowed((int) $stock_transfer->from_warehouse_id, (int) $stock_transfer->to_warehouse_id);
        try {
            $t = $this->service->post($stock_transfer, $this->resolveTraceOverrides($request));
        } catch (RuntimeException $e) {
            return $this->error('transfer_post_failed', $e->getMessage(), 422);
        }

        return $this->success($t->fresh('lines'));
    }

    public function ship(Request $request, StockTransfer $stock_transfer): JsonResponse
    {
        $this->warehouseAccess->assertTransferAllowed((int) $stock_transfer->from_warehouse_id, (int) $stock_transfer->to_warehouse_id);
        try {
            $t = $this->service->ship($stock_transfer, $this->resolveTraceOverrides($request));
        } catch (RuntimeException $e) {
            return $this->error('transfer_ship_failed', $e->getMessage(), 422);
        }

        return $this->success($t->fresh('lines'));
    }

    public function receive(StockTransfer $stock_transfer): JsonResponse
    {
        $this->warehouseAccess->assertTransferAllowed((int) $stock_transfer->from_warehouse_id, (int) $stock_transfer->to_warehouse_id);
        try {
            $t = $this->service->receive($stock_transfer);
        } catch (RuntimeException $e) {
            return $this->error('transfer_receive_failed', $e->getMessage(), 422);
        }

        return $this->success($t->fresh('lines'));
    }

    /** Available qty for an item at a warehouse (from the balances projection). */
    public function available(Request $request): JsonResponse
    {
        $request->validate(['item_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer']]);
        $avail = StockBalance::query()
            ->leftJoin('warehouse_bins as bin', 'stock_balances.bin_id', '=', 'bin.id')
            ->leftJoin('lots as lot', 'stock_balances.lot_id', '=', 'lot.id')
            ->where('stock_balances.item_id', (int) $request->query('item_id'))
            ->where('stock_balances.warehouse_id', (int) $request->query('warehouse_id'))
            ->where(function ($q) {
                $q->whereNull('bin.id')
                    ->orWhereNotIn('bin.coords->bin_type', ['quarantine', 'damaged']);
            })
            ->where(function ($q) {
                $q->whereNull('lot.id')
                    ->orWhereNotIn('lot.status', ['quarantined', 'recalled'])
                    ->where(function ($expiry) {
                        $expiry->whereNull('lot.expiry_date')->orWhere('lot.expiry_date', '>=', now()->toDateString());
                    });
            })
            ->sum(DB::raw('stock_balances.on_hand_qty - stock_balances.reserved_qty'));

        return $this->success(['available_qty' => (string) $avail]);
    }
}
