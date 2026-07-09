<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderBackorder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\WarehouseReorderRule;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\OpeningStockService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class PurchasingReorderCompletionTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function approved_po_creates_explicit_backorders_without_moving_stock(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'POBO']);
        $item = F::averageItem(['sku' => 'PO-BO-ITEM']);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-BO-001',
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'ordered_qty' => '10.0000',
            'received_qty' => '0.0000',
            'unit_price' => '7.0000',
        ]);

        app(PurchaseOrderController::class)->approve($po);

        $backorder = PurchaseOrderBackorder::query()->where('purchase_order_line_id', $line->id)->firstOrFail();
        $this->assertSame('open', $backorder->status);
        $this->assertSame('10.0000', (string) $backorder->backorder_qty);
        $this->assertSame(0, StockLedger::query()->where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->count());

        $detail = app(PurchaseOrderController::class)->show($po->fresh())->getData(true)['data'];
        $this->assertSame('10.0000', $detail['open_backorder_qty']);
        $this->assertSame('10.0000', $detail['lines'][0]['backorder_qty']);
    }

    #[Test]
    public function posted_grn_reduces_and_closes_po_backorders_through_the_canonical_stock_path(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'POGRN']);
        $item = F::fifoItem(['sku' => 'PO-GRN-ITEM']);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-BO-002',
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'status' => 'approved',
        ]);
        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'ordered_qty' => '10.0000',
            'received_qty' => '0.0000',
            'unit_price' => '4.0000',
        ]);
        app(\App\Services\Purchasing\PurchaseOrderBackorderService::class)->refresh($po);

        $partial = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'GRN-BO-PART',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
        ], [[
            'purchase_order_line_id' => $line->id,
            'item_id' => $item->id,
            'received_qty' => '6',
            'accepted_qty' => '6',
            'unit_cost' => '4',
        ]]);
        app(GoodsReceiptService::class)->post($partial);

        $backorder = PurchaseOrderBackorder::query()->where('purchase_order_line_id', $line->id)->firstOrFail();
        $this->assertSame('open', $backorder->status);
        $this->assertSame('4.0000', (string) $backorder->backorder_qty);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(1, StockLedger::query()->where('source_type', GoodsReceipt::class)->where('source_id', $partial->id)->count());
        $this->assertSame('6.0000', (string) CostLayer::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail()->remaining_qty);

        $final = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'GRN-BO-FINAL',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
        ], [[
            'purchase_order_line_id' => $line->id,
            'item_id' => $item->id,
            'received_qty' => '4',
            'accepted_qty' => '4',
            'unit_cost' => '4',
        ]]);
        app(GoodsReceiptService::class)->post($final);

        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('closed', $backorder->fresh()->status);
        $this->assertSame('0.0000', (string) $backorder->fresh()->backorder_qty);
        $this->assertSame(2, StockLedger::query()->where('source_type', GoodsReceipt::class)->whereIn('source_id', [$partial->id, $final->id])->count());
    }

    #[Test]
    public function safety_stock_calculation_uses_outbound_ledger_demand_and_feeds_reorder_rules(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'SAFE']);
        $item = F::averageItem(['sku' => 'SAFE-STOCK']);

        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft([
            'entry_number' => 'OS-SAFE-STOCK',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'quantity' => '100',
            'unit_cost' => '2',
        ]]));

        app(StockLedgerService::class)->post([
            new StockMovement('out', $item->id, $warehouse->id, '8', self::class, 1),
            new StockMovement('out', $item->id, $warehouse->id, '12', self::class, 2),
        ], 'safety-stock:test');

        $calculation = app(SettingsController::class)->calculateWarehouseReorderRule(Request::create('/settings/warehouse-reorder-rules/calculate', 'POST', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'lookback_days' => 7,
            'lead_time_days' => 5,
            'service_factor' => 1.65,
        ]))->getData(true)['data'];

        $this->assertSame('20.0000', $calculation['total_demand']);
        $this->assertGreaterThan(0, (float) $calculation['calculated_safety_stock']);
        $this->assertGreaterThan(0, (float) $calculation['calculated_reorder_point']);

        app(SettingsController::class)->storeWarehouseReorderRule(Request::create('/settings/warehouse-reorder-rules', 'POST', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'reorder_point' => $calculation['calculated_reorder_point'],
            'reorder_qty' => $calculation['suggested_reorder_qty'],
            'safety_stock' => $calculation['calculated_safety_stock'],
        ]));

        $rule = WarehouseReorderRule::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame($calculation['calculated_safety_stock'], (string) $rule->safety_stock);
    }
}
