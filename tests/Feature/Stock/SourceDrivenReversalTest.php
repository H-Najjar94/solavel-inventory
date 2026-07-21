<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Models\Tenant\CostLayer;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class SourceDrivenReversalTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function goods_receipt_reversal_is_separate_idempotent_and_restores_fifo_and_po_state(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-GRN-WH']);
        $item = F::fifoItem(['sku' => 'REV-GRN-ITEM']);
        $receipt = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'REV-GRN-SOURCE',
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
        ], [[
            'item_id' => $item->id,
            'received_qty' => '5',
            'accepted_qty' => '5',
            'unit_cost' => '7.25',
        ]]);
        app(GoodsReceiptService::class)->post($receipt);

        $reversal = app(InventoryReversalService::class)->reverseGoodsReceipt($receipt, 'Supplier shipment rejected');
        $again = app(InventoryReversalService::class)->reverseGoodsReceipt($receipt->fresh(), 'Ignored duplicate');

        $this->assertSame($reversal->id, $again->id);
        $this->assertSame('goods_receipt', $reversal->source_type);
        $this->assertSame($receipt->id, (int) $reversal->source_id);
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame('0.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('remaining_qty'));
        $this->assertSame(1, InventoryReversal::query()->where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->count());
        $this->assertSame(1, StockLedger::query()->where('source_type', InventoryReversal::class)->where('source_id', $reversal->id)->count());
        $this->assertSame($reversal->id, (int) $receipt->fresh()->reversal_id);
    }

    #[Test]
    public function receipt_reversal_rejects_downstream_consumption_without_mutating_stock(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-DOWN-WH']);
        $item = F::fifoItem(['sku' => 'REV-DOWN-ITEM']);
        $receipt = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'REV-DOWN-SOURCE',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'received_qty' => '5',
            'accepted_qty' => '5',
            'unit_cost' => '4',
        ]]);
        app(GoodsReceiptService::class)->post($receipt);
        app(StockLedgerService::class)->post([
            new StockMovement('out', $item->id, $warehouse->id, '1', self::class, 9001),
        ], 'downstream-consumption:9001');

        try {
            app(InventoryReversalService::class)->reverseGoodsReceipt($receipt, 'Attempt after consumption');
            $this->fail('Downstream consumption must block un-receipt.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('downstream', $e->getMessage());
        }

        $this->assertSame('4.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertNull($receipt->fresh()->reversal_id);
        $this->assertSame(0, InventoryReversal::query()->where('source_id', $receipt->id)->count());
    }

    #[Test]
    public function receipt_reversal_preserves_lot_expiry_and_marks_a_serial_returned_not_sold(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-TRACE-WH']);
        $lotItem = F::lotItem(['sku' => 'REV-LOT-ITEM', 'costing_method' => 'fifo']);
        $serialItem = F::serialItem(['sku' => 'REV-SERIAL-ITEM', 'costing_method' => 'fifo']);
        $expiry = now()->addMonths(9)->toDateString();
        $service = app(GoodsReceiptService::class);
        $receipt = $service->createDraft([
            'grn_number' => 'REV-TRACE-SOURCE',
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
        ], [
            [
                'item_id' => $lotItem->id,
                'received_qty' => '2',
                'accepted_qty' => '2',
                'unit_cost' => '8.50',
                'lot_code' => 'REV-TRACE-LOT',
                'expiry_date' => $expiry,
            ],
            [
                'item_id' => $serialItem->id,
                'received_qty' => '1',
                'accepted_qty' => '1',
                'unit_cost' => '12.00',
                'serials' => ['REV-TRACE-SERIAL'],
            ],
        ]);
        $service->post($receipt);
        $serial = SerialNumber::query()->where('serial', 'REV-TRACE-SERIAL')->firstOrFail();
        $lotLedger = StockLedger::query()->where('item_id', $lotItem->id)->where('direction', 'in')->firstOrFail();

        $reversal = app(InventoryReversalService::class)->reverseGoodsReceipt($receipt, 'Return traceable receipt to supplier');
        $reversedLot = StockLedger::query()
            ->where('source_type', InventoryReversal::class)
            ->where('source_id', $reversal->id)
            ->where('item_id', $lotItem->id)
            ->firstOrFail();

        $this->assertSame('returned', $serial->fresh()->status);
        $this->assertNotSame('sold', $serial->fresh()->status);
        $this->assertSame($lotLedger->lot_id, $reversedLot->lot_id);
        $this->assertSame($expiry, (string) $reversedLot->expiry_date);
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $lotItem->id)->value('on_hand_qty'));
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $serialItem->id)->value('on_hand_qty'));
    }

    #[Test]
    public function average_cost_receipt_reversal_removes_the_exact_source_value_after_a_later_inbound(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-AVG-WH']);
        $item = F::averageItem(['sku' => 'REV-AVG-ITEM']);
        $service = app(GoodsReceiptService::class);
        $first = $service->createDraft([
            'grn_number' => 'REV-AVG-FIRST', 'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id, 'received_qty' => '5', 'accepted_qty' => '5', 'unit_cost' => '10',
        ]]);
        $service->post($first);
        $second = $service->createDraft([
            'grn_number' => 'REV-AVG-SECOND', 'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id, 'received_qty' => '5', 'accepted_qty' => '5', 'unit_cost' => '20',
        ]]);
        $service->post($second);

        app(InventoryReversalService::class)->reverseGoodsReceipt($first, 'Remove exact first receipt');
        $balance = StockBalance::query()->where('item_id', $item->id)->firstOrFail();

        $this->assertSame('5.0000', (string) $balance->on_hand_qty);
        $this->assertSame('20.0000', (string) $balance->average_cost);
        $this->assertSame('100.00', (string) $balance->total_value);
    }

    #[Test]
    public function negative_adjustment_reversal_restores_exact_fifo_layer_and_has_its_own_event_source(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-ADJ-WH']);
        $item = F::fifoItem(['sku' => 'REV-ADJ-ITEM']);
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'REV-ADJ-OPEN', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '8', 'unit_cost' => '6.50']]
        ));
        $adjustment = app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft([
            'adjustment_number' => 'REV-ADJ-SOURCE',
            'warehouse_id' => $warehouse->id,
            'reason_code' => 'DAMAGE',
        ], [[
            'item_id' => $item->id,
            'direction' => 'decrease',
            'quantity' => '3',
        ]]));

        $reversal = app(InventoryReversalService::class)->reverseNegativeAdjustment($adjustment, 'Damage count corrected');

        $this->assertSame('8.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame('8.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('remaining_qty'));
        $this->assertSame('reversed', $adjustment->fresh()->status);
        $this->assertSame($reversal->id, (int) $adjustment->fresh()->reversal_id);
        $this->assertDatabaseHas('integration_outbox_events', [
            'event_type' => 'adjustment.reversed',
            'aggregate_type' => 'InventoryReversal',
            'aggregate_id' => $reversal->id,
        ], 'tenant');
    }

    #[Test]
    public function shipment_source_return_ignores_client_stock_coordinates_and_exactly_inverts_original_ledger(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-SHIP-WH']);
        $item = F::fifoItem(['sku' => 'REV-SHIP-ITEM', 'sales_price' => '20']);
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'REV-SHIP-OPEN', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '9.75']]
        ));
        $sales = app(SalesOrderService::class);
        $order = $sales->reserve($sales->confirm($sales->createDraft([
            'order_number' => 'REV-SHIP-SO',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'ordered_qty' => '2',
            'unit_price' => '20',
        ]])));
        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'REV-SHIP-SOURCE',
            'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'ship_date' => now()->toDateString(),
        ], app(ShipmentService::class)->fromSalesOrder($order));
        app(ShipmentService::class)->post($shipment);

        $return = app(SalesReturnService::class)->createDraft([
            'return_number' => 'REV-SHIP-RMA',
            'shipment_id' => $shipment->id,
            'warehouse_id' => 999999,
            'reason' => 'Customer order cancelled',
        ], [[
            'item_id' => $item->id,
            'returned_qty' => '999',
            'unit_cost' => '0.01',
            'condition' => 'damaged',
        ]]);

        $this->assertTrue($return->is_source_reversal);
        $this->assertSame($warehouse->id, (int) $return->warehouse_id);
        $this->assertSame('2.0000', (string) $return->lines->first()->returned_qty);
        $this->assertSame('9.7500', (string) $return->lines->first()->unit_cost);
        $this->assertSame('resellable', $return->lines->first()->condition);

        app(SalesReturnService::class)->post($return);
        $this->assertSame('4.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame($return->id, (int) $shipment->fresh()->reversal_sales_return_id);
        $this->assertSame(1, StockLedger::query()->where('source_type', SalesReturn::class)->where('source_id', $return->id)->count());
    }

    #[Test]
    public function canonical_available_serial_can_leave_stock_after_a_resellable_return(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-SERIAL-AVAILABLE-WH']);
        $item = F::serialItem(['sku' => 'REV-SERIAL-AVAILABLE-ITEM', 'costing_method' => 'fifo']);
        $serial = F::serial($item, 'REV-SERIAL-AVAILABLE-001', [
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
        ]);
        $ledger = app(StockLedgerService::class);
        $ledger->post([
            new StockMovement('in', $item->id, $warehouse->id, '1', self::class, 9101, unitCost: '12', serialId: $serial->id),
        ], 'returned-serial:in');

        $serial->fresh()->forceFill(['status' => 'available'])->save();
        $ledger->post([
            new StockMovement('out', $item->id, $warehouse->id, '1', self::class, 9102, serialId: $serial->id),
        ], 'returned-serial:out');

        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }

    #[Test]
    public function goods_receipt_reversal_explicitly_enforces_warehouse_access_before_mutation(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-GRN-DENIED-WH']);
        $receipt = app(GoodsReceiptService::class)->createDraft([
            'grn_number' => 'REV-GRN-DENIED',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => F::fifoItem(['sku' => 'REV-GRN-DENIED-ITEM'])->id,
            'received_qty' => '1',
            'accepted_qty' => '1',
            'unit_cost' => '5',
        ]]);
        app(GoodsReceiptService::class)->post($receipt);

        $access = $this->mock(WarehouseAccessService::class);
        $access->shouldReceive('assertAllowed')->once()->with($warehouse->id)->andThrow(new AuthorizationException('denied'));
        $controller = app(GoodsReceiptController::class);

        $this->expectException(AuthorizationException::class);
        $controller->reverse(Request::create('/', 'POST', ['reason' => 'unauthorized reversal']), $receipt);
    }
}
