<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Services\Integration\ConnectionWizardService;
use App\Services\Integration\IntegrationStatusService;
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
    public function canonical_authenticated_wizard_path_is_registered_without_weakening_permission_guard(): void
    {
        $router = file_get_contents(resource_path('js/solastock/router/router.jsx'));
        $web = file_get_contents(base_path('routes/web.php'));
        $navigation = file_get_contents(resource_path('js/solastock/router/nav.js'));

        $this->assertStringContainsString("path: 'integrations/solabooks'", $router);
        $this->assertStringContainsString("protectedElement(<IntegrationSettingsPage />, 'inventory.integration.view')", $router);
        $this->assertStringContainsString("Route::view('/integrations/{any?}', 'solastock-app')", $web);
        $this->assertStringContainsString("path: '/integrations/solabooks'", $navigation);
        $this->assertStringContainsString("useCanCreate('inventory.integration.setup')", file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx')));
    }

    #[Test]
    public function dual_product_readiness_allows_an_audited_draft_before_mapping_or_cutoff(): void
    {
        $this->seedCentralIdentity();
        DB::connection('tenant')->table('organizations')->insert([
            'id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
        ]);
        Item::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'sku' => 'PREMAP-1', 'name' => 'Pre-map candidate', 'item_type' => 'inventory',
            'tracking_type' => 'none', 'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
        ]);

        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();
        $readiness = $wizard->discover(TenantTestManager::ORG_A);

        $this->assertSame('setup_available', $readiness['connection_state']);
        $this->assertTrue($readiness['read_only']);
        $this->assertFalse($readiness['activation_available']);
        $this->assertNull($readiness['organization_mapping_uuid']);
        $this->assertSame($before, $this->mutationCounters());

        $draft = $wizard->start(TenantTestManager::ORG_A, 7001);
        $this->assertSame('draft_decisions', $draft['state']);
        $this->assertNull(DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('run_uuid', $draft['run_uuid'])->value('cutoff_at'));
        $this->assertNull(DB::connection('tenant')->table('integration_connection_wizard_runs')
            ->where('run_uuid', $draft['run_uuid'])->value('organization_mapping_uuid'));
        $this->assertSame(1, DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $draft['run_uuid'])->where('action', 'draft_started')->count());
        $this->assertSame($before, $this->mutationCounters());
    }

    #[Test]
    public function owner_and_accountant_draft_decisions_are_role_scoped_versioned_and_optimistically_locked(): void
    {
        [, $item] = $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $preview = $wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid']);
        $candidate = collect($preview['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');
        $this->assertNotNull($candidate);
        $preview = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'approve_exact_binding', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['reason' => 'owner_exact_sku_review'], 7001, $preview['lock_version'], $candidate['candidate_before_hash'], true, false);
        $decision = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->where('candidate_fingerprint', $candidate['fingerprint'])->first();
        $this->assertSame('owner', $decision->reviewer_role);
        $this->assertSame(1, (int) $decision->decision_version);

        $account = collect($preview['comparison'])->firstWhere('entity_type', 'account_role');
        $this->assertNotNull($account);
        try {
            $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $account['fingerprint'],
                'retain_account_role_unresolved', [], $account['solabooks_record_ids'], [], 7001,
                $preview['lock_version'], $account['candidate_before_hash'], true, false);
            $this->fail('An owner must not write an accountant decision.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
        $preview = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $account['fingerprint'],
            'retain_account_role_unresolved', [], $account['solabooks_record_ids'], [], 7002,
            $preview['lock_version'], $account['candidate_before_hash'], false, true);
        $this->assertSame('accountant', DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->where('candidate_fingerprint', $account['fingerprint'])->value('reviewer_role'));
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame((string) $item->id, (string) Item::query()->firstOrFail()->id);
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
        foreach ([
            'Setup', 'Draft', 'Activation', 'Delivery', 'Available', 'In progress',
            'Safely paused', 'Disabled',
            'You can complete connection setup. Activation and delivery remain safely paused until review and approval are complete.',
        ] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        foreach ([
            'الإعداد', 'المسودة', 'التفعيل', 'الإرسال', 'متاح', 'قيد الإعداد',
            'متوقف بأمان', 'معطل',
            'يمكنك إكمال إعداد الربط. التفعيل والإرسال متوقفان بأمان حتى اكتمال المراجعة والموافقة.',
        ] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        $this->assertStringContainsString('ConnectionPhaseBadges', $page);
        $this->assertStringNotContainsString('<HealthBadge health={s.health}', $page);
    }

    #[Test]
    public function status_reports_setup_and_draft_independently_from_activation_and_delivery_holds(): void
    {
        $this->seedConnectionFixture(false);
        config()->set('integration_safety.solabooks_delivery_enabled', false);
        config()->set('integration_connection_wizard.activation_enabled', false);
        request()->attributes->set('tenant_state', ['client_id' => 860001]);
        DB::connection('mysql')->table('entitlement_state_snapshots')->updateOrInsert(
            ['organization_id' => TenantTestManager::ORG_A],
            [
                'subscription_id' => null,
                'underlying_subscription_state' => 'paid_active',
                'effective_access_state' => 'paid_active',
                'state_hash' => hash('sha256', 'wizard-status-setup-only'),
                'state_payload' => json_encode([
                    'client_id' => 860001,
                    'organization_id' => TenantTestManager::ORG_A,
                    'integration_capabilities' => [
                        'connection_setup_readiness' => true,
                        'connection_activation_delivery_entitled' => false,
                        'reason' => 'setup_available_delivery_not_entitled',
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'evaluated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        app(ConnectionWizardService::class)->start(TenantTestManager::ORG_A, 7001);

        $status = app(IntegrationStatusService::class)->status(TenantTestManager::ORG_A);

        $this->assertSame('available', $status['setup_status']);
        $this->assertSame('in_progress', $status['draft_status']);
        $this->assertSame('safely_paused', $status['activation_status']);
        $this->assertSame('disabled', $status['delivery_status']);
        $this->assertFalse($status['delivery_enabled']);
        $this->assertSame('draft_decisions', $status['connection_wizard']['state']);
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
    public function exact_binding_draft_never_materializes_a_mapping_or_stock_mutation(): void
    {
        [, $item] = $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $preview = $wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid']);
        $candidate = collect($preview['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');
        $this->assertNotNull($candidate);
        $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'approve_exact_binding', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'], [],
            7001, $preview['lock_version'], $candidate['candidate_before_hash'], true, false);
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
    }

    #[Test]
    public function create_record_draft_does_not_create_stock_or_import_finance_quantity(): void
    {
        $this->seedConnectionFixture(false);
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
        $wizard->decide(
            TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'create_solastock_record', [], [(string) $booksId], ['reason' => 'authorized_create_proposal_only'], 7001,
            $preview['lock_version'], $candidate['candidate_before_hash'], true, false,
        );
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertFalse(Item::query()->where('sku', 'FINANCE-ONLY')->exists());
        $this->assertSame('37.0000', (string) DB::connection('tenant')->table('inventory_items')->where('id', $booksId)->value('qty_on_hand'));
    }

    #[Test]
    public function organization_switching_and_invalid_direct_actions_fail_closed(): void
    {
        $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $draft = $wizard->start(TenantTestManager::ORG_A, 7001);
        $candidate = collect($draft['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');
        $this->assertNotNull($candidate);

        try {
            $wizard->show(TenantTestManager::ORG_B, $draft['run_uuid']);
            $this->fail('A draft must not be visible after organization switching.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
        try {
            $wizard->decide(TenantTestManager::ORG_A, $draft['run_uuid'], $candidate['fingerprint'],
                'select_account_role', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'], [],
                7001, $draft['lock_version'], $candidate['candidate_before_hash'], true, false);
            $this->fail('A direct API call must not apply an action from another candidate type.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $draft['run_uuid'])->count());
    }

    #[Test]
    public function correction_preview_reconciles_stock_authority_to_inventory_control_gl_not_legacy_item_value(): void
    {
        $service = app(ConnectionWizardService::class);
        $method = new \ReflectionMethod($service, 'accountingEffectFromValues');
        $effect = $method->invoke($service, 9002, '777.00', '5385.00', 'JOD');

        $this->assertSame('4608.00', $effect['amount']);
        $this->assertSame('5385.00', $effect['inventory_control_current']);
        $this->assertSame('777.00', $effect['authoritative_stock_target']);
        $this->assertSame('inventory_asset_candidate', $effect['credit']);
        $this->assertSame('accountant_selected_cutoff_offset', $effect['debit']);
        $this->assertFalse($effect['automatic_posting']);
    }

    #[Test]
    public function dangling_finance_unit_identity_is_visible_and_remains_an_owner_blocker(): void
    {
        $this->seedConnectionFixture(false);
        DB::connection('tenant')->table('inventory_items')->insert([
            'organization_id' => 14, 'sku' => 'UNIT-REVIEW', 'name' => 'Unit review item',
            'unit_id' => 4242, 'qty_on_hand' => 0, 'average_cost' => 0,
            'valuation_method' => 'average', 'tracking_type' => 'none',
        ]);

        $preview = app(ConnectionWizardService::class)->discover(TenantTestManager::ORG_A);
        $unit = collect($preview['comparison'])->first(fn (array $row) =>
            $row['entity_type'] === 'unit'
            && ($row['safe_details']['source'] ?? null) === 'dangling_finance_item_unit_reference'
        );
        $this->assertNotNull($unit);
        $this->assertSame(['4242'], $unit['solabooks_record_ids']);
        $this->assertSame('owner_unit_selection_required', $unit['blocking_reason']);
        $this->assertTrue($unit['safe_details']['authoritative_unit_details_required']);
    }

    private function seedConnectionFixture(bool $withMapping = true): array
    {
        $this->seedCentralIdentity();
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A]);
        $unitId = DB::connection('tenant')->table('units')->insertGetId([
            'organization_id' => TenantTestManager::ORG_A, 'code' => 'EA', 'name' => 'Each',
            'symbol' => 'ea', 'kind' => 'count', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = DB::connection('tenant')->table('item_categories')->insertGetId([
            'organization_id' => TenantTestManager::ORG_A, 'name' => 'Reviewed goods',
            'level' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('warehouses')->insert([
            'organization_id' => TenantTestManager::ORG_A, 'code' => 'OWNER-MAIN',
            'name' => 'Owner selected warehouse', 'type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('inventory_settings')->insert([
            'organization_id' => TenantTestManager::ORG_A, 'default_costing_method' => 'average',
            'allow_negative_stock' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('accounts')->insert([
            'id' => 9001, 'organization_id' => 14, 'code' => 'UAT-CTRL', 'name' => 'UAT control', 'is_active' => 1, 'is_postable' => 1,
        ]);
        $item = Item::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'sku' => 'WIZARD-ITEM', 'name' => 'Wizard item', 'item_type' => 'inventory',
            'tracking_type' => 'none', 'costing_method' => 'average',
            'base_unit_id' => $unitId, 'category_id' => $categoryId,
            'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
        ]);
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks',
            'mode' => 'paused', 'solabooks_organization_id' => 14,
        ]);
        $mapping = $withMapping ? IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 860001,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified_hold',
            'activation_state' => 'maintenance_hold', 'base_currency_code' => 'JOD',
            'v2_key_scope_status' => 'provisioned_held', 'current_v2_signing_key_id' => 6001,
            'currency_verified_at' => now(), 'verified_at' => now(),
        ]) : null;
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

    private function seedCentralIdentity(): void
    {
        DB::connection('mysql')->table('organizations')->updateOrInsert(
            ['id' => TenantTestManager::ORG_A],
            ['central_organization_id' => TenantTestManager::ORG_A, 'client_id' => 860001,
                'name' => 'Disposable wizard organization', 'database_name' => 'solastock_test_a',
                'base_currency' => 'JOD', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
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
