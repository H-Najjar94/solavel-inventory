<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\SalesReturn;
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
        $this->assertSame(1, StockLedger::query()->where('source_type', StockAdjustment::class)->where('direction', 'out')->count());
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

    /** @return array{0:\App\Models\Tenant\Warehouse,1:\App\Models\Tenant\Item} */
    private function stocked(string $method, array $layers): array
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => $method, 'allow_negative_stock' => false]);
        $w = F::warehouse();
        $item = $method === 'fifo' ? F::fifoItem() : F::averageItem();
        $os = app(OpeningStockService::class);
        foreach ($layers as $i => [$qty, $cost]) {
            $os->post($os->createDraft(['entry_number' => 'XOS-'.$method.'-'.$i, 'warehouse_id' => $w->id],
                [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $cost]]));
        }

        return [$w, $item];
    }

    private function standaloneReturn(string $number, $w, $item, string $cond, string $qty, string $cost, array $extra = [])
    {
        $svc = app(SalesReturnService::class);
        $r = $svc->createDraft(['return_number' => $number, 'warehouse_id' => $w->id, 'return_date' => now()->toDateString()],
            [array_merge(['item_id' => $item->id, 'returned_qty' => $qty, 'unit_cost' => $cost, 'condition' => $cond], $extra)]);

        return $svc->post($r);
    }

    private function inOutValues($r, string $number): array
    {
        $in = (float) StockLedger::query()->where('source_type', \App\Models\Tenant\SalesReturn::class)->where('source_id', $r->id)->where('direction', 'in')->sum('total_cost');
        $adj = StockAdjustment::query()->where('adjustment_number', 'DMG-'.$number)->firstOrFail();
        $out = (float) StockLedger::query()->where('source_type', StockAdjustment::class)->where('source_id', $adj->id)->where('direction', 'out')->sum('total_cost');

        return [$in, $out];
    }

    #[Test]
    public function fifo_write_off_consumes_the_returned_layer_not_the_oldest(): void
    {
        [$w, $item] = $this->stocked('fifo', [['4.000000', '8.0000']]);
        $r = $this->standaloneReturn('WO-F1', $w, $item, 'damaged', '2.000000', '2.0000');
        [$in, $out] = $this->inOutValues($r, 'WO-F1');
        $this->assertEquals(4.0, $in);
        $this->assertEquals($in, $out);
        $this->assertEquals(32.0, (float) StockBalance::query()->where('item_id', $item->id)->sum('total_value'));
        $this->assertEquals(4, $this->onHand($item));
    }

    #[Test]
    public function average_write_off_removes_exactly_the_received_cost(): void
    {
        [$w, $item] = $this->stocked('average', [['4.000000', '8.0000']]);
        $r = $this->standaloneReturn('WO-A1', $w, $item, 'damaged', '2.000000', '2.0000');
        [$in, $out] = $this->inOutValues($r, 'WO-A1');
        $this->assertEquals($in, $out);
        $this->assertEquals(32.0, (float) StockBalance::query()->where('item_id', $item->id)->sum('total_value'));
        $this->assertEquals(8.0, (float) StockBalance::query()->where('item_id', $item->id)->value('average_cost'));
    }

    #[Test]
    public function expired_lot_return_can_still_be_written_off(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $w = F::warehouse();
        $item = F::lotItem();
        $adjs = app(\App\Services\Documents\StockAdjustmentService::class);
        $a = $adjs->createDraft(['adjustment_number' => 'LOT-SEED', 'warehouse_id' => $w->id, 'reason_code' => 'FOUND'],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '2', 'lot_code' => 'LOT-EXP', 'expiry_date' => now()->addMonth()->toDateString()]]);
        $adjs->post($a);
        $lot = \App\Models\Tenant\Lot::query()->where('item_id', $item->id)->firstOrFail();
        $lot->forceFill(['expiry_date' => now()->subDay()->toDateString()])->save();
        $r = $this->standaloneReturn('WO-L1', $w, $item, 'damaged', '1.000000', '2.0000', ['lot_id' => $lot->id]);
        $this->assertSame('posted', $r->status);
        $this->assertTrue(StockAdjustment::query()->where('adjustment_number', 'DMG-WO-L1')->firstOrFail()->isPosted());
        $this->assertEquals(3, $this->onHand($item));
    }

    #[Test]
    public function underscore_in_a_return_number_does_not_match_another_returns_write_off(): void
    {
        [$w, $item] = $this->stocked('average', [['6.000000', '5.0000']]);
        $other = F::averageItem();
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'XOS-OTHER', 'warehouse_id' => $w->id], [['item_id' => $other->id, 'quantity' => '6.000000', 'unit_cost' => '5.0000']]));
        $a = $this->standaloneReturn('RMA_1', $w, $item, 'damaged', '1.000000', '5.0000');
        $b = $this->standaloneReturn('RMAX1', $w, $other, 'damaged', '1.000000', '5.0000');
        app(InventoryReversalService::class)->reverseSalesReturn($a, 'customer withdrew return');
        $this->assertSame('reversed', StockAdjustment::query()->where('adjustment_number', 'DMG-RMA_1')->value('status'));
        $this->assertSame('posted', StockAdjustment::query()->where('adjustment_number', 'DMG-RMAX1')->value('status'));
    }

    #[Test]
    public function a_write_off_already_reversed_by_hand_does_not_block_or_double_reverse(): void
    {
        [$w, $item] = $this->stocked('average', [['6.000000', '5.0000']]);
        $r = $this->standaloneReturn('WO-H1', $w, $item, 'damaged', '2.000000', '5.0000');
        $adj = StockAdjustment::query()->where('adjustment_number', 'DMG-WO-H1')->firstOrFail();
        app(InventoryReversalService::class)->reverseNegativeAdjustment($adj, 'manual correction');
        $this->assertEquals(8, $this->onHand($item));
        app(InventoryReversalService::class)->reverseSalesReturn($r, 'customer withdrew return');
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame('reversed', $r->fresh()->status);
    }

    #[Test]
    public function connected_org_without_adjustment_mappings_gets_a_clear_error_and_nothing_is_written(): void
    {
        [$w, $item] = $this->stocked('average', [['6.000000', '5.0000']]);
        $svc = app(SalesReturnService::class);
        $r = $svc->createDraft(['return_number' => 'WO-M1', 'warehouse_id' => $w->id, 'return_date' => now()->toDateString()],
            [['item_id' => $item->id, 'returned_qty' => '1.000000', 'unit_cost' => '5.0000', 'condition' => 'damaged']]);
        $m = new \ReflectionMethod($svc, 'assertWriteOffReady');
        $m->setAccessible(true);
        $m->invoke($svc, $r->load('lines')); // standalone: passes
        \Illuminate\Support\Facades\DB::connection('tenant')->table('integration_settings')->insert([
            'organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks', 'mode' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $m->invoke($svc, $r);
            $this->fail('Expected the adjustment-readiness preflight to reject the return.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
            $this->assertStringContainsString('adjustment', strtolower($e->errors()['lines'][0]));
        }
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame(0, StockAdjustment::query()->where('adjustment_number', 'like', 'DMG-WO-M1%')->count());
        $this->assertSame('draft', $r->fresh()->status);
    }
}
