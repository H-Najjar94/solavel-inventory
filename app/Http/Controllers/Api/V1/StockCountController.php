<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreStockCountRequest;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCount;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StockCountController extends ApiController
{
    public function __construct(private StockCountService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = StockCount::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(StockCount $stock_count): JsonResponse
    {
        $stock_count->load('lines');

        // Generated adjustment + its ledger (variance posting goes through one adj).
        $adjustment = $stock_count->adjustment_id
            ? StockAdjustment::query()->find($stock_count->adjustment_id, ['id', 'adjustment_number', 'status'])
            : null;
        $ledger = $adjustment
            ? StockLedger::query()->where('source_type', StockAdjustment::class)->where('source_id', $adjustment->id)->get()
            : collect();

        return $this->success(['count' => $stock_count, 'adjustment' => $adjustment, 'ledger' => $ledger]);
    }

    /**
     * Prefill expected quantities for a scope (warehouse, optionally bin) from the
     * balances projection. Returns suggested count lines the client edits.
     */
    public function prefill(Request $request): JsonResponse
    {
        $request->validate(['warehouse_id' => ['required', 'integer']]);
        $warehouseId = (int) $request->query('warehouse_id');
        $rows = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->when($request->filled('bin_id'), fn ($q) => $q->where('bin_id', (int) $request->query('bin_id')))
            ->get();

        // Lot metadata for the prefilled lot rows (code + expiry for the UI).
        $lotIds = $rows->pluck('lot_id')->filter()->unique()->values();
        $lots = \App\Models\Tenant\Lot::query()->whereIn('id', $lotIds)->get(['id', 'lot_code', 'expiry_date', 'status'])->keyBy('id');

        // Expected serials currently in stock at this warehouse, grouped by item,
        // so a serial-tracked count line can present its expected serial list.
        $serials = \App\Models\Tenant\SerialNumber::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['available', 'in_stock'])
            ->get(['id', 'item_id', 'serial', 'lot_id'])
            ->groupBy('item_id');

        $lines = $rows->map(fn ($b) => [
            'item_id' => $b->item_id,
            'variant_id' => $b->variant_id,
            'bin_id' => $b->bin_id,
            'lot_id' => $b->lot_id,
            'lot_code' => $b->lot_id ? ($lots[$b->lot_id]->lot_code ?? null) : null,
            'expiry_date' => $b->lot_id ? ($lots[$b->lot_id]->expiry_date ?? null) : null,
            'system_qty' => $b->on_hand_qty,
            'expected_serials' => ($serials[$b->item_id] ?? collect())->map(fn ($s) => ['id' => $s->id, 'serial' => $s->serial])->values(),
            'counted_qty' => '',
        ])->values();

        return $this->success(['lines' => $lines]);
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

        return $this->success($count, 201);
    }

    public function update(StoreStockCountRequest $request, StockCount $stock_count): JsonResponse
    {
        try {
            $data = $request->validated();
            $count = $this->service->updateDraft($stock_count, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('count_update_failed', $e->getMessage(), 422);
        }

        return $this->success($count);
    }

    public function post(StockCount $stock_count): JsonResponse
    {
        try { $count = $this->service->post($stock_count); }
        catch (RuntimeException $e) { return $this->error('count_post_failed', $e->getMessage(), 422); }

        return $this->success($count->fresh('lines'));
    }
}
