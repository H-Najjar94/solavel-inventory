<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Stock Adjustments. All stock writes delegated to StockAdjustmentService →
 * StockLedgerService. Controller never touches stock tables directly.
 */
class StockAdjustmentController extends ApiController
{
    use \App\Http\Controllers\Api\Concerns\ResolvesTraceOverrides;

    public function __construct(private StockAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = StockAdjustment::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(StockAdjustment $adjustment): JsonResponse
    {
        $adjustment->load('lines');
        $ledger = StockLedger::query()
            ->where('source_type', StockAdjustment::class)
            ->where('source_id', $adjustment->id)->get();

        return $this->success(['adjustment' => $adjustment, 'ledger' => $ledger]);
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $adj = $this->service->createDraft(
            collect($data)->except('lines')->toArray(),
            $data['lines']
        );

        return $this->success($adj, 201);
    }

    public function update(StoreStockAdjustmentRequest $request, StockAdjustment $adjustment): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($adjustment, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('adjustment_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        try {
            $adjustment = $this->service->post($adjustment, $this->resolveTraceOverrides($request));
        } catch (RuntimeException $e) {
            return $this->error('adjustment_post_failed', $e->getMessage(), 422);
        }

        return $this->success($adjustment->fresh('lines'));
    }

    public function reverse(StockAdjustment $adjustment): JsonResponse
    {
        try {
            $adjustment = $this->service->reverse($adjustment);
        } catch (RuntimeException $e) {
            return $this->error('adjustment_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success($adjustment->fresh('lines'));
    }
}
