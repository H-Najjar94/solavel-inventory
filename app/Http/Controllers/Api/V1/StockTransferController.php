<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransfer;
use App\Services\Documents\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StockTransferController extends ApiController
{
    use \App\Http\Controllers\Api\Concerns\ResolvesTraceOverrides;

    public function __construct(private StockTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = StockTransfer::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id');
        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(StockTransfer $stock_transfer): JsonResponse
    {
        $stock_transfer->load('lines');
        $ledger = StockLedger::query()->where('source_type', StockTransfer::class)->where('source_id', $stock_transfer->id)->get();
        return $this->success(['transfer' => $stock_transfer, 'ledger' => $ledger]);
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        try { $t = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']); }
        catch (RuntimeException $e) { return $this->error('transfer_create_failed', $e->getMessage(), 422); }
        return $this->success($t, 201);
    }

    public function update(StoreStockTransferRequest $request, StockTransfer $stock_transfer): JsonResponse
    {
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
        try { $t = $this->service->post($stock_transfer, $this->resolveTraceOverrides($request)); }
        catch (RuntimeException $e) { return $this->error('transfer_post_failed', $e->getMessage(), 422); }

        return $this->success($t->fresh('lines'));
    }

    /** Available qty for an item at a warehouse (from the balances projection). */
    public function available(Request $request): JsonResponse
    {
        $request->validate(['item_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer']]);
        $avail = StockBalance::query()
            ->where('item_id', (int) $request->query('item_id'))
            ->where('warehouse_id', (int) $request->query('warehouse_id'))
            ->sum('on_hand_qty');

        return $this->success(['available_qty' => (string) $avail]);
    }
}
