<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Recall;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use App\Services\Traceability\RecallService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class SalesReturnsAndCustomerCompletionTest extends TestCase
{
    use TenantAware;

    private function bootTenant(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]
        );
    }

    #[Test]
    public function sales_order_pricing_customer_rma_and_recall_customer_impact_are_complete(): void
    {
        $this->bootTenant();
        $warehouse = F::warehouse();
        $item = F::averageItem(['sales_price' => '20.0000']);
        $customer = Customer::create([
            'code' => 'B5-CUST',
            'name' => 'Batch Five Customer',
            'contact' => ['email' => 'batch5@example.test'],
            'is_active' => true,
        ]);

        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'B5-OS', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]
        ));

        $sales = app(SalesOrderService::class);
        $order = $sales->createDraft([
            'order_number' => 'B5-SO-1',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
        ], [[
            'item_id' => $item->id,
            'ordered_qty' => '2.0000',
            'unit_price' => '20.0000',
            'discount_rate' => '10.0000',
            'tax_rate' => '15.0000',
            'tax_code' => 'VAT15',
        ]]);

        $this->assertSame('Batch Five Customer', $order->customer_name);
        $this->assertSame('40.00', (string) $order->subtotal);
        $this->assertSame('4.00', (string) $order->discount_total);
        $this->assertSame('5.40', (string) $order->tax_total);
        $this->assertSame('41.40', (string) $order->total);
        $this->assertSame('41.40', (string) $order->lines->first()->line_total);

        $order = $sales->reserve($sales->confirm($order));
        $draftLines = app(ShipmentService::class)->fromSalesOrder($order);
        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'B5-SHIP-1',
            'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'ship_date' => now()->toDateString(),
        ], $draftLines);
        app(ShipmentService::class)->post($shipment);
        $this->assertSame('8.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        $return = app(SalesReturnService::class)->createDraft([
            'return_number' => 'B5-RMA-1',
            'shipment_id' => $shipment->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'return_date' => now()->toDateString(),
            'reason' => 'inspection',
        ], [[
            'item_id' => $item->id,
            'returned_qty' => '1.0000',
            'unit_cost' => '5.0000',
            'condition' => 'quarantine',
        ]]);
        $return = app(SalesReturnService::class)->authorizeReturn($return);
        $this->assertSame('authorized', $return->status);
        $return = app(SalesReturnService::class)->inspect($return, 'Quarantine on receipt.');
        $this->assertSame('inspected', $return->status);
        $this->assertSame('restock', $return->lines->first()->disposition);
        app(SalesReturnService::class)->post($return);
        $this->assertSame('10.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        $recall = app(RecallService::class)->createDraft([
            'recall_number' => 'B5-REC-1',
            'item_id' => $item->id,
            'scope' => 'item',
            'reason' => 'Customer impact proof',
        ], [['item_id' => $item->id, 'disposition' => 'quarantine']]);
        $impact = app(RecallService::class)->impact(Recall::query()->findOrFail($recall->id));

        $this->assertSame('2.0000', $impact['totals']['shipped']);
        $this->assertSame('Batch Five Customer', $impact['lines'][0]['shipped_documents'][0]['customer']);
        $this->assertSame('B5-SO-1', $impact['lines'][0]['shipped_documents'][0]['sales_order_number']);
    }
}
