<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Lot;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockTransferService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class WarehouseOperationsCompletionTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function blind_receiving_hides_ordered_quantity_but_keeps_po_line_binding(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'BLIND']);
        $item = F::averageItem(['sku' => 'BLIND-ITEM']);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-BLIND',
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'status' => 'approved',
        ]);
        $line = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'ordered_qty' => '12.0000',
            'received_qty' => '0.0000',
            'unit_price' => '3.0000',
        ]);

        $response = app(GoodsReceiptController::class)
            ->fromPo(Request::create('/grn-draft', 'GET', ['blind' => 1]), $po)
            ->getData(true)['data'];

        $this->assertTrue($response['blind']);
        $this->assertSame($line->id, $response['lines'][0]['purchase_order_line_id']);
        $this->assertArrayNotHasKey('ordered_qty', $response['lines'][0]);
        $this->assertArrayNotHasKey('remaining_qty', $response['lines'][0]);
    }

    #[Test]
    public function qc_quarantine_posts_stock_but_excludes_it_from_sellable_availability(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'QCWH']);
        $zone = WarehouseZone::query()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'QC',
            'name' => 'Quality',
        ]);
        $bin = WarehouseBin::query()->create([
            'warehouse_id' => $warehouse->id,
            'zone_id' => $zone->id,
            'code' => 'Q-01',
            'name' => 'Quarantine 01',
            'coords' => ['bin_type' => 'quarantine'],
            'is_active' => true,
        ]);
        $item = F::lotItem(['sku' => 'QC-LOT']);

        $grn = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'GRN-QC',
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'blind_receiving' => true,
        ], [[
            'item_id' => $item->id,
            'received_qty' => '10',
            'accepted_qty' => '8',
            'rejected_qty' => '2',
            'inspection_status' => 'quarantine',
            'disposition' => 'quarantine',
            'quarantine_qty' => '8',
            'unit_cost' => '5',
            'bin_id' => $bin->id,
            'lot_code' => 'QC-LOT-001',
        ]]);
        app(GoodsReceiptService::class)->post($grn);

        $this->assertSame('posted', $grn->fresh()->status);
        $this->assertSame('quarantine', $grn->fresh()->inspection_status);
        $this->assertSame('quarantined', Lot::query()->where('lot_code', 'QC-LOT-001')->firstOrFail()->status);
        $this->assertSame('8.0000', StockBalance::query()->where('item_id', $item->id)->where('bin_id', $bin->id)->firstOrFail()->on_hand_qty);
        $this->assertSame(1, StockLedger::query()->where('source_type', GoodsReceipt::class)->where('source_id', $grn->id)->count());

        $rows = app(StockBalanceController::class)->index(Request::create('/balances', 'GET', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
        ]))->getData(true)['data'];

        $this->assertSame('quarantined', $rows[0]['availability_status']);
        $this->assertSame('0.0000', $rows[0]['sellable_available_qty']);
        $this->assertSame('8.0000', $rows[0]['quarantine_qty']);
    }

    #[Test]
    public function transfer_ship_and_receive_preserves_fifo_cost_layers(): void
    {
        $this->useTenantA();
        $source = F::warehouse(['code' => 'SRC2']);
        $dest = F::warehouse(['code' => 'DST2']);
        $item = F::fifoItem(['sku' => 'TRF-FIFO']);

        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft([
            'entry_number' => 'OS-TRF-FIFO',
            'warehouse_id' => $source->id,
        ], [[
            'item_id' => $item->id,
            'quantity' => '10',
            'unit_cost' => '4',
        ]]));

        $transfer = app(StockTransferService::class)->createDraft([
            'transfer_number' => 'TRF-INTRANSIT',
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $source->id,
            'to_warehouse_id' => $dest->id,
        ], [[
            'item_id' => $item->id,
            'quantity' => '5',
        ]]);

        app(StockTransferService::class)->ship($transfer);
        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame('5.0000', StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $source->id)->firstOrFail()->on_hand_qty);
        $this->assertNull(StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $dest->id)->first());

        app(StockTransferService::class)->receive($transfer->fresh());
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('5.0000', StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $dest->id)->firstOrFail()->on_hand_qty);
        $this->assertSame('5.0000', CostLayer::query()->where('item_id', $item->id)->where('warehouse_id', $dest->id)->firstOrFail()->remaining_qty);
        $this->assertSame(2, StockLedger::query()->where('source_id', $transfer->id)->where('source_type', \App\Models\Tenant\StockTransfer::class)->count());
    }
}
