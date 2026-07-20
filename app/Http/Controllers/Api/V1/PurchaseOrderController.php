<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderBackorder;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\Support\DocumentNumber;
use App\Services\Purchasing\PurchaseOrderBackorderService;
use App\Services\Stock\Support\Decimal;
use App\Services\Tax\InventoryTaxService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Purchase Orders. POs never move stock (GRN does). Thin controller; totals are
 * computed from lines. Received quantities roll up from posted GRN lines.
 */
class PurchaseOrderController extends ApiController
{
    public function __construct(
        private OrganizationContext $context,
        private UnitConversionResolver $conversions,
        private PurchaseOrderBackorderService $backorders,
        private InventoryTaxService $taxes,
    ) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = PurchaseOrder::query()
            ->with(['warehouse:id,name,code', 'supplier:id,name,code'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->query('supplier_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (PurchaseOrder $po) {
            $po->setAttribute('warehouse_name', $po->warehouse?->name);
            $po->setAttribute('warehouse_code', $po->warehouse?->code);
            $po->setAttribute('supplier_name', $po->supplier?->name);
            $po->setAttribute('supplier_code', $po->supplier?->code);
            $po->setAttribute('open_backorder_qty', PurchaseOrderBackorder::query()
                ->where('purchase_order_id', $po->id)
                ->where('status', 'open')
                ->sum('backorder_qty'));

            return $po;
        }));
    }

    public function show(PurchaseOrder $purchase_order): JsonResponse
    {
        // Eager-load names (org-scoped by each model's global scope) so the detail
        // page shows names, not raw #ids.
        $purchase_order->load(['lines.item:id,name,sku,base_unit_id', 'lines.enteredUnit:id,code,name,symbol', 'backorders', 'warehouse:id,name,code', 'supplier:id,name,code']);
        $purchase_order->setAttribute('warehouse_name', $purchase_order->warehouse?->name);
        $purchase_order->setAttribute('supplier_name', $purchase_order->supplier?->name);
        $backordersByLine = $purchase_order->backorders->keyBy('purchase_order_line_id');

        // Remaining per line = ordered − received (received_qty maintained by GRN posting).
        $lines = $purchase_order->lines->map(function ($l) use ($backordersByLine) {
            $remaining = Decimal::qty(Decimal::sub((string) $l->ordered_qty, (string) $l->received_qty));
            $backorder = $backordersByLine->get($l->id);

            return array_merge($l->toArray(), [
                'item_name' => $l->item?->name,
                'item_sku' => $l->item?->sku,
                'remaining_qty' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
                'backorder_qty' => $backorder && $backorder->status === 'open' ? (string) $backorder->backorder_qty : '0.0000',
                'backorder_status' => $backorder?->status,
            ]);
        });

        // Linked GRNs (any that reference this PO).
        $grns = GoodsReceipt::query()->where('purchase_order_id', $purchase_order->id)
            ->orderByDesc('id')->get(['id', 'grn_number', 'status', 'receipt_date']);

        return $this->success([
            'purchase_order' => $purchase_order,
            'lines' => $lines,
            'linked_grns' => $grns,
            'backorders' => $purchase_order->backorders->values(),
            'open_backorder_qty' => Decimal::qty((string) $purchase_order->backorders->where('status', 'open')->sum(fn ($b) => (float) $b->backorder_qty)),
            'has_remaining' => $lines->contains(fn ($l) => Decimal::gt((string) $l['remaining_qty'], '0')),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['po_number']);
        $orgId = $this->context->idOrFail();
        try {
            $po = DB::connection($this->conn())->transaction(function () use ($data, $orgId) {
                // Server-issued PO number. Ignore client-submitted numbers on create.
                $poNumber = DocumentNumber::next('PO', PurchaseOrder::class, 'po_number', $orgId, $this->conn());

                $po = PurchaseOrder::create(collect($data)->except('lines')
                    // Drop a null currency_code so the column's DB default (SAR) applies
                    // — it's a NOT NULL column; sending null would fail the insert.
                    ->reject(fn ($v, $k) => $k === 'currency_code' && $v === null)
                    ->merge([
                        'po_number' => $poNumber,
                        'status' => 'draft',
                        'order_date' => $data['order_date'] ?? now()->toDateString(),
                    ])->toArray());
                $this->syncLines($po, $data['lines']);
                $this->backorders->refresh($po->fresh('lines'));
                $this->audit('purchase_order.created', $po);

                return $po->fresh('lines');
            });
        } catch (RuntimeException $e) {
            return $this->error('po_create_failed', $e->getMessage(), 422);
        }

        return $this->success($po, 201);
    }

    public function update(StorePurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        if ($purchase_order->status !== 'draft') {
            return $this->error('po_not_draft', 'Only a draft PO can be edited.', 422);
        }
        $data = $request->validated();
        try {
            $po = DB::connection($this->conn())->transaction(function () use ($data, $purchase_order) {
                $purchase_order->update(collect($data)->except('lines')->toArray());
                $purchase_order->lines()->delete();
                $this->syncLines($purchase_order, $data['lines']);
                $this->backorders->refresh($purchase_order->fresh('lines'));
                $this->audit('purchase_order.updated', $purchase_order);

                return $purchase_order->fresh('lines');
            });
        } catch (RuntimeException $e) {
            return $this->error('po_update_failed', $e->getMessage(), 422);
        }

        return $this->success($po);
    }

    public function approve(PurchaseOrder $purchase_order): JsonResponse
    {
        if ($purchase_order->status !== 'draft') {
            return $this->error('po_not_draft', 'Only a draft PO can be approved.', 422);
        }
        $purchase_order->update(['status' => 'approved']);
        $this->backorders->refresh($purchase_order->fresh('lines'));
        $this->audit('purchase_order.approved', $purchase_order);

        return $this->success($purchase_order->fresh());
    }

    public function cancel(PurchaseOrder $purchase_order): JsonResponse
    {
        if (in_array($purchase_order->status, ['received', 'cancelled'], true)) {
            return $this->error('po_not_cancellable', "A {$purchase_order->status} PO cannot be cancelled.", 422);
        }
        $purchase_order->update(['status' => 'cancelled']);
        $this->backorders->refresh($purchase_order->fresh('lines'));
        $this->audit('purchase_order.cancelled', $purchase_order);

        return $this->success($purchase_order->fresh());
    }

    /** Build/refresh lines and recompute totals (no stock impact). */
    private function syncLines(PurchaseOrder $po, array $lines): void
    {
        $orgId = $this->context->idOrFail();
        $subtotal = '0';
        $taxTotal = '0';
        $items = Item::query()
            ->whereIn('id', collect($lines)->pluck('item_id')->filter()->unique())
            ->get(['id', 'tax_code'])
            ->keyBy('id');
        foreach ($lines as $line) {
            $line = $this->conversions->normalizeLine($line, 'ordered_qty');
            $unitPrice = $this->baseUnitCost((string) ($line['unit_price'] ?? '0'), $line['unit_conversion_factor'] ?? null);
            $lineSubtotal = Decimal::mul((string) $line['ordered_qty'], $unitPrice);
            $item = $items[$line['item_id']] ?? null;
            $tax = $this->taxes->resolve(
                $line['tax_code'] ?? $item?->tax_code,
                isset($line['tax_rate']) ? (string) $line['tax_rate'] : null,
                'purchase',
            );
            $taxAmount = $this->taxes->amount($lineSubtotal, $tax['rate']);
            $lineTotal = Decimal::money(Decimal::add($lineSubtotal, $taxAmount));
            $subtotal = Decimal::add($subtotal, $lineSubtotal);
            $taxTotal = Decimal::add($taxTotal, $taxAmount);
            $lineAttributes = [
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'ordered_qty' => Decimal::qty((string) $line['ordered_qty']),
                'entered_qty' => $line['entered_qty'] ?? null,
                'entered_unit_id' => $line['entered_unit_id'] ?? null,
                'unit_conversion_factor' => $line['unit_conversion_factor'] ?? null,
                'unit_price' => $unitPrice,
                'tax_code' => $tax['code'],
                'expected_date' => $line['expected_date'] ?? null,
                'notes' => $line['notes'] ?? null,
            ];
            if (Schema::connection($this->conn())->hasColumn('purchase_order_lines', 'tax_rate')) {
                $lineAttributes += [
                    'tax_rate' => $tax['rate'],
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ];
            }
            $po->lines()->create($lineAttributes);
        }
        $po->subtotal = Decimal::money($subtotal);
        $po->tax_total = Decimal::money($taxTotal);
        $po->total = Decimal::money(Decimal::add($subtotal, $taxTotal));
        $po->save();
    }

    private function baseUnitCost(string $enteredUnitCost, ?string $factor): string
    {
        if ($factor && Decimal::gt($factor, '0')) {
            return Decimal::cost(Decimal::div($enteredUnitCost, $factor));
        }

        return Decimal::cost($enteredUnitCost);
    }

    private function audit(string $action, PurchaseOrder $po): void
    {
        InventoryAuditLog::create([
            'organization_id' => $this->context->id(),
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'purchase_order',
            'entity_id' => $po->id,
            'after' => ['status' => $po->status, 'total' => $po->total],
            'document_ref' => $po->po_number,
            'created_at' => now(),
        ]);
    }
}
