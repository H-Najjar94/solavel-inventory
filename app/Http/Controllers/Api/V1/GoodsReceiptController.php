<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreGoodsReceiptRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockLedger;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GoodsReceiptController extends ApiController
{
    public function __construct(
        private GoodsReceiptService $service,
        private InventoryReversalService $reversals,
        private WarehouseAccessService $warehouseAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = GoodsReceipt::query()
            ->with(['warehouse:id,name,code', 'supplier:id,name,code', 'purchaseOrder:id,po_number'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('purchase_order_id'), fn ($q) => $q->where('purchase_order_id', (int) $request->query('purchase_order_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString()->through(function (GoodsReceipt $grn) {
            $grn->setAttribute('warehouse_name', $grn->warehouse?->name);
            $grn->setAttribute('warehouse_code', $grn->warehouse?->code);
            $grn->setAttribute('supplier_name', $grn->supplier?->name);
            $grn->setAttribute('supplier_code', $grn->supplier?->code);
            $grn->setAttribute('purchase_order_number', $grn->purchaseOrder?->po_number);

            return $grn;
        }));
    }

    public function show(GoodsReceipt $goods_receipt): JsonResponse
    {
        // Eager-load names (org-scoped) so the detail page shows names, not raw #ids.
        $goods_receipt->load(['lines.item:id,name,sku', 'lines.enteredUnit:id,code,name,symbol', 'warehouse:id,name,code', 'supplier:id,name,code', 'reversal']);
        $goods_receipt->setAttribute('warehouse_name', $goods_receipt->warehouse?->name);
        $goods_receipt->setAttribute('supplier_name', $goods_receipt->supplier?->name);
        $ledger = StockLedger::query()->where('source_type', GoodsReceipt::class)->where('source_id', $goods_receipt->id)->get();
        $po = $goods_receipt->purchase_order_id
            ? PurchaseOrder::query()->find($goods_receipt->purchase_order_id, ['id', 'po_number', 'status'])
            : null;

        return $this->success(['grn' => $goods_receipt, 'ledger' => $ledger, 'purchase_order' => $po]);
    }

    /**
     * Prepare a GRN draft payload from an approved PO: one suggested line per PO
     * line with remaining qty (ordered − received) and the PO unit cost. Read-only;
     * the client edits then POSTs to store.
     */
    public function fromPo(Request $request, PurchaseOrder $purchase_order): JsonResponse
    {
        if (! in_array($purchase_order->status, ['approved', 'partially_received'], true)) {
            return $this->error('po_not_receivable', 'Only an approved/partially-received PO can be received.', 422);
        }
        $purchase_order->load('lines');

        $blind = $request->boolean('blind');
        $lines = $purchase_order->lines->map(function ($l) use ($blind) {
            $remaining = Decimal::qty(Decimal::sub((string) $l->ordered_qty, (string) $l->received_qty));
            if (! Decimal::gt($remaining, '0')) {
                return null;
            }

            $line = [
                'purchase_order_line_id' => $l->id,
                'item_id' => $l->item_id,
                'variant_id' => $l->variant_id,
                'received_qty' => $blind ? '' : (Decimal::lt($remaining, '0') ? '0.0000' : $remaining),
                'unit_cost' => $l->unit_price,
            ];

            if (! $blind) {
                $line += [
                    'ordered_qty' => $l->ordered_qty,
                    'already_received_qty' => $l->received_qty,
                    'remaining_qty' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
                ];
            }

            return $line;
        })->filter()->values();

        return $this->success([
            'purchase_order' => $purchase_order->only(['id', 'po_number', 'supplier_id', 'warehouse_id']),
            'blind' => $blind,
            'lines' => $lines,
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            unset($data['grn_number']);
            $grn = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('grn_create_failed', $e->getMessage(), 422);
        }

        return $this->success($grn, 201);
    }

    public function update(StoreGoodsReceiptRequest $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        try {
            $data = $request->validated();
            $grn = $this->service->updateDraft($goods_receipt, collect($data)->except('lines')->toArray(), $data['lines']);
        } catch (RuntimeException $e) {
            return $this->error('grn_update_failed', $e->getMessage(), 422);
        }

        return $this->success($grn);
    }

    public function post(GoodsReceipt $goods_receipt): JsonResponse
    {
        try {
            $grn = $this->service->post($goods_receipt);
        } catch (RuntimeException $e) {
            return $this->error('grn_post_failed', $e->getMessage(), 422);
        }

        return $this->success($grn->fresh('lines'));
    }

    public function reverse(Request $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $this->warehouseAccess->assertAllowed((int) $goods_receipt->warehouse_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $reversal = $this->reversals->reverseGoodsReceipt($goods_receipt, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->error('grn_reverse_failed', $e->getMessage(), 422);
        }

        return $this->success([
            'goods_receipt' => $goods_receipt->fresh('reversal'),
            'reversal' => $reversal,
        ]);
    }
}
