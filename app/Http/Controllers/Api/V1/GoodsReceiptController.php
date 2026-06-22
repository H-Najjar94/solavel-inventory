<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\StoreGoodsReceiptRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GoodsReceiptController extends ApiController
{
    public function __construct(private GoodsReceiptService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $query = GoodsReceipt::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('purchase_order_id'), fn ($q) => $q->where('purchase_order_id', (int) $request->query('purchase_order_id')))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($perPage)->withQueryString());
    }

    public function show(GoodsReceipt $goods_receipt): JsonResponse
    {
        $goods_receipt->load('lines');
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
    public function fromPo(PurchaseOrder $purchase_order): JsonResponse
    {
        if (! in_array($purchase_order->status, ['approved', 'partially_received'], true)) {
            return $this->error('po_not_receivable', 'Only an approved/partially-received PO can be received.', 422);
        }
        $purchase_order->load('lines');

        $lines = $purchase_order->lines->map(function ($l) {
            $remaining = Decimal::qty(Decimal::sub((string) $l->ordered_qty, (string) $l->received_qty));

            return [
                'purchase_order_line_id' => $l->id,
                'item_id' => $l->item_id,
                'variant_id' => $l->variant_id,
                'ordered_qty' => $l->ordered_qty,
                'already_received_qty' => $l->received_qty,
                'remaining_qty' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
                'received_qty' => Decimal::lt($remaining, '0') ? '0.0000' : $remaining,
                'unit_cost' => $l->unit_price,
            ];
        })->filter(fn ($l) => Decimal::gt((string) $l['remaining_qty'], '0'))->values();

        return $this->success([
            'purchase_order' => $purchase_order->only(['id', 'po_number', 'supplier_id', 'warehouse_id']),
            'lines' => $lines,
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();
        $grn = $this->service->createDraft(collect($data)->except('lines')->toArray(), $data['lines']);

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
        try { $grn = $this->service->post($goods_receipt); }
        catch (RuntimeException $e) { return $this->error('grn_post_failed', $e->getMessage(), 422); }

        return $this->success($grn->fresh('lines'));
    }
}
