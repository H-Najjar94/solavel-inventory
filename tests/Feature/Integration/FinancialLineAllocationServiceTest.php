<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Unit;
use App\Services\Integration\FinancialLineAllocationService;
use App\Services\Stock\{PurchaseCostAdjustmentService, StockLedgerService, StockMovement};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class FinancialLineAllocationServiceTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $connection;
    private GoodsReceipt $receipt;
    private IntegrationDocumentLifecycleMapping $source;
    private int $lineId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $unit = Unit::query()->create(['code' => 'CASE-ALLOC', 'name' => 'Case', 'symbol' => 'cs']);
        $baseUnit = Unit::query()->create(['code' => 'EACH-ALLOC', 'name' => 'Each', 'symbol' => 'ea']);
        $item = F::item(['sku' => 'ALLOC-ITEM', 'base_unit_id' => $baseUnit->id]);
        $warehouse = F::warehouse(['code' => 'ALLOC-WH']);
        $this->receipt = GoodsReceipt::query()->create([
            'grn_number' => 'ALLOC-GRN-1', 'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-09', 'status' => 'posted', 'posted_at' => now(),
        ]);
        $line = $this->receipt->lines()->create([
            'item_id' => $item->id, 'received_qty' => '10', 'accepted_qty' => '10',
            'entered_qty' => '2', 'entered_unit_id' => $unit->id, 'base_unit_id' => $baseUnit->id,
            'unit_conversion_factor' => '5', 'unit_conversion_version' => 'v1',
            'unit_conversion_hash' => hash('sha256', 'case-to-base-5'),
            'unit_conversion_precision' => 4, 'unit_conversion_rounding_mode' => 'HALF_UP',
            'unit_cost' => '4',
        ]);
        $this->lineId = (int) $line->id;
        $this->connection = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 77,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD',
        ]);
        $this->source = IntegrationDocumentLifecycleMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->connection->mapping_uuid,
            'central_client_id' => 77, 'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'source_application' => 'solastock', 'source_document_type' => 'goods_receipt',
            'source_document_id' => (string) $this->receipt->id, 'lifecycle_status' => 'posted',
            'received_qty' => '10', 'transaction_currency_code' => 'JOD', 'base_currency_code' => 'JOD',
            'exchange_rate' => '1', 'accounting_source_key' => 'grn:alloc:1',
        ]);
    }

    #[Test]
    public function converted_partial_allocations_are_exact_idempotent_and_cannot_overconsume(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $first = $service->reserve($this->payload(9001, 11, '1.2', '6', '6', '5', '30', '2', '1', '27'));
        $again = $service->reserve($this->payload(9001, 11, '1.2', '6', '6', '5', '30', '2', '1', '27'));
        $this->assertSame($first['allocations'][0]['allocation_uuid'], $again['allocations'][0]['allocation_uuid']);
        $this->assertSame('6.00000000', $first['allocations'][0]['base_quantity']);
        $this->assertSame('6.00000000', $first['allocations'][0]['destination_quantity']);
        $this->assertSame('3.00000000', $first['allocations'][0]['price_difference']);
        $this->assertSame(1, IntegrationFinancialLineAllocation::query()->count());

        $second = $service->reserve($this->payload(9002, 12, '0.8', '4', '0.8', '25', '20', '0', '0', '20'));
        $this->assertSame('4.00000000', $second['allocations'][0]['base_quantity']);
        try {
            $service->reserve($this->payload(9003, 13, '0.2', '1', '1', '4', '4', '0', '0', '4'));
            $this->fail('Cumulative allocations must not exceed the source line.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }
        $this->assertSame(2, IntegrationFinancialLineAllocation::query()->count());

        $service->transition([
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9001,
            'destination_fingerprint' => str_repeat('a', 64),
        ], 'posted');
        $service->transition([
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9001,
            'destination_fingerprint' => str_repeat('a', 64),
        ], 'posted');
        $this->assertSame('posted', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
    }

    #[Test]
    public function a_changed_draft_selection_atomically_releases_the_old_reservation(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $old = $service->reserve($this->payload(9100, 21, '1.2', '6', '6', '4', '24', '0', '0', '24'));
        $new = $service->reserve($this->payload(9100, 21, '1', '5', '5', '4', '20', '0', '0', '20'));

        $this->assertNotSame($old['allocations'][0]['allocation_uuid'], $new['allocations'][0]['allocation_uuid']);
        $this->assertSame('released', IntegrationFinancialLineAllocation::query()->where('allocation_uuid', $old['allocations'][0]['allocation_uuid'])->value('state'));
        $this->assertSame('draft_reserved', IntegrationFinancialLineAllocation::query()->where('allocation_uuid', $new['allocations'][0]['allocation_uuid'])->value('state'));
        $this->assertSame('5.00000000', (string) IntegrationFinancialLineAllocation::query()->where('state', 'draft_reserved')->sum('base_quantity'));
    }

    #[Test]
    public function cancelling_a_draft_releases_capacity_idempotently_for_a_later_document(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $reserved = $service->reserve($this->payload(9200, 31, '2', '10', '10', '4', '40', '0', '0', '40'));

        $transition = [
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9200,
            'destination_fingerprint' => str_repeat('a', 64),
        ];
        $service->transition($transition, 'released');
        $service->transition($transition, 'released');
        $this->assertSame('released', IntegrationFinancialLineAllocation::query()
            ->where('allocation_uuid', $reserved['allocations'][0]['allocation_uuid'])->value('state'));

        $replacement = $service->reserve($this->payload(9201, 32, '2', '10', '10', '4', '40', '0', '0', '40'));
        $this->assertSame('draft_reserved', $replacement['allocations'][0]['state']);
        $this->assertSame('10.00000000', $replacement['allocations'][0]['base_quantity']);
    }

    #[Test]
    public function purchase_cost_adjustment_is_provenance_backed_idempotent_and_reversible(): void
    {
        $line=$this->receipt->lines()->firstOrFail();
        app(StockLedgerService::class)->post([new StockMovement(direction:'in',itemId:(int)$line->item_id,
            warehouseId:(int)$this->receipt->warehouse_id,quantity:'10',sourceType:GoodsReceipt::class,
            sourceId:(int)$this->receipt->id,sourceLineId:(int)$line->id,unitCost:'4',movedAt:'2026-09-09 09:00:00')],
            'test:purchase-cost:receipt');
        app(FinancialLineAllocationService::class)->reserve($this->payload(9300,41,'2','10','10','5','50','0','0','50'));
        $input=['organization_mapping_uuid'=>$this->connection->mapping_uuid,'destination_document_id'=>9300,
            'destination_fingerprint'=>str_repeat('a',64),'currency_code'=>'JOD','base_currency_code'=>'JOD',
            'exchange_rate'=>'1','finance_money_scale'=>3,'discount_posting_mode'=>'net'];
        $service=app(PurchaseCostAdjustmentService::class);
        $prepared=$service->prepare($input); $again=$service->prepare($input);
        $this->assertSame($prepared['adjustment_uuid'],$again['adjustment_uuid']);
        $this->assertSame('JOD',$prepared['currency_code']);
        $this->assertSame('JOD',$prepared['base_currency_code']);
        $this->assertSame('1.000000000000',$prepared['exchange_rate']);
        $this->assertSame(3,$prepared['finance_money_scale']);
        $this->assertSame('10.00000000',$prepared['exact_base_difference']);
        $this->assertSame('inventory_asset',$prepared['components'][0]['destination_role']);
        $this->assertSame('40.00',(string)\App\Models\Tenant\StockBalance::query()->value('total_value'));
        $applied=$service->apply($input); $service->apply($input);
        $this->assertSame('applied',$applied['state']);
        $this->assertSame('50.00',(string)\App\Models\Tenant\StockBalance::query()->value('total_value'));
        $this->assertTrue(app(\App\Services\Stock\IntegrityChecker::class)->check('tenant',TenantTestManager::ORG_A)['ok']);
        $reversed=$service->reverse($input); $service->reverse($input);
        $this->assertSame('reversed',$reversed['state']);
        $this->assertSame('40.00',(string)\App\Models\Tenant\StockBalance::query()->value('total_value'));
        $this->assertTrue(app(\App\Services\Stock\IntegrityChecker::class)->check('tenant',TenantTestManager::ORG_A)['ok']);
        $this->assertSame(1,\App\Models\Tenant\IntegrationPurchaseCostAdjustment::query()->count());
    }

    #[Test]
    public function lower_price_is_split_between_actual_sale_cogs_and_remaining_average_inventory(): void
    {
        $line=$this->receipt->lines()->firstOrFail();
        $ledger=app(StockLedgerService::class);
        $ledger->post([new StockMovement(direction:'in',itemId:(int)$line->item_id,warehouseId:(int)$this->receipt->warehouse_id,
            quantity:'10',sourceType:GoodsReceipt::class,sourceId:(int)$this->receipt->id,sourceLineId:(int)$line->id,
            unitCost:'4',movedAt:'2026-09-09 09:00:00')],'test:purchase-cost:mixed-in');
        $ledger->post([new StockMovement(direction:'out',itemId:(int)$line->item_id,warehouseId:(int)$this->receipt->warehouse_id,
            quantity:'4',sourceType:\App\Models\Tenant\Shipment::class,sourceId:81,sourceLineId:82,movedAt:'2026-09-09 10:00:00')],
            'test:purchase-cost:mixed-out');
        $this->source->transaction_currency_code='USD';$this->source->exchange_rate='2';$this->source->save();
        app(FinancialLineAllocationService::class)->reserve($this->payload(9400,51,'2','10','10','3','30','0','0','30','2','USD'));
        $input=['organization_mapping_uuid'=>$this->connection->mapping_uuid,'destination_document_id'=>9400,
            'destination_fingerprint'=>str_repeat('a',64),'currency_code'=>'USD','base_currency_code'=>'JOD','exchange_rate'=>'2',
            'finance_money_scale'=>3,'discount_posting_mode'=>'net'];
        $plan=app(PurchaseCostAdjustmentService::class)->prepare($input);
        $byRole=collect($plan['components'])->groupBy('destination_role')->map(fn($rows)=>$rows->sum(fn($r)=>(float)$r['posted_base_amount']));
        $this->assertSame(-2.0,$byRole['cogs']);
        $this->assertSame(-3.0,$byRole['inventory_asset']);
        app(PurchaseCostAdjustmentService::class)->apply($input);
        $this->assertSame('21.00',(string)\App\Models\Tenant\StockBalance::query()->value('total_value'));
    }

    #[Test]
    public function fifo_uses_exact_layer_consumption_and_adjusts_only_the_remaining_layer(): void
    {
        $line=$this->receipt->lines()->firstOrFail();
        \App\Models\Tenant\Item::query()->findOrFail($line->item_id)->update(['costing_method'=>'fifo']);
        $ledger=app(StockLedgerService::class);
        $ledger->post([new StockMovement(direction:'in',itemId:(int)$line->item_id,warehouseId:(int)$this->receipt->warehouse_id,
            quantity:'10',sourceType:GoodsReceipt::class,sourceId:(int)$this->receipt->id,sourceLineId:(int)$line->id,
            unitCost:'4',movedAt:'2026-09-09 09:00:00')],'test:purchase-cost:fifo-in');
        $ledger->post([new StockMovement(direction:'out',itemId:(int)$line->item_id,warehouseId:(int)$this->receipt->warehouse_id,
            quantity:'4',sourceType:\App\Models\Tenant\Shipment::class,sourceId:91,sourceLineId:92,movedAt:'2026-09-09 10:00:00')],
            'test:purchase-cost:fifo-out');
        app(FinancialLineAllocationService::class)->reserve($this->payload(9500,61,'2','10','10','5','50','0','0','50'));
        $input=['organization_mapping_uuid'=>$this->connection->mapping_uuid,'destination_document_id'=>9500,
            'destination_fingerprint'=>str_repeat('a',64),'currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1',
            'finance_money_scale'=>3,'discount_posting_mode'=>'net'];
        $plan=app(PurchaseCostAdjustmentService::class)->prepare($input);
        $byRole=collect($plan['components'])->groupBy('destination_role')->map(fn($rows)=>$rows->sum(fn($r)=>(float)$r['posted_base_amount']));
        $this->assertSame(4.0,$byRole['cogs']);$this->assertSame(6.0,$byRole['inventory_asset']);
        app(PurchaseCostAdjustmentService::class)->apply($input);
        $this->assertSame('30.00',(string)\App\Models\Tenant\StockBalance::query()->value('total_value'));
        $this->assertSame('5.0000',(string)\App\Models\Tenant\CostLayer::query()->value('unit_cost'));
    }

    private function payload(int $documentId, int $lineId, string $entered, string $base, string $destinationQty,
        string $destinationPrice, string $gross, string $lineDiscount, string $documentDiscount, string $net, string $exchangeRate='1', string $currency='JOD'): array
    {
        return [
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => $documentId,
            'destination_revision' => str_repeat('a', 64), 'destination_fingerprint' => str_repeat('a', 64),
            'allocation_kind' => 'bill', 'currency_code' => $currency, 'base_currency_code' => 'JOD', 'exchange_rate' => $exchangeRate,
            'allocations' => [[
                'source_document_mapping_uuid' => $this->source->mapping_uuid,
                'source_document_type' => 'goods_receipt', 'source_document_id' => $this->receipt->id,
                'source_line_id' => $this->lineId, 'stock_item_id' => $this->receipt->lines()->firstOrFail()->item_id,
                'destination_line_id' => $lineId, 'entered_quantity' => $entered, 'base_quantity' => $base,
                'destination_quantity' => $destinationQty, 'destination_unit_id' => 801,
                'destination_unit_price' => $destinationPrice, 'destination_gross' => $gross,
                'line_discount_allocated' => $lineDiscount, 'document_discount_allocated' => $documentDiscount,
                'destination_net' => $net,
            ]],
        ];
    }

    #[Test]
    public function paid_and_bonus_lines_reserve_one_physical_source_without_duplicate_quantity(): void
    {
        $input = $this->shipmentPayload('4');
        $paid = $input['allocations'][0];
        $paid['entered_quantity'] = $paid['base_quantity'] = $paid['destination_quantity'] = '7';
        $paid['destination_gross'] = $paid['destination_net'] = '28';
        $bonus = $input['allocations'][0];
        $bonus['destination_line_id'] = 902;
        $bonus['entered_quantity'] = $bonus['base_quantity'] = $bonus['destination_quantity'] = '3';
        $bonus['destination_unit_price'] = $bonus['destination_gross'] = $bonus['destination_net'] = '0';
        $input['allocations'] = [$paid, $bonus];
        $service = app(FinancialLineAllocationService::class);
        $result = $service->reserve($input);
        $again = $service->reserve($input);
        $this->assertSame(array_column($result['allocations'], 'allocation_uuid'), array_column($again['allocations'], 'allocation_uuid'));
        $this->assertSame('0.00000000', $result['allocations'][1]['destination_net']);
        $this->assertSame(10.0, (float) IntegrationFinancialLineAllocation::where('state', 'draft_reserved')->sum('base_quantity'));
        $this->assertSame(2, IntegrationFinancialLineAllocation::query()->count());
    }

    #[Test]
    public function recorded_fully_free_shipment_can_link_but_missing_source_price_cannot(): void
    {
        $input = $this->shipmentPayload('0');
        $result = app(FinancialLineAllocationService::class)->reserve($input);
        $this->assertSame('0.00000000', $result['allocations'][0]['source_unit_price']);
        $this->assertSame('0.00000000', $result['allocations'][0]['destination_net']);
        \App\Models\Tenant\SalesOrderLine::query()->delete();
        $input['destination_document_id']++;
        $this->expectException(ValidationException::class);
        app(FinancialLineAllocationService::class)->reserve($input);
    }

    private function shipmentPayload(string $price): array
    {
        $receiptLine = $this->receipt->lines()->firstOrFail();
        $order = \App\Models\Tenant\SalesOrder::create(['order_number' => 'ALLOC-SO', 'order_date' => '2024-06-19', 'warehouse_id' => $this->receipt->warehouse_id]);
        $orderLine = $order->lines()->create(['item_id' => $receiptLine->item_id, 'ordered_qty' => '10', 'unit_price' => $price]);
        $shipment = \App\Models\Tenant\Shipment::create(['shipment_number' => '00001', 'sales_order_id' => $order->id,
            'warehouse_id' => $this->receipt->warehouse_id, 'ship_date' => '2024-06-19', 'status' => 'posted', 'posted_at' => now()]);
        $line = $shipment->lines()->create(['sales_order_line_id' => $orderLine->id, 'item_id' => $receiptLine->item_id,
            'warehouse_id' => $this->receipt->warehouse_id, 'quantity' => '10', 'entered_qty' => '10',
            'entered_unit_id' => $receiptLine->base_unit_id, 'base_unit_id' => $receiptLine->base_unit_id,
            'unit_conversion_factor' => '1', 'unit_conversion_version' => 'v1', 'unit_conversion_hash' => hash('sha256', 'each-to-each'),
            'unit_conversion_precision' => 4, 'unit_conversion_rounding_mode' => 'HALF_UP']);
        $source = IntegrationDocumentLifecycleMapping::create([
            'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->connection->mapping_uuid,
            'central_client_id' => 77, 'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'source_application' => 'solastock', 'source_document_type' => 'shipment', 'source_document_id' => (string) $shipment->id,
            'lifecycle_status' => 'posted', 'shipped_qty' => '10', 'transaction_currency_code' => 'JOD', 'base_currency_code' => 'JOD',
            'exchange_rate' => '1', 'accounting_source_key' => 'shipment:alloc:1',
        ]);
        $payload = $this->payload(9600, 901, '10', '10', '10', $price, (string) ((float) $price * 10), '0', '0', (string) ((float) $price * 10));
        $payload['destination_document_type'] = 'customer_invoice';
        $payload['allocation_kind'] = 'invoice';
        $payload['allocations'][0] = array_replace($payload['allocations'][0], [
            'source_document_mapping_uuid' => $source->mapping_uuid, 'source_document_type' => 'shipment',
            'source_document_id' => $shipment->id, 'source_line_id' => $line->id,
        ]);
        return $payload;
    }
}
