<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Api\V1\ScannerController;
use App\Http\Controllers\Api\V1\WarehouseStructureController;
use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\ShipmentService;
use App\Services\Shipping\CarrierService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class ShippingAndScannerCompletionTest extends TestCase
{
    use TenantAware;

    private function bootTenant(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false]
        );
    }

    #[Test]
    public function carrier_labels_tracking_scanner_lookup_and_serial_registration_are_complete(): void
    {
        $this->bootTenant();
        $warehouse = F::warehouse(['code' => 'B6-WH']);
        $zone = WarehouseZone::create(['warehouse_id' => $warehouse->id, 'code' => 'A', 'name' => 'Aisle A']);
        $bin = WarehouseBin::create([
            'warehouse_id' => $warehouse->id,
            'zone_id' => $zone->id,
            'code' => 'A-01',
            'capacity' => '10.0000',
            'coords' => ['bin_type' => 'picking', 'barcode' => 'BIN-A-01'],
            'is_active' => true,
        ]);
        $item = F::serialItem(['sku' => 'B6-SERIAL', 'name' => 'Serialized scanner item']);
        ItemBarcode::create(['item_id' => $item->id, 'barcode' => 'B6-ITEM-BC', 'type' => 'primary']);
        $customer = Customer::create(['code' => 'B6-CUST', 'name' => 'Batch Six Customer', 'is_active' => true]);

        $labels = (new WarehouseStructureController(app(\App\Tenancy\OrganizationContext::class)))
            ->labelSheet($warehouse)->getData(true)['data']['labels'];
        $this->assertSame('BIN-A-01', $labels[0]['barcode']);
        $this->assertStringContainsString('<svg', $labels[0]['qr_svg']);

        $scanner = new ScannerController();
        $itemScan = $scanner->lookup(Request::create('/scanner/lookup', 'GET', ['code' => 'B6-ITEM-BC']))->getData(true)['data'];
        $this->assertSame('item', $itemScan['type']);
        $binScan = $scanner->lookup(Request::create('/scanner/lookup', 'GET', ['code' => 'BIN-A-01']))->getData(true)['data'];
        $this->assertSame('bin', $binScan['type']);
        $this->assertSame('B6-WH', $binScan['bin']['warehouse_code']);

        $opening = app(OpeningStockService::class)->createDraft([
            'entry_number' => 'B6-OS',
            'warehouse_id' => $warehouse->id,
        ], [[
            'item_id' => $item->id,
            'bin_id' => $bin->id,
            'quantity' => '1.0000',
            'unit_cost' => '12.0000',
            'serials' => ['B6-SER-001'],
        ]]);
        app(OpeningStockService::class)->post($opening);
        $serial = SerialNumber::query()->where('serial', 'B6-SER-001')->firstOrFail();
        $this->assertSame('in_stock', $serial->status);

        $sales = app(SalesOrderService::class);
        $order = $sales->createDraft([
            'order_number' => 'B6-SO',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
        ], [[
            'item_id' => $item->id,
            'ordered_qty' => '1.0000',
            'unit_price' => '25.0000',
        ]]);
        $order = $sales->confirm($order);

        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'B6-SHIP',
            'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'ship_date' => now()->toDateString(),
            'carrier' => 'SolaShip',
            'carrier_service' => 'express',
            'ship_to' => ['name' => 'Batch Six Customer', 'address' => 'QA Dock'],
            'package_weight' => '1.5000',
            'warranty_months' => 18,
        ], [[
            'sales_order_line_id' => $order->lines->first()->id,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'bin_id' => $bin->id,
            'quantity' => '1.0000',
            'serial_id' => $serial->id,
        ]]);

        $carrier = app(CarrierService::class);
        $rates = $carrier->rates($shipment);
        $this->assertCount(3, $rates);
        $label = $carrier->generateLabel($shipment, 'express');
        $this->assertSame('express', $label['service_code']);
        $this->assertStringStartsWith('STK', $label['tracking_number']);
        $this->assertStringContainsString('<svg', $label['qr_svg']);

        app(ShipmentService::class)->post($shipment->fresh());
        $this->assertSame('0.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        $serial = $serial->fresh();
        $this->assertSame('sold', $serial->status);
        $this->assertSame('Batch Six Customer', $serial->owner_ref);
        $this->assertSame(now()->addMonths(18)->toDateString(), $serial->warranty_until?->toDateString());

        $tracking = $carrier->tracking($shipment->fresh());
        $this->assertSame('in_transit', $tracking['status']);
        $shipScan = $scanner->lookup(Request::create('/scanner/lookup', 'GET', ['code' => $label['tracking_number']]))->getData(true)['data'];
        $this->assertSame('shipment', $shipScan['type']);
        $this->assertSame('B6-SHIP', $shipScan['shipment']['shipment_number']);
    }
}
