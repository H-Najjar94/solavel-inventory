<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\PurchaseOrder;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Orders. POs never move stock (GRN does). Thin controller; totals are
 * computed from lines. Received quantities roll up from posted GRN lines.
 */
class PurchaseOrderController extends ApiController
{
    public function __construct(private OrganizationContext $context) {}

    private function conn(): string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = PurchaseOrder::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->query('supplier_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(PurchaseOrder $purchase_order): JsonResponse
    {
        $purchase_order->load('lines');

        // Remaining per line = ordered − received (received_qty maintained by GRN posting).
        $lines = $purchase_order->lines->map(function ($l) {
            $remaining = Decimal::qty(Decimal::sub((string) $l->ordered_qty, (string) $l->received_qty));

            return array_merge($l->toArray(), [
                'remaining_qty' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
            ]);
        });

        // Linked GRNs (any that reference this PO).
        $grns = GoodsReceipt::query()->where('purchase_order_id', $purchase_order->id)
            ->orderByDesc('id')->get(['id', 'grn_number', 'status', 'receipt_date']);

        return $this->success([
            'purchase_order' => $purchase_order,
            'lines' => $lines,
            'linked_grns' => $grns,
            'has_remaining' => $lines->contains(fn ($l) => Decimal::gt((string) $l['remaining_qty'], '0')),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = $this->context->idOrFail();
        $po = DB::connection($this->conn())->transaction(function () use ($data, $orgId) {
            // Server-issued PO number when none was supplied (users don't type it).
            $poNumber = ! empty($data['po_number']) ? $data['po_number']
                : \App\Services\Documents\Support\DocumentNumber::next('PO', PurchaseOrder::class, 'po_number', $orgId, $this->conn());

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
            $this->audit('purchase_order.created', $po);

            return $po->fresh('lines');
        });

        return $this->success($po, 201);
    }

    public function update(StorePurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        if ($purchase_order->status !== 'draft') {
            return $this->error('po_not_draft', 'Only a draft PO can be edited.', 422);
        }
        $data = $request->validated();
        $po = DB::connection($this->conn())->transaction(function () use ($data, $purchase_order) {
            $purchase_order->update(collect($data)->except('lines')->toArray());
            $purchase_order->lines()->delete();
            $this->syncLines($purchase_order, $data['lines']);
            $this->audit('purchase_order.updated', $purchase_order);

            return $purchase_order->fresh('lines');
        });

        return $this->success($po);
    }

    public function approve(PurchaseOrder $purchase_order): JsonResponse
    {
        if ($purchase_order->status !== 'draft') {
            return $this->error('po_not_draft', 'Only a draft PO can be approved.', 422);
        }
        $purchase_order->update(['status' => 'approved']);
        $this->audit('purchase_order.approved', $purchase_order);

        return $this->success($purchase_order->fresh());
    }

    public function cancel(PurchaseOrder $purchase_order): JsonResponse
    {
        if (in_array($purchase_order->status, ['received', 'cancelled'], true)) {
            return $this->error('po_not_cancellable', "A {$purchase_order->status} PO cannot be cancelled.", 422);
        }
        $purchase_order->update(['status' => 'cancelled']);
        $this->audit('purchase_order.cancelled', $purchase_order);

        return $this->success($purchase_order->fresh());
    }

    /** Build/refresh lines and recompute totals (no stock impact). */
    private function syncLines(PurchaseOrder $po, array $lines): void
    {
        $orgId = $this->context->idOrFail();
        $subtotal = '0';
        foreach ($lines as $line) {
            $subtotal = Decimal::add($subtotal, Decimal::mul((string) $line['ordered_qty'], (string) ($line['unit_price'] ?? '0')));
            $po->lines()->create([
                'organization_id' => $orgId,
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'ordered_qty' => Decimal::qty((string) $line['ordered_qty']),
                'unit_price' => Decimal::cost((string) ($line['unit_price'] ?? '0')),
                'tax_code' => $line['tax_code'] ?? null,
                'expected_date' => $line['expected_date'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
        $po->subtotal = Decimal::money($subtotal);
        $po->total = Decimal::money($subtotal); // tax/discount placeholder
        $po->save();
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
