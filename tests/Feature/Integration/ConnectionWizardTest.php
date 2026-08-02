<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Services\Integration\ConnectionWizardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class ConnectionWizardTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
    }

    #[Test]
    public function discovery_decisions_and_approval_are_immutable_audited_and_do_not_activate_delivery(): void
    {
        [$mapping, $item] = $this->seedConnectionFixture();
        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();

        $discovery = $wizard->discover(TenantTestManager::ORG_A);
        $this->assertTrue($discovery['read_only']);
        $this->assertSame('0.0000', $discovery['totals']['total_quantity_difference']);
        $this->assertSame('0.00', $discovery['totals']['total_valuation_difference']);
        $this->assertSame($before, $this->mutationCounters());

        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $preview = $wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid']);
        foreach ($preview['blocking'] as $candidate) {
            $preview = $wizard->decide(
                TenantTestManager::ORG_A,
                $run['run_uuid'],
                $candidate['fingerprint'],
                'exclude_initial_connection',
                $candidate['solastock_record_ids'],
                $candidate['solabooks_record_ids'],
                ['reason' => 'explicit_fixture_review'],
                7001,
            );
        }

        $this->assertSame('ready_for_approval', $preview['state']);
        $approved = $wizard->approve(
            TenantTestManager::ORG_A,
            $run['run_uuid'],
            $preview['approval_payload_hash'],
            'CONNECT SOLASTOCK AS INVENTORY AUTHORITY',
            7001,
        );
        $this->assertSame('approved_maintenance_hold', $approved['state']);
        $this->assertNull($approved['activated_at']);
        $this->assertSame('maintenance_hold', $mapping->fresh()->activation_state);
        $this->assertGreaterThanOrEqual(2, DB::connection('tenant')->table('integration_connection_wizard_audits')->where('run_uuid', $run['run_uuid'])->count());
        $this->assertSame($before, $this->mutationCounters());

        try {
            $wizard->activate(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'STAGING-UAT-APPROVAL', 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);
            $this->fail('The default safety hold must block activation.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }

        config()->set('integration_connection_wizard.activation_enabled', true);
        config()->set('integration_connection_wizard.activation_organization_allowlist', [TenantTestManager::ORG_A]);
        config()->set('integration_connection_wizard.activation_approval_id', 'STAGING-UAT-APPROVAL');
        config()->set('integration_connection_wizard.receiver_confirmed_enabled', true);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('integration_safety.legacy_journal_contract_enabled', false);
        config()->set('integration_safety.historical_repair_enabled', false);
        config()->set('integration_safety.pending_event_replay_enabled', false);
        $active = $wizard->activate(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'STAGING-UAT-APPROVAL', 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);
        $this->assertSame('connected', $active['state']);
        $this->assertTrue((bool) IntegrationSetting::query()->where('organization_id', TenantTestManager::ORG_A)->first()->meta['transport_enabled']);
        $auditCount = DB::connection('tenant')->table('integration_connection_wizard_audits')->where('run_uuid', $run['run_uuid'])->count();
        $this->assertSame('connected', $wizard->activate(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'STAGING-UAT-APPROVAL', 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001)['state']);
        $this->assertSame($auditCount, DB::connection('tenant')->table('integration_connection_wizard_audits')->where('run_uuid', $run['run_uuid'])->count());
        $this->assertSame('paused', $wizard->pause(TenantTestManager::ORG_A, $run['run_uuid'], 'STAGING-UAT-APPROVAL', 7001)['state']);
        $this->assertFalse((bool) IntegrationSetting::query()->where('organization_id', TenantTestManager::ORG_A)->first()->meta['transport_enabled']);
        $this->assertSame($before, $this->mutationCounters());
    }

    #[Test]
    public function wizard_ui_contract_has_english_arabic_rtl_responsive_and_no_legacy_fallback(): void
    {
        $page = file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx'));
        $translations = file_get_contents(resource_path('js/solastock/i18n/settingsPages.js'));
        $styles = file_get_contents(resource_path('js/solastock/styles/solastock.css'));

        foreach (['Not subscribed', 'Discovery running', 'Ready for approval', 'Connected with blocked records', 'Maintenance hold'] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        foreach (['غير مشترك', 'جارٍ الاكتشاف', 'جاهز للموافقة', 'متصل مع سجلات محظورة', 'توقف أمان للصيانة'] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        $this->assertStringContainsString('legacy finance inventory', strtolower($translations));
        $this->assertStringContainsString('exportComparison', $page);
        $this->assertStringContainsString('approval_payload_hash', $page);
        $this->assertStringContainsString('@media (max-width: 820px)', $styles);
        $this->assertStringContainsString('html[dir="rtl"]', $styles);
    }

    #[Test]
    public function frozen_cutoff_column_has_no_implicit_update_behavior(): void
    {
        $column = DB::connection('tenant')->selectOne(
            "SELECT DATA_TYPE data_type, EXTRA extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_connection_wizard_runs' AND COLUMN_NAME = 'cutoff_at'"
        );

        $this->assertSame('datetime', strtolower((string) $column->data_type));
        $this->assertStringNotContainsString('on update', strtolower((string) $column->extra));
    }

    #[Test]
    public function approved_bind_decision_materializes_one_stable_mapping_without_stock_quantity_mutation(): void
    {
        [$mapping, $item] = $this->seedConnectionFixture();
        $wizard = app(ConnectionWizardService::class);
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $candidate = collect($wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid'])['comparison'])
            ->first(fn (array $row) => $row['entity_type'] === 'item' && $row['classification'] === 'exact_match');
        $this->assertNotNull($candidate);
        $preview = $wizard->decide(
            TenantTestManager::ORG_A,
            $run['run_uuid'],
            $candidate['fingerprint'],
            'bind_existing',
            $candidate['solastock_record_ids'],
            $candidate['solabooks_record_ids'],
            ['reason' => 'authorized_exact_identity_review'],
            7001,
        );
        foreach ($preview['blocking'] as $blocked) {
            if ($blocked['fingerprint'] === $candidate['fingerprint']) {
                continue;
            }
            $preview = $wizard->decide(
                TenantTestManager::ORG_A,
                $run['run_uuid'],
                $blocked['fingerprint'],
                'exclude_initial_connection',
                $blocked['solastock_record_ids'],
                $blocked['solabooks_record_ids'],
                ['reason' => 'explicit_fixture_review'],
                7001,
            );
        }
        $wizard->approve(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);
        $this->openActivationGate();

        $wizard->activate(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'STAGING-UAT-APPROVAL', 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);

        $stable = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', 'item')->first();
        $this->assertNotNull($stable);
        $this->assertSame((string) $item->id, (string) $stable->solastock_record_id);
        $this->assertSame((string) $candidate['solabooks_record_ids'][0], (string) $stable->solabooks_record_id);
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
    }

    #[Test]
    public function approved_create_decision_creates_zero_stock_record_and_never_imports_finance_quantity(): void
    {
        [$mapping] = $this->seedConnectionFixture();
        $booksId = DB::connection('tenant')->table('inventory_items')->insertGetId([
            'organization_id' => 14, 'sku' => 'FINANCE-ONLY', 'name' => 'Finance-only item',
            'qty_on_hand' => '37.0000', 'average_cost' => '12.500000', 'valuation_method' => 'fifo',
            'tracking_type' => 'none',
        ]);
        $wizard = app(ConnectionWizardService::class);
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $preview = $wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid']);
        $candidate = collect($preview['comparison'])->first(
            fn (array $row) => $row['entity_type'] === 'item'
                && $row['classification'] === 'missing_solastock_record'
                && $row['solabooks_record_ids'] === [(string) $booksId]
        );
        $this->assertNotNull($candidate);
        $preview = $wizard->decide(
            TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'create_solastock_record', [], [(string) $booksId], ['reason' => 'authorized_create_without_stock_import'], 7001,
        );
        foreach ($preview['blocking'] as $blocked) {
            if ($blocked['fingerprint'] === $candidate['fingerprint']) {
                continue;
            }
            $preview = $wizard->decide(
                TenantTestManager::ORG_A, $run['run_uuid'], $blocked['fingerprint'],
                'exclude_initial_connection', $blocked['solastock_record_ids'], $blocked['solabooks_record_ids'],
                ['reason' => 'explicit_fixture_review'], 7001,
            );
        }
        $wizard->approve(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);
        $this->openActivationGate();
        $wizard->activate(TenantTestManager::ORG_A, $run['run_uuid'], $preview['approval_payload_hash'], 'STAGING-UAT-APPROVAL', 'CONNECT SOLASTOCK AS INVENTORY AUTHORITY', 7001);

        $stable = IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('entity_type', 'item')->where('solabooks_record_id', (string) $booksId)->first();
        $this->assertNotNull($stable);
        $created = Item::query()->where('id', $stable->solastock_record_id)->firstOrFail();
        $this->assertSame('FINANCE-ONLY', $created->sku);
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $created->id)->count());
    }

    private function seedConnectionFixture(): array
    {
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A]);
        DB::connection('tenant')->table('accounts')->insert([
            'id' => 9001, 'organization_id' => 14, 'code' => 'UAT-CTRL', 'name' => 'UAT control', 'is_active' => 1, 'is_postable' => 1,
        ]);
        $item = Item::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'sku' => 'WIZARD-ITEM', 'name' => 'Wizard item', 'item_type' => 'inventory',
            'tracking_type' => 'none', 'costing_method' => 'average',
            'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
        ]);
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks',
            'mode' => 'paused', 'solabooks_organization_id' => 14,
        ]);
        $mapping = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 860001,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified_hold',
            'activation_state' => 'maintenance_hold', 'base_currency_code' => 'JOD',
            'v2_key_scope_status' => 'provisioned_held', 'current_v2_signing_key_id' => 6001,
            'currency_verified_at' => now(), 'verified_at' => now(),
        ]);
        $roles = [
            'inventory_asset', 'cogs', 'grni', 'opening_offset', 'adjustment_gain', 'adjustment_loss',
            'landed_cost_clearing', 'transfer_clearing', 'accounts_receivable', 'accounts_payable',
            'input_tax', 'output_tax', 'rounding',
            'sales_revenue',
        ];
        foreach ($roles as $role) {
            DB::connection('tenant')->table('integration_account_mappings')->insert([
                'organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks',
                'mapping_type' => $role, 'solabooks_account_id' => '9001', 'status' => 'verified',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$mapping, $item];
    }

    private function openActivationGate(): void
    {
        config()->set('integration_connection_wizard.activation_enabled', true);
        config()->set('integration_connection_wizard.activation_organization_allowlist', [TenantTestManager::ORG_A]);
        config()->set('integration_connection_wizard.activation_approval_id', 'STAGING-UAT-APPROVAL');
        config()->set('integration_connection_wizard.receiver_confirmed_enabled', true);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('integration_safety.legacy_journal_contract_enabled', false);
        config()->set('integration_safety.historical_repair_enabled', false);
        config()->set('integration_safety.pending_event_replay_enabled', false);
    }

    private function mutationCounters(): array
    {
        return [
            'events' => DB::connection('tenant')->table('integration_outbox_events')->count(),
            'attempts' => (int) DB::connection('tenant')->table('integration_outbox_events')->sum('attempts'),
            'transitions' => DB::connection('tenant')->table('integration_outbox_transition_audits')->count(),
            'heartbeats' => DB::connection('tenant')->table('integration_transport_worker_heartbeats')->count(),
        ];
    }
}
