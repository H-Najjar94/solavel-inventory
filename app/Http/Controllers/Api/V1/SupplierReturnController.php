<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{GoodsReceipt, StockLedger, SupplierReturn};
use App\Services\Access\{InventoryPermissionService, WarehouseAccessService};
use App\Services\Documents\SupplierReturnService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

/** Interactive adapter: physical supplier returns use the native document service, never GRN reversal. */
final class SupplierReturnController extends ApiController
{
    public function __construct(private SupplierReturnService $service, private WarehouseAccessService $warehouses) {}

    private function actor(Request $request): void
    {
        abort_unless($request->user() && app(InventoryPermissionService::class)->can($request->user(), 'inventory.manage_returns'), 403);
    }

    public function prepare(Request $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $this->actor($request);
        $this->warehouses->assertAllowed((int) $goods_receipt->warehouse_id);
        abort_unless($goods_receipt->status === 'posted' && !$goods_receipt->reversed_at && $goods_receipt->supplier_id, 422);
        $goods_receipt->load('lines.item', 'lines.enteredUnit');
        $rows = StockLedger::query()->where('source_type', GoodsReceipt::class)->where('source_id', $goods_receipt->id)->where('direction', 'in')->get();
        $lines = [];
        foreach ($rows as $row) {
            $source = $goods_receipt->lines->firstWhere('id', $row->source_line_id);
            if (!$source) continue;
            $this->warehouses->assertAllowed((int) $row->warehouse_id);
            $returned = DB::connection('tenant')->table('supplier_return_lines as l')->join('supplier_returns as r', 'r.id', '=', 'l.supplier_return_id')
                ->where('l.organization_id', $goods_receipt->organization_id)->where('l.source_stock_ledger_id', $row->id)
                ->where('r.status', 'posted')->whereNull('r.reversed_at')->sum('l.quantity');
            $factor = (string) $source->unit_conversion_factor;
            if (!is_numeric($factor) || !\App\Services\Stock\Support\Decimal::gt($factor, '0')) continue;
            $remaining = \App\Services\Stock\Support\Decimal::sub((string) $row->quantity, (string) $returned);
            $lines[] = ['goods_receipt_line_id'=>$source->id, 'source_stock_ledger_id'=>$row->id,
                'item_name'=>$source->item?->name, 'unit'=>$source->enteredUnit?->code,
                'warehouse_id'=>$row->warehouse_id, 'lot_id'=>$row->lot_id, 'serial_id'=>$row->serial_id, 'bin_id'=>$row->bin_id,
                'remaining_entered_qty'=>\App\Services\Stock\Support\Decimal::div($remaining, $factor)];
        }
        $drafts = SupplierReturn::query()->where('goods_receipt_id', $goods_receipt->id)->orderByDesc('id')->get(['id','return_number','status','created_by']);
        return $this->success(['lines'=>$lines, 'returns'=>$drafts]);
    }

    public function store(Request $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $this->actor($request);
        $data = $request->validate(['request_uuid'=>'required|uuid', 'return_date'=>'required|date', 'reason'=>'required|string|min:3|max:2000',
            'lines'=>'required|array|min:1', 'lines.*.goods_receipt_line_id'=>'required|integer|min:1',
            'lines.*.source_stock_ledger_id'=>'required|integer|min:1|distinct', 'lines.*.entered_qty'=>['required','regex:/^\d+(?:\.\d{1,4})?$/','numeric','gt:0']]);
        $this->warehouses->assertAllowed((int) $goods_receipt->warehouse_id);
        $return = DB::connection('tenant')->transaction(function () use ($goods_receipt, $data, $request) {
            GoodsReceipt::query()->whereKey($goods_receipt->id)->lockForUpdate()->firstOrFail();
            $existing = SupplierReturn::query()->where('return_uuid', $data['request_uuid'])->with('lines')->first();
            if ($existing) {
                abort_unless((int)$existing->goods_receipt_id === (int)$goods_receipt->id && (int)$existing->created_by === (int)$request->user()->id, 409);
                abort_unless($existing->return_date->format('Y-m-d') === $data['return_date'] && $existing->reason === $data['reason'] && $existing->lines->count() === count($data['lines']), 409);
                foreach ($data['lines'] as $input) {
                    $line = $existing->lines->firstWhere('source_stock_ledger_id', (int)$input['source_stock_ledger_id']);
                    abort_unless($line && (int)$line->goods_receipt_line_id === (int)$input['goods_receipt_line_id'] && \App\Services\Stock\Support\Decimal::cmp((string)$line->entered_qty, (string)$input['entered_qty']) === 0, 409);
                }
                // A token resumes this exact document; never overwrite a previously accepted draft.
                return $existing;
            }
            $return = $this->service->createDraft(['goods_receipt_id'=>$goods_receipt->id, 'return_date'=>$data['return_date'], 'reason'=>$data['reason']], $data['lines']);
            $return->update(['return_uuid'=>$data['request_uuid']]);
            return $return;
        });
        return $this->success($return, 201);
    }

    public function post(Request $request, SupplierReturn $supplier_return): JsonResponse
    {
        $this->actor($request);
        $this->warehouses->assertAllowed((int)$supplier_return->warehouse_id);
        foreach ($supplier_return->lines as $line) $this->warehouses->assertAllowed((int)$line->warehouse_id);
        return $this->success($this->service->post($supplier_return));
    }
}
