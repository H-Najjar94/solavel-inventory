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
        $po->update(['currency_code' => 'USD']);
        $foreign = app(WorkflowCurrencyResolver::class)->resolve(
            $po->fresh(), 'purchase_order', $po->order_date->toDateString()
        );
        $this->assertSame('USD', $foreign['code']);
        $this->assertSame('1.41000000', $foreign['exchange_rate']);
        $this->assertSame('solabooks_authoritative_snapshot', $foreign['rate_source']);

        $po->update(['currency_code' => 'CAD']);
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

    private function purchaseOrder(string $currency): PurchaseOrder
    {
        $warehouse = F::warehouse();

        return PurchaseOrder::query()->create([
            'po_number' => 'PO-PH3-'.Str::random(8),
            'order_date' => '2026-07-30',
            'warehouse_id' => $warehouse->id,
            'currency_code' => $currency,
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
                'currency' => ['code' => $po->currency_code, 'exchange_rate' => '1'],
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
