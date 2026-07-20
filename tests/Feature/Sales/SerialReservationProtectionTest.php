<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\PackService;
use App\Services\Documents\PickListService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\ShipmentService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class SerialReservationProtectionTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function one_active_reservation_exclusively_owns_a_serial_and_release_allows_reuse(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false],
        );
        $warehouse = F::warehouse(['code' => 'SER-CONC-WH']);
        $item = F::serialItem(['sku' => 'SER-CONC-ITEM']);
        $customer = Customer::create(['code' => 'SER-CONC-CUST', 'name' => 'Serial concurrency customer', 'is_active' => true]);

        $opening = app(OpeningStockService::class)->createDraft([
            'entry_number' => 'SER-CONC-OS',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'quantity' => '1',
            'unit_cost' => '10',
            'serials' => ['SER-CONC-001'],
        ]]);
        app(OpeningStockService::class)->post($opening);
        $serial = SerialNumber::query()->where('serial', 'SER-CONC-001')->firstOrFail();

        $sales = app(SalesOrderService::class);
        $orders = collect([1, 2])->map(function (int $n) use ($sales, $warehouse, $customer, $item) {
            $order = $sales->createDraft([
                'order_number' => "SER-CONC-SO-{$n}",
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer->id,
            ], [[
                'item_id' => $item->id,
                'ordered_qty' => '1',
                'unit_price' => '20',
            ]]);

            return $sales->confirm($order);
        });

        $first = $orders[0];
        $second = $orders[1];
        $sales->reserve($first, ['serial_ids' => [$first->lines->first()->id => [$serial->id]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already reserved');
        try {
            $sales->reserve($second, ['serial_ids' => [$second->lines->first()->id => [$serial->id]]]);
        } finally {
            $this->assertSame(1, Reservation::query()->where('serial_id', $serial->id)->where('status', 'active')->count());
            $sales->releaseReservation($first->fresh());
            $sales->reserve($second->fresh(), ['serial_ids' => [$second->lines->first()->id => [$serial->id]]]);
            $this->assertSame(1, Reservation::query()->where('serial_id', $serial->id)->where('status', 'active')->count());
            $this->assertSame($second->id, (int) Reservation::query()->where('serial_id', $serial->id)->where('status', 'active')->value('source_id'));
        }
    }

    #[Test]
    public function serial_identity_is_exclusive_through_pick_pack_and_idempotent_shipment(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false],
        );
        $warehouse = F::warehouse(['code' => 'SER-FLOW-WH']);
        $item = F::serialItem(['sku' => 'SER-FLOW-ITEM', 'costing_method' => 'fifo']);
        $opening = app(OpeningStockService::class)->createDraft([
            'entry_number' => 'SER-FLOW-OS',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'quantity' => '1',
            'unit_cost' => '12',
            'serials' => ['SER-FLOW-001'],
        ]]);
        app(OpeningStockService::class)->post($opening);
        $serial = SerialNumber::query()->where('serial', 'SER-FLOW-001')->firstOrFail();

        $sales = app(SalesOrderService::class);
        $order = $sales->createDraft([
            'order_number' => 'SER-FLOW-SO',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'ordered_qty' => '1',
            'unit_price' => '20',
        ]]);
        $order = $sales->confirm($order);
        $order = $sales->reserve($order, ['serial_ids' => [$order->lines->first()->id => [$serial->id]]]);

        $picks = app(PickListService::class);
        $pick = $picks->createFromSalesOrder($order, ['pick_number' => 'SER-FLOW-PICK']);
        $this->assertSame($serial->id, (int) $pick->lines->first()->serial_id);
        try {
            $picks->createFromSalesOrder($order, ['pick_number' => 'SER-FLOW-PICK-DUP']);
            $this->fail('A second active pick for the serial should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already belongs to an active pick', $exception->getMessage());
        }
        $pick = $picks->updatePicks($pick, [$pick->lines->first()->id => '1']);
        $pick = $picks->markPicked($pick);
        $this->assertSame('picked', $pick->status);

        $packs = app(PackService::class);
        $pack = $packs->createFromPickList($pick, ['pack_number' => 'SER-FLOW-PACK']);
        $this->assertSame($serial->id, (int) $pack->lines->first()->serial_id);
        try {
            $packs->createFromPickList($pick, ['pack_number' => 'SER-FLOW-PACK-DUP']);
            $this->fail('A second active pack for the picked serial should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already belongs to an active pack', $exception->getMessage());
        }
        $pack = $packs->updatePacks($pack, [$pack->lines->first()->id => '1']);
        $pack = $packs->markPacked($pack);
        $this->assertSame('packed', $pack->status);

        $shipments = app(ShipmentService::class);
        $lines = $shipments->fromSalesOrder($order->fresh());
        $this->assertSame($serial->id, (int) $lines[0]['serial_id']);
        $first = $shipments->createDraft([
            'shipment_number' => 'SER-FLOW-SHIP-1',
            'sales_order_id' => $order->id,
            'pack_id' => $pack->id,
            'warehouse_id' => $warehouse->id,
        ], $lines);
        $second = $shipments->createDraft([
            'shipment_number' => 'SER-FLOW-SHIP-2',
            'sales_order_id' => $order->id,
            'pack_id' => $pack->id,
            'warehouse_id' => $warehouse->id,
        ], $lines);
        $posted = $shipments->post($first);
        $this->assertSame('posted', $posted->status);
        $this->assertSame('posted', $shipments->post($posted)->status, 'same shipment replay is idempotent');
        try {
            $shipments->post($second);
            $this->fail('A second shipment for the consumed serial should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not actively reserved', $exception->getMessage());
        }

        $this->assertSame(1, StockLedger::query()->where('source_type', Shipment::class)
            ->where('serial_id', $serial->id)->where('direction', 'out')->count());
        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame($first->id, (int) $serial->fresh()->shipment_id);
    }
}
