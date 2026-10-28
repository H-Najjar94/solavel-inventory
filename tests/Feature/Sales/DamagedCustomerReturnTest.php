<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\InventoryReversalService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Damaged/retired returned units are received at the shipment cost (so COGS is
 * reversed in full via sales_return.posted) and written off by a posted decrease
 * adjustment (adjustment.posted -> adjustment_loss). They never remain on hand.
 * MySQL-backed: not executed in the defects-20261028 worktree.
 */
class DamagedCustomerReturnTest extends TestCase
{
    use TenantAware;

    private function shipped(): array
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $warehouse = F::warehouse();
        $item = F::averageItem(['sales_price' => '20.0000']);
        $customer = Customer::create(['code' => 'DMG-C', 'name' => 'Damage Customer', 'contact' => [], 'is_active' => true]);
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'DMG-OS', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '10.000000', 'unit_cost' => '5.0000']]));
        $so = app(SalesOrderService::class);
        $order = $so->createDraft(['order_number' => 'DMG-SO', 'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id],
            [['item_id' => $item->id, 'ordered_qty' => '4.000000', 'unit_price' => '20.0000']]);
        $order = $so->reserve($so->confirm($order));
        $ship = app(ShipmentService::class);
        $shipment = $ship->createDraft(['shipment_number' => 'DMG-SH', 'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id, 'ship_date' => now()->toDateString()], $ship->fromSalesOrder($order));
        $ship->post($shipment);

        return [$warehouse, $item, $customer, $shipment->fresh('lines')];
    }

    private function postReturn(string $number, $shipment, $customer, $warehouse, $item, array $conditions)
    {
        $svc = app(SalesReturnService::class);
        $line = $shipment->lines->first();
        $lines = array_map(fn ($c) => ['source_line_id' => $line->id, 'item_id' => $item->id,
            'returned_qty' => (string) $c[1], 'entered_qty' => (string) $c[1], 'condition' => $c[0]], $conditions);
        $r = $svc->createDraft(['return_number' => $number, 'shipment_id' => $shipment->id, 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id, 'return_date' => now()->toDateString(), 'reason' => 'customer return'], $lines);

        return $svc->post($r);
    }

    private function onHand($item): string
    {
        return (string) StockBalance::query()->where('item_id', $item->id)->sum('on_hand_qty');
    }

    #[Test]
    public function resellable_only_restocks_without_a_write_off(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $this->postReturn('DMG-R1', $s, $c, $w, $item, [['resellable', 2]]);
        $this->assertEquals(8, $this->onHand($item));
        $this->assertSame(0, StockAdjustment::query()->where('adjustment_number', 'like', 'DMG-DMG-R1%')->count());
    }

    #[Test]
    public function damaged_only_is_not_left_on_hand_and_is_written_off_at_cost(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $r = $this->postReturn('DMG-R2', $s, $c, $w, $item, [['damaged', 2]]);
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame('posted', $r->status);
        $this->assertEquals(10, (float) StockLedger::query()->where('source_type', \App\Models\Tenant\SalesReturn::class)
            ->where('source_id', $r->id)->where('direction', 'in')->sum('total_cost'));
        $adj = StockAdjustment::query()->where('adjustment_number', 'DMG-DMG-R2')->firstOrFail();
        $this->assertTrue($adj->isPosted());
        $this->assertSame('customer_return_damage', $adj->reason_code);
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'adjustment.posted')->where('aggregate_id', $adj->id)->count());
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'sales_return.posted')->where('aggregate_id', $r->id)->count());
    }

    #[Test]
    public function mixed_return_restocks_only_the_resellable_part(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $this->postReturn('DMG-R3', $s, $c, $w, $item, [['resellable', 1], ['damaged', 1], ['retired', 1]]);
        $this->assertEquals(7, $this->onHand($item)); // 6 + 1 resellable
        $adj = StockAdjustment::query()->where('adjustment_number', 'DMG-DMG-R3')->firstOrFail();
        $this->assertEquals(2, (float) $adj->lines()->sum('quantity'));
    }

    #[Test]
    public function quarantine_stays_on_hand_without_a_write_off(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $this->postReturn('DMG-R4', $s, $c, $w, $item, [['quarantine', 1]]);
        $this->assertEquals(7, $this->onHand($item));
        $this->assertSame(0, StockAdjustment::query()->where('adjustment_number', 'like', 'DMG-DMG-R4%')->count());
    }

    #[Test]
    public function reposting_does_not_write_off_twice(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $r = $this->postReturn('DMG-R5', $s, $c, $w, $item, [['damaged', 1]]);
        app(SalesReturnService::class)->post($r);
        $this->assertSame(1, StockAdjustment::query()->where('adjustment_number', 'DMG-DMG-R5')->count());
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame(1, IntegrationOutboxEvent::query()->where('event_type', 'adjustment.posted')->count());
    }

    #[Test]
    public function reversing_a_damaged_return_reverses_the_write_off_first(): void
    {
        [$w, $item, $c, $s] = $this->shipped();
        $r = $this->postReturn('DMG-R6', $s, $c, $w, $item, [['damaged', 2]]);
        app(InventoryReversalService::class)->reverseSalesReturn($r, 'customer withdrew return');
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame('reversed', StockAdjustment::query()->where('adjustment_number', 'DMG-DMG-R6')->value('status'));
        $this->assertSame('reversed', $r->fresh()->status);
    }
}
