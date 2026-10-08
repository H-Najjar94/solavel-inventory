<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationPurchaseCostAdjustment;
use App\Models\Tenant\IntegrationPurchaseCostAdjustmentComponent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\PurchaseCostAdjustmentPlanner;
use App\Services\Stock\PurchaseCostAdjustmentService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class PurchaseCostHandoffRegressionTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $mapping;

    private $warehouse;

    private $item;

    private $supplier;

    private $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A, 'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $this->mapping = IntegrationOrganizationMapping::create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(), 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
        IntegrationSetting::create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14, 'meta' => ['client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'signing_key_id' => 'test-key', 'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD', 'USD'], 'currency_precisions' => ['JOD' => 2, 'USD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        $this->warehouse = F::warehouse();
        $this->unit = Unit::create(['code' => 'PUR-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $this->item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $this->supplier = Supplier::create(['code' => 'PUR-SUP', 'name' => 'Supplier', 'is_active' => true]);
        $this->master('item', $this->item->id, 701);
        $this->master('unit', $this->unit->id, 702);
        $this->master('supplier', $this->supplier->id, 703);
        foreach (['inventory_asset' => 100, 'grni' => 200] as $role => $id) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'code' => (string) $id, 'name' => $role, 'type' => $role === 'grni' ? 'liability' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $a = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $this->master('account_role', $a->id, $id);
        }
    }

    private function master($type, $stock, $finance): void
    {
        IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid, 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'entity_type' => $type, 'solastock_record_id' => (string) $stock, 'solabooks_record_id' => (string) $finance, 'status' => 'verified']);
    }

    public function test_average_receipt_price_difference_follows_remaining_and_consumed_value_without_quantity_changes(): void
    {
        $this->exerciseCostDifference('average');
    }

    public function test_fifo_receipt_price_difference_keeps_layer_and_consumption_provenance_and_reverses_once(): void
    {
        $this->exerciseCostDifference('fifo');
    }

    public function test_finance_only_average_cost_review_preserves_consumed_cogs_provenance(): void
    {
        $this->exerciseCostDifference('average', true);
    }

    public function test_finance_only_fifo_cost_review_preserves_consumed_cogs_provenance(): void
    {
        $this->exerciseCostDifference('fifo', true);
    }

    public function test_posted_settlement_average_provenance_uses_no_reservation_and_reverses_once(): void
    {
        $this->exerciseCostDifference('average', true, true);
    }

    public function test_posted_settlement_fifo_provenance_uses_no_reservation_and_reverses_once(): void
    {
        $this->exerciseCostDifference('fifo', true, true);
    }

    private function exerciseCostDifference(string $method, bool $financeOnly = false, bool $settlement = false): void
    {
        $this->item->update(['costing_method' => $method]);
        DB::connection('tenant')->table('exchange_rates')->insert(['organization_id' => 14, 'base_currency_code' => 'JOD', 'quote_currency_code' => 'USD', 'rate' => '2', 'rate_date' => '2026-10-06', 'source' => 'manual']);
        app(ReceivingRequestService::class)->upsert(['request_uuid' => (string) Str::uuid(), 'source_bill_id' => 800, 'source_bill_number' => 'COST-800', 'source_revision' => str_repeat('a', 64), 'supplier_external_id' => 703, 'currency_code' => 'USD', 'lines' => [['source_line_id' => 801, 'item_external_id' => 701, 'unit_external_id' => 702, 'quantity' => '10', 'unit_cost' => '10']]]);
        $request = ReceivingRequest::sole();
        $grn = app(GoodsReceiptService::class)->createDraft(['receiving_request_id' => $request->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'], [['receiving_request_line_id' => $request->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '10']]);
        app(GoodsReceiptService::class)->post($grn);
        $receipt = StockLedger::sole();
        $this->assertSame('5.0000', $receipt->unit_cost);
        $consumed = app(StockLedgerService::class)->post([new StockMovement(direction: 'out', itemId: $this->item->id, warehouseId: $this->warehouse->id, quantity: '4', sourceType: Shipment::class, sourceId: 900, sourceLineId: 901, movedAt: '2026-10-06')], 'qa-cost-consumed:900')[0];
        $this->assertSame('20.00', $consumed->total_cost);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('30.00', StockBalance::sole()->total_value);
        $life = IntegrationDocumentLifecycleMapping::where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $grn->id)->sole();
        $fingerprint = str_repeat('d', 64);
        // Persist real reviewed allocation evidence. The planner and transition services are native, never mocked.
        $allocation = new IntegrationFinancialLineAllocation(['allocation_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid, 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(), 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'source_document_mapping_uuid' => $life->mapping_uuid, 'source_document_type' => 'goods_receipt', 'source_document_id' => (string) $grn->id, 'source_line_id' => $grn->lines()->sole()->id, 'destination_document_type' => 'supplier_bill', 'destination_document_id' => 800, 'destination_line_id' => 801, 'allocation_kind' => 'bill', 'entered_quantity' => '10', 'entered_unit_id' => $this->unit->id, 'base_quantity' => '10', 'base_unit_id' => $this->unit->id, 'destination_quantity' => '10', 'destination_unit_id' => $this->unit->id, 'source_unit_price' => '10', 'destination_unit_price' => '12', 'source_gross' => '100', 'destination_gross' => '120', 'source_net' => '100', 'destination_net' => '120', 'price_difference' => '20', 'currency_code' => 'USD', 'base_currency_code' => 'JOD', 'exchange_rate' => '2', 'state' => 'draft_reserved', 'destination_revision' => $fingerprint, 'source_fingerprint' => str_repeat('e', 64), 'destination_fingerprint' => $fingerprint, 'idempotency_key' => 'qa-cost-allocation']);
        if ($settlement) {
            request()->attributes->set('posted_purchase_settlement_allocation', $allocation);
            request()->attributes->set('verified_workspace_action', 'purchasing.settlement.prepare');
            $this->assertSame(0, IntegrationFinancialLineAllocation::count());
        } else {
            $allocation->save();
        }

        $input = ['organization_mapping_uuid' => $this->mapping->mapping_uuid, 'destination_document_id' => 800, 'destination_fingerprint' => $fingerprint, 'currency_code' => 'USD', 'base_currency_code' => 'JOD', 'exchange_rate' => '2', 'finance_money_scale' => 2];
        if ($financeOnly) {
            request()->attributes->set('verified_workspace_action', $settlement ? 'purchasing.settlement.prepare' : 'purchasing.bill.cost-adjustment.prepare');
            request()->attributes->set('purchasing_authority', ['organization_mapping_uuid' => $this->mapping->mapping_uuid,
                'finance_organization_id' => 14, 'receipt_ids' => [$grn->id], 'receipt_mapping_uuids' => [$life->mapping_uuid]]);
            $warehouseScope = $this->createStub(WarehouseAccessService::class);
            $warehouseScope->method('allowedIds')->willReturn([]);
            // The actor cannot read physical Stock. Permit ordinary balance assertions,
            // while hiding all ledger provenance exactly as the live warehouse scope does.
            $warehouseScope->method('scope')->willReturnCallback(function ($query, $column = 'warehouse_id') {
                return $query->getModel() instanceof StockLedger ? $query->whereRaw('1 = 0') : $query;
            });
            $this->app->instance(WarehouseAccessService::class, $warehouseScope);
            $this->assertSame(0, StockLedger::count());
        }
        $service = app(PurchaseCostAdjustmentService::class);
        $plan = $service->prepare($input);
        $again = $service->prepare($input);
        $this->assertSame($plan['adjustment_uuid'], $again['adjustment_uuid']);
        $this->assertSame('10.00000000', $plan['exact_base_difference']);
        $parts = collect($plan['components'])->keyBy('destination_role');
        $this->assertSame('6.000000', $parts['inventory_asset']['posted_base_amount']);
        $this->assertSame('4.000000', $parts['cogs']['posted_base_amount']);
        $this->assertSame(900, $parts['cogs']['destination_source_id']);
        $component = IntegrationPurchaseCostAdjustmentComponent::where('destination_role', 'cogs')->sole();
        $this->assertSame($consumed->id, $component->stock_ledger_id);
        if ($method === 'fifo') {
            $this->assertNotEmpty($component->provenance['consumption_id']);
        } else {
            $this->assertSame($receipt->id, $component->provenance['average_replay_from_ledger_id']);
        }
        try {
            app(PurchaseCostAdjustmentPlanner::class)->plan(array_replace($input, ['exchange_rate' => '3']));
            $this->fail('Changed FX was absorbed into price variance');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FX differences', $e->getMessage());
        }
        // A later current FX update cannot rewrite the already-reviewed receipt or plan.
        DB::connection('tenant')->table('exchange_rates')->where('quote_currency_code', 'USD')->update(['rate' => '3']);
        $service->apply($input);
        $service->apply($input);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('36.00', StockBalance::sole()->total_value);
        $this->assertSame('6.0000', StockBalance::sole()->average_cost);
        if ($method === 'fifo') {
            $this->assertSame('6.0000', CostLayer::sole()->unit_cost);
        }
        $this->assertSame(2, StockLedger::withoutGlobalScope('warehouse_access')->count());
        $this->assertSame(1, IntegrationPurchaseCostAdjustment::count());
        $this->assertSame(2, IntegrationPurchaseCostAdjustmentComponent::count());
        $service->reverse($input);
        $service->reverse($input);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('30.00', StockBalance::sole()->total_value);
        $this->assertSame('5.0000', StockBalance::sole()->average_cost);
        if ($method === 'fifo') {
            $this->assertSame('5.0000', CostLayer::sole()->unit_cost);
        }
        $this->assertSame('5.0000', $receipt->fresh()->unit_cost);
        $this->assertSame('20.00', $consumed->fresh()->total_cost);
        $this->assertSame(2, StockLedger::withoutGlobalScope('warehouse_access')->count());
        $this->assertSame('reversed', IntegrationPurchaseCostAdjustment::sole()->state);
        $this->assertSame('2.000000000000', IntegrationPurchaseCostAdjustment::sole()->exchange_rate);
    }
}
