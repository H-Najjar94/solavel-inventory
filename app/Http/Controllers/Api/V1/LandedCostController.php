<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\LandedCost;
use App\Models\Tenant\LandedCostLine;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\LandedCostService;
use App\Services\Integration\LandedCostWorkflow;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Landed costs (freight, duty, insurance) on posted receipts. Valuation writes go through LandedCostService. */
class LandedCostController extends ApiController
{
    public function __construct(
        private LandedCostService $service,
        private WarehouseAccessService $warehouseAccess,
        private OrganizationContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = LandedCost::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('landed_cost_number', 'like', '%'.$request->query('search').'%')
                ->orWhere('supplier_reference', 'like', '%'.$request->query('search').'%')))
            ->orderByDesc('id');
        $this->scopeToWarehouses($query);

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(LandedCost $landedCost): JsonResponse
    {
        $this->assertDocumentAllowed($landedCost);
        $landedCost->load(['charges', 'lines.item:id,name,sku', 'lines.warehouse:id,name,code', 'lines.receipt:id,grn_number,receipt_date', 'components', 'reversal']);
        $events = IntegrationOutboxEvent::query()
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('aggregate_type', 'LandedCost')->where('aggregate_id', $landedCost->id))
                ->orWhere(fn ($w) => $w->where('aggregate_type', 'InventoryReversal')->where('aggregate_id', (int) $landedCost->reversal_id)->where('event_type', 'landed_cost.reversed')))
            ->orderBy('id')->get(['id', 'event_uuid', 'event_type', 'status', 'aggregate_number', 'occurred_at']);

        return $this->success([
            'landed_cost' => $landedCost,
            'preview' => $landedCost->status === 'draft' ? $this->service->preview($landedCost) : null,
            'accounting_events' => $events,
            'connection' => app(LandedCostWorkflow::class)->status($this->context->idOrFail()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertReceiptLinesAllowed($data['receipt_line_ids']);
        $doc = $this->service->createDraft($data, $data['charges'], $data['receipt_line_ids']);

        return $this->success($doc, 201);
    }

    public function update(Request $request, LandedCost $landedCost): JsonResponse
    {
        $this->assertDocumentAllowed($landedCost);
        $data = $this->validated($request);
        $this->assertReceiptLinesAllowed($data['receipt_line_ids']);

        return $this->success($this->service->updateDraft($landedCost, $data, $data['charges'], $data['receipt_line_ids']));
    }

    public function post(LandedCost $landedCost): JsonResponse
    {
        $this->assertDocumentAllowed($landedCost);
        try {
            $doc = $this->service->post($landedCost);
        } catch (RuntimeException $e) {
            return $this->error('landed_cost_post_failed', $e->getMessage(), 422);
        }

        return $this->success($doc);
    }

    public function reverse(Request $request, LandedCost $landedCost): JsonResponse
    {
        $this->assertDocumentAllowed($landedCost);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $reversal = $this->service->reverse($landedCost, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->error('landed_cost_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success(['landed_cost' => $landedCost->fresh(['charges', 'lines', 'reversal']), 'reversal' => $reversal]);
    }

    /** Posted, unreversed receipts the user can see, with their lines (newest first). */
    public function receiptLines(Request $request): JsonResponse
    {
        $receipts = GoodsReceipt::query()->with(['lines.item:id,name,sku,weight,costing_method', 'warehouse:id,name,code', 'supplier:id,name'])
            ->where('status', 'posted')->whereNull('reversal_id')
            ->when($request->filled('search'), fn ($q) => $q->where('grn_number', 'like', '%'.$request->query('search').'%'))
            ->orderByDesc('id')->limit(min((int) $request->query('limit', 30), 100))->get();

        return $this->success(['receipts' => $receipts->map(fn (GoodsReceipt $r) => [
            'id' => $r->id, 'grn_number' => $r->grn_number, 'receipt_date' => $r->receipt_date?->toDateString(),
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse?->name, 'supplier_name' => $r->supplier?->name,
            'lines' => $r->lines->filter(fn ($l) => (float) $l->accepted_qty > 0)->values()->map(fn ($l) => [
                'id' => $l->id, 'item_id' => $l->item_id, 'item_name' => $l->item?->name, 'item_sku' => $l->item?->sku,
                'accepted_qty' => (string) $l->accepted_qty, 'unit_cost' => (string) $l->unit_cost,
                'weight' => $l->item?->weight !== null ? (string) $l->item->weight : null,
            ]),
        ])->values()]);
    }

    public function connection(): JsonResponse
    {
        return $this->success(app(LandedCostWorkflow::class)->status($this->context->idOrFail()));
    }

    public function enableConnection(Request $request): JsonResponse
    {
        $data = $request->validate(['finance_account_id' => ['required', 'integer', 'min:1']]);

        return $this->success(app(LandedCostWorkflow::class)->enable($this->context->idOrFail(), (int) $data['finance_account_id'], (int) auth()->id()));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'landed_cost_date' => ['nullable', 'date_format:Y-m-d'],
            'allocation_method' => ['required', Rule::in(LandedCostService::METHODS)],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'charges' => ['required', 'array', 'min:1', 'max:20'],
            'charges.*.charge_type' => ['required', Rule::in(LandedCostService::CHARGE_TYPES)],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
            'receipt_line_ids' => ['required', 'array', 'min:1', 'max:200'],
            'receipt_line_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
    }

    private function assertReceiptLinesAllowed(array $lineIds): void
    {
        $receiptIds = \App\Models\Tenant\GoodsReceiptLine::query()->whereIn('id', $lineIds)->distinct()->pluck('goods_receipt_id');
        $warehouses = GoodsReceipt::query()->withoutGlobalScope('warehouse_access')->whereIn('id', $receiptIds)->distinct()->pluck('warehouse_id');
        foreach ($warehouses as $warehouseId) {
            $this->warehouseAccess->assertAllowed((int) $warehouseId);
        }
    }

    private function assertDocumentAllowed(LandedCost $doc): void
    {
        foreach (LandedCostLine::query()->where('landed_cost_id', $doc->id)->distinct()->pluck('warehouse_id') as $warehouseId) {
            $this->warehouseAccess->assertAllowed((int) $warehouseId);
        }
    }

    /** A document is listed only when every warehouse it touches is visible to the user. */
    private function scopeToWarehouses($query): void
    {
        $allowed = $this->warehouseAccess->allowedIds();
        if ($allowed === null) {
            return;
        }
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_landed_cost_lines as l')
            ->whereColumn('l.landed_cost_id', 'stock_landed_costs.id')->whereNotIn('l.warehouse_id', $allowed ?: [0]));
    }
}
