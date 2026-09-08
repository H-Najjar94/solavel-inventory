<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryCurrencyRate;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\PurchaseOrder;
use App\Services\Integration\WorkflowCurrencyResolver;
use App\Services\Integration\WorkflowDocumentMappingService;
use App\Services\Integration\WorkflowMatchingService;
use App\Services\Integration\WorkflowPreviewService;
use App\Services\Integration\WorkflowValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class Phase3WorkflowContractTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $organizationMapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $this->organizationMapping = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(),
            'central_client_id' => 7,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14,
            'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2',
            'status' => 'verified_hold',
            'activation_state' => 'maintenance_hold',
            'base_currency_code' => 'JOD',
            'verified_at' => now(),
        ]);
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
            'mode' => 'paused',
            'solabooks_organization_id' => 14,
            'meta' => [
                'client_id' => 7,
                'central_organization_id' => TenantTestManager::ORG_A,
                'signing_key_id' => 'phase3-held-key',
                'finance_currency_contract' => [
                    'base_currency_code' => 'JOD',
                    'enabled_currency_codes' => ['JOD', 'USD', 'EUR', 'GBP', 'AED', 'SAR'],
                    'currency_precisions' => ['JOD' => 2, 'USD' => 2],
                    'money_scale' => 2,
                    'rate_scale' => 8,
                ],
            ],
        ]);
    }

    public function test_inventory_only_currency_requires_the_reviewed_base_valuation_contract(): void
    {
        $setting = IntegrationSetting::query()->firstOrFail();
        $meta = $setting->meta;
        $meta['finance_currency_contract']['inventory_valuation_basis'] = \App\Services\Integration\FinanceBaseValuation::BASIS;
        $setting->update(['meta' => $meta]);
        $document = (object) ['id' => 8800, 'organization_id' => TenantTestManager::ORG_A];
        $resolver = app(WorkflowCurrencyResolver::class);
        foreach (['stock_adjustment', 'stock_count', 'stock_transfer', 'opening_stock'] as $type) {
            $currency = $resolver->resolve($document, $type, '2026-09-07');
            $this->assertSame(['code' => 'JOD', 'exchange_rate' => '1', 'rate_date' => '2026-09-07', 'rate_source' => 'identity'], $currency);
        }
        try { $resolver->resolve($document, 'goods_receipt', '2026-09-07'); $this->fail('A receipt must not inherit base currency without document evidence.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('currency', $e->errors()); }
        unset($meta['finance_currency_contract']['inventory_valuation_basis']);
        $setting->update(['meta' => $meta]);
        $this->expectException(ValidationException::class);
        $resolver->resolve($document, 'stock_adjustment', '2026-09-07');
    }

    #[Test]
    public function currency_is_document_scoped_same_currency_identity_and_foreign_rate_is_dated(): void
    {
        $po = $this->purchaseOrder('JOD');
        $same = app(WorkflowCurrencyResolver::class)->resolve(
            $po, 'purchase_order', $po->order_date->toDateString()
        );
        $this->assertSame([
            'code' => 'JOD',
            'exchange_rate' => '1',
            'rate_date' => $po->order_date->toDateString(),
            'rate_source' => 'identity',
        ], $same);

        InventoryCurrencyRate::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'currency_code' => 'USD',
            'rate_to_base' => '1.41000000',
            'effective_date' => $po->order_date,
        ]);
        DB::connection('tenant')->table('exchange_rates')->insert([
            'organization_id' => 14, 'base_currency_code' => 'JOD', 'quote_currency_code' => 'USD',
            'rate' => '1.41000000', 'rate_date' => $po->order_date, 'source' => 'manual',
        ]);
        $po->update(['currency_code' => 'USD', 'integration_currency_code' => 'USD']);
        $foreign = app(WorkflowCurrencyResolver::class)->resolve(
            $po->fresh(), 'purchase_order', $po->order_date->toDateString()
        );
        $this->assertSame('USD', $foreign['code']);
        $this->assertSame('1.41000000', $foreign['exchange_rate']);
        $this->assertSame('manual', $foreign['rate_source']);

        $po->update(['currency_code' => 'CAD', 'integration_currency_code' => 'CAD']);
        $this->expectException(ValidationException::class);
        app(WorkflowCurrencyResolver::class)->resolve(
            $po->fresh(), 'purchase_order', $po->order_date->toDateString()
        );
    }

    #[Test]
    public function source_document_mapping_is_stable_idempotent_audited_and_preview_is_read_only(): void
    {
        $po = $this->purchaseOrder('JOD');
        $event = $this->event($po);
        $service = app(WorkflowDocumentMappingService::class);
        $mapping = $service->recordForEvent($event, $po);
        $auditCount = DB::connection('tenant')->table('integration_document_lifecycle_audits')->count();

        $po->update(['po_number' => 'PO-PH3-RENAMED']);
        $same = $service->recordForEvent($event, $po->fresh());
        $this->assertSame($mapping->mapping_uuid, $same->mapping_uuid);
        $this->assertSame((string) $po->id, $same->source_document_id);
        $this->assertGreaterThan($auditCount, DB::connection('tenant')->table('integration_document_lifecycle_audits')->count());

        $before = [
            'events' => IntegrationOutboxEvent::query()->count(),
            'mappings' => IntegrationDocumentLifecycleMapping::query()->count(),
            'audits' => DB::connection('tenant')->table('integration_document_lifecycle_audits')->count(),
        ];
        $preview = app(WorkflowPreviewService::class)->preview('purchase_order', $po->id);
        $this->assertSame('purchase_order', data_get($preview, 'operational_source.document_type'));
        $this->assertSame(0, data_get($preview, 'mutation.attempts'));
        $this->assertSame($before['events'], IntegrationOutboxEvent::query()->count());
        $this->assertSame($before['mappings'], IntegrationDocumentLifecycleMapping::query()->count());
        $this->assertSame($before['audits'], DB::connection('tenant')->table('integration_document_lifecycle_audits')->count());
    }

    #[Test]
    public function cross_organization_document_scope_fails_closed_and_mapping_cannot_be_deleted(): void
    {
        try {
            IntegrationDocumentLifecycleMapping::query()->create([
                'mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => $this->organizationMapping->mapping_uuid,
                'central_client_id' => 7,
                'central_organization_id' => TenantTestManager::ORG_B,
                'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
                'finance_organization_id' => 14,
                'solastock_organization_id' => TenantTestManager::ORG_B,
                'source_application' => 'solastock',
                'source_document_type' => 'purchase_order',
                'source_document_id' => '1',
                'lifecycle_status' => 'approved',
                'base_currency_code' => 'JOD',
            ]);
            $this->fail('Cross-organization lifecycle mapping must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $mapping = app(WorkflowDocumentMappingService::class)
            ->recordForEvent($event = $this->event($po = $this->purchaseOrder('JOD')), $po);
        $this->expectException(ValidationException::class);
        $mapping->delete();
    }

    #[Test]
    public function unresolved_connected_workflow_fails_before_document_or_event_mutation(): void
    {
        $po = $this->purchaseOrder('JOD');
        $po->update(['status' => 'draft']);
        $beforeEvents = IntegrationOutboxEvent::query()->count();
        try {
            app(WorkflowValidationService::class)
                ->assertOperationalDocumentReady($po->fresh('lines'), 'purchase_order.approved');
            $this->fail('Missing warehouse mapping must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mapping_review_required', $exception->getMessage());
        }
        $this->assertSame('draft', $po->fresh()->status);
        $this->assertSame($beforeEvents, IntegrationOutboxEvent::query()->count());
        $this->assertSame(0, (int) IntegrationOutboxEvent::query()->sum('attempts'));
    }

    #[Test]
    public function connected_workflow_without_verified_organization_mapping_fails_closed(): void
    {
        $po = $this->purchaseOrder('JOD');
        $this->organizationMapping->update([
            'status' => 'conflict',
            'activation_state' => 'maintenance_hold',
        ]);

        $this->expectException(ValidationException::class);
        app(WorkflowValidationService::class)
            ->assertOperationalDocumentReady($po->fresh('lines'), 'purchase_order.approved');
    }

    #[Test]
    public function goods_receipt_reversal_is_mapped_as_supplier_return_with_exact_stock_quantity(): void
    {
        $reversal = InventoryReversal::query()->create([
            'reversal_number' => 'REV-GRN-PH3',
            'source_type' => 'goods_receipt',
            'source_id' => 7001,
            'source_number' => 'GRN-7001',
            'reversal_date' => '2026-07-30',
            'status' => 'posted',
            'reason' => 'Return to supplier',
            'posted_guard_key' => 'goods_receipt:7001:reversal',
            'posted_at' => now(),
        ]);
        DB::connection('tenant')->table('stock_ledger')->insert([
            'organization_id' => TenantTestManager::ORG_A,
            'item_id' => 1,
            'warehouse_id' => 1,
            'direction' => 'out',
            'quantity' => '2.5000',
            'unit_cost' => '4.0000',
            'total_cost' => '10.00',
            'costing_method' => 'fifo',
            'source_type' => InventoryReversal::class,
            'source_id' => $reversal->id,
            'moved_at' => now(),
            'posted_at' => now(),
            'idempotency_key' => 'phase3:supplier-return:'.$reversal->id,
            'balance_qty_after' => 0,
            'balance_value_after' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = IntegrationOutboxEvent::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => (string) Str::uuid(),
            'integration' => 'solabooks',
            'event_type' => 'grn.reversed',
            'aggregate_type' => 'InventoryReversal',
            'aggregate_id' => $reversal->id,
            'aggregate_number' => $reversal->reversal_number,
            'occurred_at' => now(),
            'payload' => [
                'document_date' => '2026-07-30',
                'currency' => ['code' => 'JOD', 'exchange_rate' => '1'],
                'total_inventory_value_change' => '-10.00',
                'lines' => [],
            ],
            'status' => 'pending',
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'solabooks:grn.reversed:InventoryReversal:'.$reversal->id,
        ]);

        $mapping = app(WorkflowDocumentMappingService::class)->recordForEvent($event, $reversal);
        $preview = app(WorkflowPreviewService::class)->preview('inventory_reversal', $reversal->id);

        $this->assertSame('supplier_return', $mapping->source_document_type);
        $this->assertSame('2.5000', $mapping->returned_qty);
        $this->assertSame($mapping->mapping_uuid, data_get($preview, 'operational_source.mapping_uuid'));
        $this->assertSame(-2.5, (float) data_get($preview, 'quantity_effect.owned_qty'));
    }

    #[Test]
    public function partial_out_of_order_and_landed_cost_matching_are_explicit_and_read_only(): void
    {
        $matching = app(WorkflowMatchingService::class);
        $purchase = $matching->evaluate([
            'lifecycle' => 'purchasing',
            'ordered' => '10',
            'received' => '4',
            'billed' => '7',
            'returned' => '1',
        ], [
            'inventory_valuation' => '40',
            'financial_subtotal' => '44',
        ]);
        $this->assertSame('partially_matched', $purchase['matching_state']);
        $this->assertSame(
            ['bill_before_receipt', 'price_or_currency_difference'],
            collect($purchase['differences'])->pluck('code')->all()
        );
        $this->assertSame(0, $purchase['mutation']['inventory']);

        $sales = $matching->evaluate([
            'lifecycle' => 'sales',
            'ordered' => '10',
            'reserved' => '6',
            'shipped' => '3',
            'invoiced' => '5',
            'returned' => '1',
        ]);
        $this->assertSame('invoice_before_shipment', $sales['differences'][0]['code']);
        $this->assertSame('5.00', $matching->landedCost('10', '5', 'value')['remaining']);

        $this->expectException(ValidationException::class);
        $matching->evaluate([
            'lifecycle' => 'sales',
            'ordered' => '2',
            'shipped' => '3',
        ]);
    }

    public function test_foreign_receipt_and_different_currency_shipment_keep_one_base_cost_pool(): void
    {
        $setting = IntegrationSetting::query()->firstOrFail();
        $meta = $setting->meta;
        $meta['finance_currency_contract']['inventory_valuation_basis'] = \App\Services\Integration\FinanceBaseValuation::BASIS;
        $setting->update(['meta' => $meta]);
        $po = $this->purchaseOrder('USD');
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A]);
        foreach (['USD' => '1.41000000', 'GBP' => '1.10000000'] as $code => $rate) {
            DB::connection('tenant')->table('exchange_rates')->insert([
                'organization_id' => 14, 'base_currency_code' => 'JOD', 'quote_currency_code' => $code,
                'rate' => $rate, 'rate_date' => '2026-07-30', 'source' => 'manual',
            ]);
        }
        $unit = \App\Models\Tenant\Unit::create(['code' => 'BASE-FX', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = F::fifoItem(['base_unit_id' => $unit->id]);
        $identities = [['item', (string) $item->id], ['unit', (string) $unit->id], ['warehouse', (string) $po->warehouse_id]];
        foreach (['inventory_asset' => 100, 'grni' => 200, 'cogs' => 300] as $role => $accountId) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $accountId, 'organization_id' => 14, 'code' => (string) $accountId, 'name' => $role, 'type' => ['inventory_asset'=>'asset','grni'=>'liability','cogs'=>'expense'][$role], 'is_active'=>true,'is_postable'=>true]);
            $accountMapping = \App\Models\Tenant\IntegrationAccountMapping::create(['mapping_type' => $role, 'integration' => 'solabooks',
                'solabooks_account_id' => $accountId, 'status' => 'verified']);
            $identities[] = ['account_role', (string) $accountMapping->id];
        }
        foreach ($identities as $index => [$type, $id]) {
            \App\Models\Tenant\IntegrationMasterDataMapping::create([
                'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->organizationMapping->mapping_uuid,
                'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A,
                'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
                'entity_type' => $type, 'solastock_record_id' => $id, 'solabooks_record_id' => (string) (700 + $index), 'status' => 'verified',
            ]);
        }
        $poLine = $po->lines()->create(['item_id' => $item->id, 'ordered_qty' => '10', 'received_qty' => '0', 'unit_price' => '14.10']);
        $receipts = app(\App\Services\Documents\GoodsReceiptService::class);
        $receipt = $receipts->createDraft(['purchase_order_id' => $po->id, 'warehouse_id' => $po->warehouse_id,
            'receipt_date' => '2026-07-30'], [['purchase_order_line_id' => $poLine->id,
            'item_id' => $item->id, 'received_qty' => '5', 'accepted_qty' => '5', 'unit_cost' => '14.10']]);
        $receipts->post($receipt);
        $receipts->post($receipt->fresh());
        $movement = \App\Models\Tenant\StockLedger::query()->where('source_type', get_class($receipt))->where('source_id', $receipt->id)->sole();
        $this->assertSame('10.0000', $movement->unit_cost);
        $this->assertSame('50.00', $movement->total_cost);
        $this->assertSame('5.0000', $poLine->fresh()->received_qty);
        $event = IntegrationOutboxEvent::query()->where('event_type', 'grn.posted')->where('aggregate_id', $receipt->id)->sole();
        $journal = app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($event);
        $this->assertSame('70.50', $journal['lines'][0]['debit']);
        $this->assertSame('50.00', $journal['lines'][0]['base_debit']);
        $this->assertSame(['inventory_asset', 'grni'], array_column($journal['lines'], 'account_role'));
        $order = \App\Models\Tenant\SalesOrder::create(['order_number' => 'FX-SALE', 'order_date' => '2026-07-30',
            'warehouse_id' => $po->warehouse_id, 'currency_code' => 'GBP', 'integration_currency_code' => 'GBP', 'status' => 'confirmed']);
        $order->lines()->create(['item_id' => $item->id, 'ordered_qty' => '3', 'unit_price' => '11']);
        $physicalBefore = \App\Models\Tenant\StockLedger::query()->count();
        app(\App\Services\Integration\WorkflowValidationService::class)
            ->assertOperationalDocumentReady($order, 'sales_order.confirmed');
        $this->assertSame($physicalBefore, \App\Models\Tenant\StockLedger::query()->count(),
            'Validating a base-unit fulfillment order must not post inventory.');
        $shipments = app(\App\Services\Documents\ShipmentService::class);
        $shipment = $shipments->createDraft(['shipment_number' => 'FX-SHIPMENT', 'sales_order_id' => $order->id, 'warehouse_id' => $po->warehouse_id,
            'ship_date' => '2026-07-30'], [['item_id' => $item->id, 'quantity' => '2']]);
        $shipments->post($shipment);
        $shipments->post($shipment->fresh());
        $out = \App\Models\Tenant\StockLedger::query()->where('source_type', get_class($shipment))->where('source_id', $shipment->id)->sole();
        $this->assertSame('20.00', $out->total_cost);
        $outEvent = IntegrationOutboxEvent::query()->where('event_type', 'shipment.posted')->where('aggregate_id', $shipment->id)->sole();
        $outJournal = app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($outEvent);
        $this->assertSame('22.00', $outJournal['lines'][0]['debit']);
        $this->assertSame('20.00', $outJournal['lines'][0]['base_debit']);
        $this->assertSame(['cogs', 'inventory_asset'], array_column($outJournal['lines'], 'account_role'));
        $this->assertSame('3.0000', \App\Models\Tenant\StockBalance::query()->where('item_id', $item->id)->sum('on_hand_qty'));
        // A later full source return must reverse the original GBP snapshot;
        // no rate exists on the return date, and re-pricing would be incorrect.
        $returns = app(\App\Services\Documents\SalesReturnService::class);
        try {
            $returns->createDraft(['return_number' => 'FX-PARTIAL', 'shipment_id' => $shipment->id,
                'return_date' => '2026-07-31', 'reason' => 'Requested one of two shipped units'],
                [['item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'resellable']]);
            $this->fail('A partial source return must not silently become a full shipment reversal.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
            $this->assertSame(0, \App\Models\Tenant\SalesReturn::query()->where('return_number', 'FX-PARTIAL')->count());
        }
        $return = $returns->createDraft(['return_number' => 'FX-RETURN', 'shipment_id' => $shipment->id,
            'return_date' => '2026-07-31', 'reason' => 'Isolated source reversal'], []);
        $returns->post($return);
        $returns->post($return->fresh());
        $returnMovement = \App\Models\Tenant\StockLedger::query()->where('source_type', get_class($return))->where('source_id', $return->id)->sole();
        $this->assertSame('20.00', $returnMovement->total_cost);
        $returnEvent = IntegrationOutboxEvent::query()->where('event_type', 'sales_return.posted')->where('aggregate_id', $return->id)->sole();
        $returnJournal = app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($returnEvent);
        $this->assertSame($outJournal['currency'], $returnJournal['currency']);
        $this->assertSame('2026-07-31', $returnJournal['source']['transaction_date']);
        $this->assertSame('20.00', $returnJournal['lines'][0]['base_credit']);
        $this->assertSame(['cogs', 'inventory_asset'], array_column($returnJournal['lines'], 'account_role'));
        $this->assertSame('5.0000', \App\Models\Tenant\StockBalance::query()->where('item_id', $item->id)->sum('on_hand_qty'));

    }

    private function purchaseOrder(string $currency): PurchaseOrder
    {
        $warehouse = F::warehouse();

        return PurchaseOrder::query()->create([
            'po_number' => 'PO-PH3-'.Str::random(8),
            'order_date' => '2026-07-30',
            'warehouse_id' => $warehouse->id,
            'currency_code' => $currency,
            'integration_currency_code' => $currency,
            'status' => 'approved',
            'subtotal' => 10,
            'tax_total' => 0,
            'total' => 10,
        ]);
    }

    private function event(PurchaseOrder $po): IntegrationOutboxEvent
    {
        return IntegrationOutboxEvent::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => (string) Str::uuid(),
            'integration' => 'solabooks',
            'event_type' => 'purchase_order.approved',
            'aggregate_type' => 'PurchaseOrder',
            'aggregate_id' => $po->id,
            'aggregate_number' => $po->po_number,
            'occurred_at' => now(),
            'payload' => [
                'document_date' => $po->order_date->toDateString(),
                'currency' => ['code' => $po->integration_currency_code, 'exchange_rate' => '1'],
                'total_inventory_value_change' => '0',
                'lines' => [],
            ],
            'status' => 'ignored',
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'solabooks:purchase_order.approved:PurchaseOrder:'.$po->id,
        ]);
    }
}
