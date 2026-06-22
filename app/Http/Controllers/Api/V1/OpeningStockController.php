<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreOpeningStockRequest;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\OpeningStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Opening Stock documents. All stock writes are delegated to OpeningStockService
 * → StockLedgerService. This controller NEVER touches stock tables directly.
 */
class OpeningStockController extends ApiController
{
    public function __construct(private OpeningStockService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = OpeningStockEntry::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(OpeningStockEntry $entry): JsonResponse
    {
        $entry->load('lines');
        $ledger = StockLedger::query()
            ->where('source_type', OpeningStockEntry::class)
            ->where('source_id', $entry->id)->get();

        return $this->success(['entry' => $entry, 'ledger' => $ledger]);
    }

    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        $data = $request->validated();
        $entry = $this->service->createDraft(
            collect($data)->except('lines')->toArray(),
            $data['lines']
        );

        return $this->success($entry, 201);
    }

    public function update(StoreOpeningStockRequest $request, OpeningStockEntry $entry): JsonResponse
    {
        try {
            $data = $request->validated();
            $updated = $this->service->updateDraft($entry, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(OpeningStockEntry $entry): JsonResponse
    {
        try {
            $entry = $this->service->post($entry);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_post_failed', $e->getMessage(), 422);
        }

        return $this->success($entry->fresh('lines'));
    }

    public function reverse(OpeningStockEntry $entry): JsonResponse
    {
        try {
            $entry = $this->service->reverse($entry);
        } catch (RuntimeException $e) {
            return $this->error('opening_stock_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success($entry->fresh('lines'));
    }
}
