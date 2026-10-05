<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\InventorySetting;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\PickListService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\ShipmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class FulfilmentNumberSuffixTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function second_pick_list_and_shipment_for_an_order_get_a_suffix(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $warehouse = F::warehouse(['code' => 'SFX-WH']);
        $item = F::averageItem(['sku' => 'SFX-ITEM']);
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'SFX-OS-1', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '3']]));

        $sales = app(SalesOrderService::class);
        $order = $sales->confirm($sales->createDraft(['order_number' => 'SO-SFX', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'ordered_qty' => '4', 'unit_price' => '9']]));
        $order = $sales->reserve($order);

        $picks = app(PickListService::class);
        $first = $picks->createFromSalesOrder($order, ['pick_number' => 'PICK-SO-SFX']);
        $second = $picks->createFromSalesOrder($order, ['pick_number' => 'PICK-SO-SFX']);
        $this->assertSame('PICK-SO-SFX', $first->pick_number);
        $this->assertSame('PICK-SO-SFX-2', $second->pick_number);

        $shipments = app(ShipmentService::class);
        $line = [['item_id' => $item->id, 'quantity' => '1']];
        $shipA = $shipments->createDraft(['shipment_number' => 'SHIP-SO-SFX', 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id], $line);
        $shipB = $shipments->createDraft(['shipment_number' => 'SHIP-SO-SFX', 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id], $line);
        $shipC = $shipments->createDraft(['shipment_number' => 'SHIP-SO-SFX', 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id], $line);
        $this->assertSame(['SHIP-SO-SFX', 'SHIP-SO-SFX-2', 'SHIP-SO-SFX-3'], [$shipA->shipment_number, $shipB->shipment_number, $shipC->shipment_number]);
    }
}
