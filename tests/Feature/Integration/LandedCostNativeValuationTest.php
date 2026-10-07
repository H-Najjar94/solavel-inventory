<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{IntegrationAccountMapping, IntegrationDocumentLifecycleMapping, IntegrationOutboxEvent, PurchaseValuationHold, Shipment, StockBalance, StockLedger, Supplier};
use App\Services\Documents\GoodsReceiptService;
use App\Services\Purchasing\{LandedCostAllocationAuthority, PurchaseValuationHoldService};
use App\Services\Stock\{PurchaseCostAdjustmentPlanner, PurchaseCostAdjustmentService, StockLedgerService, StockMovement};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{SalesHandoffFixture, StockTestFactory as F, TenantTestManager};
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native Stock valuation; Finance signed authorization/posted-JE projections are an explicit fixture boundary. */
final class LandedCostNativeValuationTest extends TestCase
{
    use TenantAware, SalesHandoffFixture;

    private array $landedPayload;
    private array $landedIdentity;
    private array $landedProof;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $schema = DB::connection('tenant')->getSchemaBuilder();
        if (!$schema->hasColumn('bills', 'journal_entry_id')) $schema->table('bills', fn (Blueprint $t) => $t->unsignedBigInteger('journal_entry_id')->nullable());
        foreach (['source', 'source_key', 'source_type'] as $column) if (!$schema->hasColumn('journal_entries', $column)) $schema->table('journal_entries', fn (Blueprint $t) => $t->string($column)->nullable());
        if (!$schema->hasColumn('journal_entries', 'source_id')) $schema->table('journal_entries', fn (Blueprint $t) => $t->unsignedBigInteger('source_id')->nullable());
        if (!$schema->hasColumn('journal_entries', 'reverses_entry_id')) $schema->table('journal_entries', fn (Blueprint $t) => $t->unsignedBigInteger('reverses_entry_id')->nullable());
        if (!$schema->hasTable('landed_costs')) $schema->create('landed_costs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('org_id'); $t->unsignedBigInteger('bill_id'); $t->date('date');
            $t->unsignedBigInteger('purchase_order_id')->nullable(); $t->unsignedBigInteger('currency_id')->nullable();
            $t->decimal('exchange_rate', 20, 12); $t->decimal('total_additional_cost', 20, 8);
            $t->string('status'); $t->unsignedBigInteger('journal_entry_id')->nullable(); $t->softDeletes();
        });
        if (!$schema->hasTable('landed_cost_lines')) $schema->create('landed_cost_lines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('landed_cost_id'); $t->unsignedBigInteger('expense_account_id'); $t->decimal('amount', 18, 2);
        });
        if (!$schema->hasTable('landed_cost_allocations')) $schema->create('landed_cost_allocations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('landed_cost_id'); $t->unsignedBigInteger('inventory_item_id'); $t->decimal('quantity', 15, 4); $t->decimal('allocated_amount', 18, 2);
        });
        if (!$schema->hasTable('finance_landed_cost_operations')) $schema->create('finance_landed_cost_operations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('organization_mapping_uuid'); $t->unsignedBigInteger('landed_cost_id');
            $t->uuid('operation_uuid'); $t->unsignedBigInteger('actor_id'); $t->unsignedBigInteger('bill_id'); $t->unsignedBigInteger('bill_journal_id');
            $t->string('state'); $t->char('payload_hash', 64); $t->json('payload'); $t->json('cost_plan')->nullable();
            $t->char('plan_fingerprint', 64)->nullable(); $t->unsignedBigInteger('journal_entry_id')->nullable(); $t->unsignedBigInteger('reversal_journal_id')->nullable();
        });
        if (!$schema->hasTable('finance_purchase_positions')) $schema->create('finance_purchase_positions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('organization_mapping_uuid'); $t->uuid('position_uuid');
            $t->unsignedBigInteger('bill_id'); $t->unsignedBigInteger('bill_journal_id'); $t->unsignedBigInteger('bill_line_id'); $t->string('state'); $t->json('snapshot');
        });
        if (!$schema->hasTable('finance_purchase_settlements')) $schema->create('finance_purchase_settlements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('organization_mapping_uuid'); $t->uuid('position_uuid');
            $t->unsignedBigInteger('receipt_id'); $t->unsignedBigInteger('receipt_line_id'); $t->uuid('receipt_mapping_uuid');
            $t->string('receipt_journal_key'); $t->string('state'); $t->decimal('quantity', 20, 8);
        });
        // DDL precedes every fixture fact; the native test helper reopens its rollback transaction.
        $this->tenantTestManager->cleanup();
        $this->initializeSalesFixture();
        $this->warehouse = F::warehouse();
        $supplier = Supplier::create(['code' => 'LANDED-QA', 'name' => 'Landed supplier', 'is_active' => true]);
        $this->master('supplier', $supplier->id, 704);
        \App\Models\Tenant\IntegrationSetting::create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14,
            'meta' => ['client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'signing_key_id' => 'private-test',
                'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'], 'money_scale' => 2,
                    'rate_scale' => 8, 'inventory_valuation_basis' => \App\Services\Integration\FinanceBaseValuation::BASIS]]]);
        foreach (['inventory_asset' => [100, 'asset'], 'grni' => [200, 'liability'], 'cogs' => [300, 'expense']] as $role => [$id, $type]) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'code' => (string) $id,
                'name' => $role, 'type' => $type, 'is_active' => true, 'is_postable' => true]);
            $account = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $this->master('account_role', $account->id, $id);
        }
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $this->warehouse->id, 'supplier_id' => $supplier->id, 'receipt_date' => '2026-10-07'],
            [['item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        app(StockLedgerService::class)->post([new StockMovement(direction: 'out', itemId: $this->item->id, warehouseId: $this->warehouse->id,
            quantity: '4', sourceType: Shipment::class, sourceId: 900, sourceLineId: 901, movedAt: '2026-10-07')], 'landed-native-consumed');
        $line = $receipt->lines()->sole();
        $life = IntegrationDocumentLifecycleMapping::where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $receipt->id)->sole();
        $journal = IntegrationOutboxEvent::where('event_type', 'grn.posted')->where('aggregate_id', $receipt->id)->sole();
        $position = (string) Str::uuid(); $uuid = (string) Str::uuid();
        $this->landedPayload = ['schema_version' => 'purchasing.landed_cost.v1', 'operation_uuid' => $uuid, 'landed_cost_id' => 700,
            'source_bill_id' => 800, 'bill_journal_id' => 96, 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'date' => '2026-10-07', 'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'exchange_rate' => '1',
            'amount' => '10', 'amount_base' => '10', 'finance_money_scale' => 2,
            'document_hash' => hash('sha256', json_encode(['bill_id' => 800, 'purchase_order_id' => null, 'date' => '2026-10-07', 'currency_id' => null,
                'exchange_rate' => '1.00000000', 'total' => '10.00', 'lines' => [[1, 300, '10.00']], 'allocations' => [[1, 701, '10', '10.00']]], JSON_THROW_ON_ERROR)),
            'sources' => [['position_uuid' => $position, 'bill_line_id' => 801, 'receipt_id' => $receipt->id, 'receipt_line_id' => $line->id,
                'receipt_mapping_uuid' => $life->mapping_uuid, 'receipt_journal_key' => $journal->idempotency_key,
                'item_external_id' => 701, 'unit_external_id' => 702, 'quantity' => '10', 'base_quantity' => '10',
                'base_unit_id' => $this->unit->id, 'unit_conversion_factor' => '1', 'delta_base' => '10']]];
        $db = DB::connection('tenant');
        $db->table('suppliers')->insert(['id' => 704, 'organization_id' => 14, 'name' => 'Canonical landed supplier']);
        $db->table('bills')->insert(['id' => 800, 'organization_id' => 14, 'supplier_id' => 704, 'status' => 'unpaid', 'journal_entry_id' => 96]);
        $db->table('landed_costs')->insert(['id' => 700, 'org_id' => 14, 'bill_id' => 800, 'date' => '2026-10-07', 'exchange_rate' => 1, 'total_additional_cost' => 10, 'status' => 'draft']);
        $db->table('landed_cost_lines')->insert(['id' => 1, 'landed_cost_id' => 700, 'expense_account_id' => 300, 'amount' => 10]);
        $db->table('landed_cost_allocations')->insert(['id' => 1, 'landed_cost_id' => 700, 'inventory_item_id' => 701, 'quantity' => 10, 'allocated_amount' => 10]);
        foreach ([95 => ['external-api:'.hash('sha256', $journal->idempotency_key), null, null], 96 => ['private-bill-800', 'App\\Models\\Bill', 800]] as $id => [$key, $type, $source])
            $db->table('journal_entries')->insert(['id' => $id, 'organization_id' => 14, 'number' => 'LANDED-'.$id, 'entry_date' => '2026-10-07',
                'source' => $id === 96 ? 'AP' : 'STOCK-GRN', 'source_key' => $key, 'source_type' => $type, 'source_id' => $source, 'status' => 'posted', 'posted_at' => now()]);
        $db->table('finance_purchase_positions')->insert(['organization_id' => 14, 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'position_uuid' => $position, 'bill_id' => 800, 'bill_journal_id' => 96, 'bill_line_id' => 801, 'state' => 'received',
            'snapshot' => json_encode(['item_external_id' => 701, 'unit_external_id' => 702])]);
        $db->table('finance_purchase_settlements')->insert(['organization_id' => 14, 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'position_uuid' => $position, 'receipt_id' => $receipt->id, 'receipt_line_id' => $line->id,
            'receipt_mapping_uuid' => $life->mapping_uuid, 'receipt_journal_key' => $journal->idempotency_key, 'state' => 'settled', 'quantity' => 10]);
        $json = json_encode($this->landedPayload);
        $db->table('finance_landed_cost_operations')->insert(['organization_id' => 14, 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'landed_cost_id' => 700, 'operation_uuid' => $uuid, 'actor_id' => 323, 'bill_id' => 800, 'bill_journal_id' => 96,
            'state' => 'prepared', 'payload' => $json, 'payload_hash' => hash('sha256', $json)]);
        $this->landedIdentity = ['operation_uuid' => $uuid, 'landed_cost_id' => 700, 'source_bill_id' => 800, 'bill_journal_id' => 96, 'direction' => 'forward'];
        $this->landedProof = ['operation' => 'prepare', 'actor_id' => 323, 'operation_uuid' => $uuid, 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'finance_organization_id' => 14, 'state' => 'prepared', 'payload_hash' => hash('sha256', $json), 'canonical_payload' => $this->landedPayload, 'direction' => 'forward'];
    }

    public function test_native_landed_quote_tracks_remaining_and_consumed_cost_without_invoice_price_reinterpretation(): void
    {
        $authority = LandedCostAllocationAuthority::fromLockedNativeProvenance($this->landedIdentity, $this->landedProof, $this->mapping, 'prepare', 323);
        $plan = app(PurchaseCostAdjustmentPlanner::class)->planLandedCost($authority);
        $parts = collect($plan['components'])->keyBy('destination_role');
        $this->assertSame(0, bccomp('6', $parts['inventory_asset']['posted_base_amount'], 6));
        $this->assertSame(0, bccomp('4', $parts['cogs']['posted_base_amount'], 6));
        $this->assertSame(900, $parts['cogs']['destination_source_id']);
        $this->assertSame($this->landedPayload['sources'][0]['receipt_line_id'], $parts['cogs']['receipt_line_id']);
        $prepared = app(PurchaseCostAdjustmentService::class)->prepareLandedCost($authority, $plan);
        $this->assertSame('prepared', $prepared['state']);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('30.00', StockBalance::sole()->total_value);
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(0, PurchaseValuationHold::count()); // Coordinator publishes the separate durable pool holds.
        $db = DB::connection('tenant');
        $fingerprint = str_repeat('e', 64);
        $holds = app(PurchaseValuationHoldService::class);
        $holds->lockItems([$this->item->id]);
        $hold = $holds->acquire(['settlement_uuid' => $authority->holdUuid($this->item->id, $this->warehouse->id),
            'purpose' => 'landed_apply', 'plan_revision' => 1, 'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id,
            'receipt_id' => $this->landedPayload['sources'][0]['receipt_id'], 'source_bill_id' => 800], $fingerprint);
        $db->table('journal_entries')->insert(['id' => 97, 'organization_id' => 14, 'number' => 'LANDED-97', 'entry_date' => '2026-10-07',
            'source' => 'AP-LANDED-COST', 'source_type' => 'App\\Models\\LandedCost', 'source_id' => 700,
            'source_key' => 'landed-cost:'.$authority->operationUuid(), 'status' => 'posted', 'posted_at' => now()]);
        $db->table('landed_costs')->where('id', 700)->update(['status' => 'posted', 'journal_entry_id' => 97]);
        $db->table('finance_landed_cost_operations')->where('operation_uuid', $authority->operationUuid())->update([
            'state' => 'valuation_pending', 'journal_entry_id' => 97, 'plan_fingerprint' => $fingerprint,
            'cost_plan' => json_encode(['plan_fingerprint' => $fingerprint, 'native_plan' => $plan])]);
        $applyProof = array_replace($this->landedProof, ['operation' => 'apply', 'state' => 'valuation_pending',
            'landed_cost_journal_id' => 97, 'plan_fingerprint' => $fingerprint]);
        $apply = LandedCostAllocationAuthority::fromLockedNativeProvenance($this->landedIdentity, $applyProof, $this->mapping, 'apply', 323);
        $service = app(PurchaseCostAdjustmentService::class);
        $this->assertSame('applied', $service->applyLandedCost($apply, $plan)['state']);
        $this->assertSame('applied', $service->applyLandedCost($apply, $plan)['state']);
        $hold->update(['state' => 'released']);
        $this->assertSame('36.00', StockBalance::sole()->total_value);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame(2, StockLedger::count());

        $reverseFingerprint = str_repeat('f', 64);
        $db->table('journal_entries')->insert(['id' => 98, 'organization_id' => 14, 'number' => 'LANDED-98', 'entry_date' => '2026-10-07',
            'source' => 'AP-LANDED-COST', 'source_type' => 'App\\Models\\LandedCost', 'source_id' => 700, 'reverses_entry_id' => 97,
            'source_key' => 'landed-cost-reversal:'.$authority->operationUuid(), 'status' => 'posted', 'posted_at' => now()]);
        $db->table('finance_landed_cost_operations')->where('operation_uuid', $authority->operationUuid())->update([
            'state' => 'valuation_reverse_pending', 'reversal_journal_id' => 98,
            'cost_plan' => json_encode(['plan_fingerprint' => $fingerprint, 'native_plan' => $plan,
                'reverse_plan' => ['plan_fingerprint' => $reverseFingerprint]])]);
        $reverseProof = array_replace($applyProof, ['operation' => 'reverse', 'direction' => 'reverse',
            'state' => 'valuation_reverse_pending', 'plan_fingerprint' => $reverseFingerprint, 'reversal_journal_id' => 98]);
        $reverse = LandedCostAllocationAuthority::fromLockedNativeProvenance(array_replace($this->landedIdentity, ['direction' => 'reverse']),
            $reverseProof, $this->mapping, 'reverse', 323);
        $inverseHold = $holds->acquire(['settlement_uuid' => $reverse->holdUuid($this->item->id, $this->warehouse->id, 'reverse'),
            'purpose' => 'landed_reverse', 'plan_revision' => 1, 'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id,
            'receipt_id' => $this->landedPayload['sources'][0]['receipt_id'], 'source_bill_id' => 800], $reverseFingerprint);
        $this->assertSame('reversed', $service->reverseLandedCost($reverse, $plan)['state']);
        $this->assertSame('reversed', $service->reverseLandedCost($reverse, $plan)['state']);
        $inverseHold->update(['state' => 'released']);
        $this->assertSame('30.00', StockBalance::sole()->total_value);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame(2, StockLedger::count());
    }

    public function test_locked_landed_factory_rejects_a_paused_mapping_without_valuation_or_physical_changes(): void
    {
        $this->mapping->update(['activation_state' => 'paused']);
        try {
            LandedCostAllocationAuthority::fromLockedNativeProvenance($this->landedIdentity, $this->landedProof, $this->mapping, 'prepare', 323);
            $this->fail('Paused mapping admitted landed valuation.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(2, StockLedger::count());
        $this->assertSame('30.00', StockBalance::sole()->total_value);
    }
}
