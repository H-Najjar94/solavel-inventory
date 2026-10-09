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

        $this->assertSame(1, \Illuminate\Support\Facades\DB::connection('tenant')->transactionLevel());
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
    public function average_cost_opening_stock_reversal_is_blocked_after_consumption(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-OS-AVG']);
        $item = F::averageItem(['sku' => 'REV-OS-AVG-ITEM']);
        $opening = app(OpeningStockService::class);
        $entry = $opening->createDraft(['entry_number' => 'REV-OS-AVG', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]);
        $opening->post($entry);
        $adjustments = app(StockAdjustmentService::class);
        $adjustments->post($adjustments->createDraft(['adjustment_number' => 'REV-OS-AVG-USE', 'warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]));

        try {
            $opening->reverse($entry->fresh(), 'Opening entered twice');
            $this->fail('Consumed average-cost opening stock must not be reversible.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('inventory.documents.opening_reversal_downstream'), $e->getMessage());
        }
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame('7.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }

    #[Test]
    public function opening_stock_reversal_requires_and_records_the_reason(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-OS-WHY']);
        $item = F::averageItem(['sku' => 'REV-OS-WHY-ITEM']);
        $opening = app(OpeningStockService::class);
        $entry = $opening->createDraft(['entry_number' => 'REV-OS-WHY', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '5']]);
        $opening->post($entry);
        $controller = app(\App\Http\Controllers\Api\V1\OpeningStockController::class);

        try {
            $controller->reverse(Request::create('/', 'POST', []), $entry->fresh());
            $this->fail('A reversal without a reason must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('posted', $entry->fresh()->status);
        }

        $controller->reverse(Request::create('/', 'POST', ['reason' => 'Wrong warehouse']), $entry->fresh());
        $this->assertSame('reversed', $entry->fresh()->status);
        $audit = \App\Models\Tenant\InventoryAuditLog::query()->where('action', 'opening_stock.reverse')->where('document_ref', 'REV-OS-WHY')->firstOrFail();
        $this->assertSame('Wrong warehouse', $audit->after['reason']);
    }

    #[Test]
    public function standalone_opening_stock_reversal_is_an_exact_inventory_reversal_without_any_finance_event(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-OS-STD']);
        $item = F::fifoItem(['sku' => 'REV-OS-STD-ITEM']);
        $opening = app(OpeningStockService::class);
        $entry = $opening->createDraft(['entry_number' => 'REV-OS-STD', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '3', 'unit_cost' => '7']]);
        $opening->post($entry);

        $opening->reverse($entry->fresh(), 'Wrong item counted');
        $opening->reverse($entry->fresh(), 'Wrong item counted');

        $reversal = InventoryReversal::query()->where('source_type', 'opening_stock')->where('source_id', $entry->id)->sole();
        $this->assertSame('Wrong item counted', $reversal->reason);
        $this->assertSame('REV-OS-STD', $reversal->source_number);
        $out = StockLedger::query()->where('source_type', InventoryReversal::class)->where('source_id', $reversal->id)->sole();
        $this->assertSame('out', $out->direction);
        $this->assertSame('21.00', (string) $out->total_cost);
        $this->assertSame('0.0000', (string) CostLayer::query()->where('item_id', $item->id)->value('remaining_qty'));
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        // Standalone Stock: no connection, no ownership, so no Finance work at all.
        $this->assertDatabaseMissing('integration_outbox_events', ['event_type' => 'opening_stock.reversed'], 'tenant');
        $this->assertNull($reversal->reversal_event_uuid);
    }

    #[Test]
    public function increase_adjustment_reversal_blocked_downstream_names_the_adjustment_not_a_receipt(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'REV-INC-WH']);
        $item = F::fifoItem(['sku' => 'REV-INC-ITEM']);
        $adjustment = app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft([
            'adjustment_number' => 'REV-INC-SOURCE',
            'warehouse_id' => $warehouse->id,
            'reason_code' => 'FOUND',
        ], [[
            'item_id' => $item->id,
            'direction' => 'increase',
            'quantity' => '4',
            'unit_cost' => '2',
        ]]));
        app(StockLedgerService::class)->post([
            new StockMovement('out', $item->id, $warehouse->id, '1', self::class, 9002),
        ], 'downstream-consumption:9002');

        try {
            app(InventoryReversalService::class)->reverseNegativeAdjustment($adjustment, 'Found stock was miscounted');
            $this->fail('Downstream consumption must block reversing an increase adjustment.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('inventory.documents.adjustment_reversal_downstream'), $e->getMessage());
            $this->assertStringContainsString('adjustment', $e->getMessage());
            $this->assertStringNotContainsString('receipt', $e->getMessage());
        }

        $this->assertNull($adjustment->fresh()->reversal_id);
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
    public function shipment_source_return_rejects_changed_quantities_then_exactly_inverts_original_ledger(): void
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

        try {
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
            $this->fail('Unsupported quantities and disposition must not become a full restock.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
            $this->assertSame(0, SalesReturn::query()->count());
        }
        $return = app(SalesReturnService::class)->createDraft([
            'return_number' => 'REV-SHIP-RMA', 'shipment_id' => $shipment->id,
            'warehouse_id' => 999999, 'reason' => 'Customer order cancelled',
        ], [['item_id' => $item->id, 'returned_qty' => '2', 'unit_cost' => '0.01', 'condition' => 'resellable']]);

        $this->assertTrue($return->is_source_reversal);
        $this->assertSame($warehouse->id, (int) $return->warehouse_id);
        $this->assertSame('2.0000', (string) $return->lines->first()->returned_qty);
        $this->assertSame('9.7500', (string) $return->lines->first()->unit_cost);
        $this->assertSame('resellable', $return->lines->first()->condition);

        $this->assertSame(1, \Illuminate\Support\Facades\DB::connection('tenant')->transactionLevel());
        app(SalesReturnService::class)->post($return);
        $this->assertSame('4.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame($return->id, (int) $shipment->fresh()->reversal_sales_return_id);
        $this->assertSame(1, StockLedger::query()->where('source_type', SalesReturn::class)->where('source_id', $return->id)->count());
    }

    #[Test]
    public function shipment_allows_partial_resellable_and_damaged_returns_without_over_return_or_duplicate_stock(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'PARTIAL-RETURN-WH']);
        $item = F::fifoItem(['sku' => 'PARTIAL-RETURN-ITEM', 'sales_price' => '20']);
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'PARTIAL-RETURN-OPEN', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '9.75']]
        ));
        $sales = app(SalesOrderService::class);
        $order = $sales->reserve($sales->confirm($sales->createDraft([
            'order_number' => 'PARTIAL-RETURN-SO', 'warehouse_id' => $warehouse->id,
        ], [['item_id' => $item->id, 'ordered_qty' => '2', 'unit_price' => '20']])));
        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'PARTIAL-RETURN-SHIP', 'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id, 'ship_date' => now()->toDateString(),
        ], app(ShipmentService::class)->fromSalesOrder($order));
        app(ShipmentService::class)->post($shipment);
        $sourceLine = $shipment->fresh('lines')->lines->sole();
        $sourceLedger = StockLedger::query()->where('source_type', get_class($shipment))->where('source_id', $shipment->id)->sole();

        $partial = app(SalesReturnService::class)->createDraft([
            'return_number' => 'PARTIAL-RETURN-ONE', 'shipment_id' => $shipment->id,
            'warehouse_id' => $warehouse->id, 'reason' => 'One unit resellable',
        ], [['source_line_id' => $sourceLine->id, 'source_stock_ledger_id' => $sourceLedger->id,
            'item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'resellable']]);
        $this->assertFalse($partial->is_source_reversal);
        app(SalesReturnService::class)->post($partial);
        $this->assertSame('3.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame($sourceLine->id, (int) $partial->lines->sole()->source_shipment_line_id);
        $this->assertSame($sourceLedger->id, (int) $partial->lines->sole()->source_stock_ledger_id);

        $damaged = app(SalesReturnService::class)->createDraft([
            'return_number' => 'PARTIAL-RETURN-DAMAGE', 'shipment_id' => $shipment->id,
            'warehouse_id' => $warehouse->id, 'reason' => 'One unit damaged',
        ], [['source_line_id' => $sourceLine->id, 'source_stock_ledger_id' => $sourceLedger->id,
            'item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'damaged', 'disposition' => 'damage']]);
        app(SalesReturnService::class)->post($damaged);
        $this->assertSame('3.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame(0, StockLedger::query()->where('source_type', SalesReturn::class)->where('source_id', $damaged->id)->count());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SalesReturnService::class)->createDraft([
            'return_number' => 'PARTIAL-RETURN-OVER', 'shipment_id' => $shipment->id,
            'warehouse_id' => $warehouse->id, 'reason' => 'Over return attempt',
        ], [['source_line_id' => $sourceLine->id, 'source_stock_ledger_id' => $sourceLedger->id,
            'item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'resellable']]);
    }

    #[Test]
    public function cancelling_releases_partial_return_quantity_and_posted_return_reversal_is_idempotent(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'RETURN-CANCEL-WH']);
        $item = F::fifoItem(['sku' => 'RETURN-CANCEL-ITEM', 'sales_price' => '20']);
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'RETURN-CANCEL-OPEN', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '3', 'unit_cost' => '8']]
        ));
        $sales = app(SalesOrderService::class);
        $order = $sales->reserve($sales->confirm($sales->createDraft([
            'order_number' => 'RETURN-CANCEL-SO', 'warehouse_id' => $warehouse->id,
        ], [['item_id' => $item->id, 'ordered_qty' => '2', 'unit_price' => '20']])));
        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'RETURN-CANCEL-SHIP', 'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
        ], app(ShipmentService::class)->fromSalesOrder($order));
        app(ShipmentService::class)->post($shipment);
        $sourceLine = $shipment->fresh('lines')->lines->sole();
        $sourceLedger = StockLedger::query()->where('source_type', get_class($shipment))->where('source_id', $shipment->id)->sole();
        $line = ['source_line_id' => $sourceLine->id, 'source_stock_ledger_id' => $sourceLedger->id,
            'item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'resellable'];

        $abandoned = app(SalesReturnService::class)->createDraft([
            'return_number' => 'RETURN-CANCEL-DRAFT', 'shipment_id' => $shipment->id,
            'warehouse_id' => $warehouse->id, 'reason' => 'Customer changed request',
        ], [$line]);
        app(SalesReturnService::class)->cancel($abandoned);
        $replacement = app(SalesReturnService::class)->createDraft([
            'return_number' => 'RETURN-CANCEL-REPLACEMENT', 'shipment_id' => $shipment->id,
            'warehouse_id' => $warehouse->id, 'reason' => 'Accepted replacement return',
        ], [$line]);
        app(SalesReturnService::class)->post($replacement);
        $this->assertSame('2.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        $reversal = app(InventoryReversalService::class)->reverseSalesReturn($replacement, 'Customer retained returned item');
        $again = app(InventoryReversalService::class)->reverseSalesReturn($replacement->fresh(), 'Ignored retry');
        $this->assertSame($reversal->id, $again->id);
        $this->assertSame('reversed', $replacement->fresh()->status);
        $this->assertSame('1.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertDatabaseHas('integration_outbox_events', [
            'event_type' => 'sales_return.reversed', 'aggregate_id' => $reversal->id,
        ], 'tenant');
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
