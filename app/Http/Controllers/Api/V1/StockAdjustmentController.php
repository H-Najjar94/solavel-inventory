<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Concerns\PresentsLineTraceability;
use App\Http\Controllers\Api\Concerns\ResolvesTraceOverrides;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\InventoryAuditLog;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\InventoryReversalService;
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
    use ResolvesTraceOverrides, PresentsLineTraceability;

    public function __construct(
        private StockAdjustmentService $service,
        private InventoryReversalService $reversals,
        private WarehouseAccessService $warehouseAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = StockAdjustment::query()
            ->with(['warehouse:id,name,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('adjustment_number', 'like', '%'.$request->query('search').'%'))
            ->orderByDesc('id');
        $this->warehouseAccess->scope($query);

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (StockAdjustment $adjustment) {
            app(\App\Services\Presentation\AdjustmentValuation::class)->apply([$adjustment]);
            $adjustment->setAttribute('warehouse_name', $adjustment->warehouse?->name);
            $adjustment->setAttribute('warehouse_code', $adjustment->warehouse?->code);

            return $adjustment;
        }));
    }

    public function show(StockAdjustment $adjustment): JsonResponse
    {
        $this->warehouseAccess->assertAllowed((int) $adjustment->warehouse_id);
        // Eager-load names (org-scoped) so the detail page shows names, not raw #ids.
        $adjustment->load(['lines.item:id,name,sku', 'lines.bin:id,code', 'warehouse:id,name,code', 'reversal']);
        app(\App\Services\Presentation\AdjustmentValuation::class)->apply([$adjustment]);
        $adjustment->setAttribute('warehouse_name', $adjustment->warehouse?->name);
        $ledger = StockLedger::query()
            ->with(['item:id,name,sku', 'warehouse:id,name,code'])
            ->where('source_type', StockAdjustment::class)
            ->where('source_id', $adjustment->id)
            ->get()
            ->map(function (StockLedger $row) {
                $row->setAttribute('item_name', $row->item?->name);
                $row->setAttribute('item_sku', $row->item?->sku);
                $row->setAttribute('warehouse_name', $row->warehouse?->name);
                $row->setAttribute('warehouse_code', $row->warehouse?->code);

                return $row;
            });

        $adjustment->lines->each(function ($line) use ($ledger, $adjustment) {
            if (in_array($adjustment->status, ['posted', 'reversed'], true)) {
                $rows = $ledger->where('source_line_id', $line->id);
                $quantity = $rows->reduce(fn ($sum, $row) => \App\Services\Stock\Support\Decimal::add($sum, (string) $row->quantity), '0');
                if (!\App\Services\Stock\Support\Decimal::isZero($quantity)) {
                    $cost = $rows->reduce(fn ($sum, $row) => \App\Services\Stock\Support\Decimal::add($sum, (string) $row->total_cost), '0');
                    $line->setAttribute('unit_cost', \App\Services\Stock\Support\Decimal::cost(\App\Services\Stock\Support\Decimal::div($cost, $quantity)));
                }
            }
            $line->setAttribute('bin_code', $line->bin?->code);
        });

        $this->attachLineTraceability($adjustment->lines);

        // Read only this document's events (and its own reversal), under the
        // same organization and warehouse scope as the authorized detail.
        $audit = InventoryAuditLog::query()->where(function ($query) use ($adjustment) {
            $query->where(function ($original) use ($adjustment) {
                $original->where('entity_type', 'stock_adjustment')->where('entity_id', $adjustment->id);
            });
            if ($adjustment->reversal_id) {
                $query->orWhere(function ($reversal) use ($adjustment) {
                    $reversal->where('entity_type', 'inventory_reversal')->where('entity_id', $adjustment->reversal_id);
                });
            }
        })->orderBy('created_at')->orderBy('id')->get([
            'id', 'created_at', 'actor_user_id', 'action', 'document_ref', 'before', 'after',
        ]);

        return $this->success(['adjustment' => $adjustment, 'ledger' => $ledger, 'audit' => $audit]);
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->warehouseAccess->assertAllowed((int) $data['warehouse_id']);
        unset($data['adjustment_number']);
        try {
            $adj = $this->service->createDraft(
                collect($data)->except('lines')->toArray(),
                $data['lines']
            );
        } catch (RuntimeException $e) {
            // e.g. an increase line with no cost and no cost history (SurplusCostResolver).
            return $this->error('adjustment_create_failed', $e->getMessage(), 422);
        }

        return $this->success($adj, 201);
    }

    public function update(StoreStockAdjustmentRequest $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->warehouseAccess->assertAllowed((int) $adjustment->warehouse_id);
        try {
            $data = $request->validated();
            $this->warehouseAccess->assertAllowed((int) $data['warehouse_id']);
            $updated = $this->service->updateDraft($adjustment, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('adjustment_update_failed', $e->getMessage(), 422);
        }

        return $this->success($updated);
    }

    public function post(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->warehouseAccess->assertAllowed((int) $adjustment->warehouse_id);
        try {
            $adjustment = $this->service->post($adjustment, $this->resolveTraceOverrides($request));
        } catch (RuntimeException $e) {
            return $this->error('adjustment_post_failed', $e->getMessage(), 422);
        }

        return $this->success($adjustment->fresh('lines'));
    }

    public function reverse(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->warehouseAccess->assertAllowed((int) $adjustment->warehouse_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $reversal = $this->reversals->reverseNegativeAdjustment($adjustment, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->error('adjustment_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success([
            'adjustment' => $adjustment->fresh(['lines', 'reversal']),
            'reversal' => $reversal,
        ]);
    }
}
