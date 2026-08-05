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
    public function individual_decision_returns_canonical_state_and_identical_retry_is_idempotent(): void
    {
        [, $item] = $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $beforeMutations = $this->mutationCounters();
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $candidate = collect($run['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');
        $beforeAuditCount = DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->count();

        $saved = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'approve_exact_binding', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['reason' => $candidate['blocking_reason']], 7001, $run['lock_version'],
            $candidate['candidate_before_hash'], true, false);

        $this->assertSame('saved', $saved['persistence_result']);
        $this->assertSame('approve_exact_binding', $saved['canonical_decision']['action']);
        $this->assertSame($candidate['fingerprint'], $saved['canonical_decision']['candidate_fingerprint']);
        $this->assertSame($run['lock_version'] + 1, $saved['lock_version']);
        $this->assertSame($beforeAuditCount + 1, DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->count());

        $again = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'approve_exact_binding', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['reason' => $candidate['blocking_reason']], 7001, $saved['lock_version'],
            $candidate['candidate_before_hash'], true, false);

        $this->assertSame('already_saved', $again['persistence_result']);
        $this->assertSame($saved['lock_version'], $again['lock_version']);
        $this->assertSame(1, (int) DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->value('decision_version'));
        $this->assertSame($beforeAuditCount + 1, DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->count());
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
        $this->assertSame($beforeMutations, $this->mutationCounters());
    }

    #[Test]
    public function physical_count_is_versioned_draft_metadata_and_never_mutates_stock(): void
    {
        [, $item] = $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $candidate = collect($run['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');

        $saved = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'approve_exact_binding', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['reason' => $candidate['blocking_reason'], 'physical_quantity' => '17.2500',
                'physical_count_reference' => 'wizard_draft'], 7001, $run['lock_version'],
            $candidate['candidate_before_hash'], true, false);

        $this->assertSame('17.2500', $saved['canonical_decision']['safe_details']['physical_quantity']);
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame($before, $this->mutationCounters());
    }

    #[Test]
    public function wizard_ui_contract_has_english_arabic_rtl_responsive_and_no_legacy_fallback(): void
    {
        $page = file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx'));
        $assistant = file_get_contents(resource_path('js/solastock/components/GuidedConnectionAssistant.jsx'));
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
        foreach (['recommendedChoice', 'saveRecommendationsAndContinue', 'saveAccountingRecommendationsAndContinue', 'recommendationWillSave', 'type="checkbox"'] as $contract) {
            $this->assertStringContainsString($contract, $assistant.$translations);
        }
        $this->assertStringNotContainsString("window.location.assign('/inventory/dashboard')", $assistant);
        $this->assertStringContainsString('Add this category to the connection plan', $translations);
        $this->assertStringContainsString('إضافة التصنيف إلى خطة الربط', $translations);
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
        foreach (['Check configuration', 'Complete required decisions', 'Review and approve', 'Advanced details'] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        foreach ([
            'التحقق من الإعدادات', 'مراجعة العناصر المطلوبة', 'المراجعة والموافقة',
            'تفاصيل فنية متقدمة', 'البضاعة المستلمة غير المفوترة', 'أرباح تسوية المخزون',
            'خسائر تسوية المخزون', 'تاريخ بدء الربط', 'الجرد الفعلي', 'فرق غير مفسر',
        ] as $label) {
            $this->assertStringContainsString($label, $translations);
        }
        foreach (['حل الاستثناءات', 'مرشح ربط SKU', 'بصمة التحقق من مصدر Finance', 'مستندات وقت القطع'] as $forbiddenArabic) {
            $this->assertStringNotContainsString($forbiddenArabic, $translations);
        }
        foreach (['focus-header', 'focus-stage', 'focus-question', 'focus-choices', 'focus-footer',
            'integration.focus.steps.business', 'integration.focus.steps.count',
            'integration.focus.steps.accountant', 'integration.focus.steps.startDate'] as $needle) {
            $this->assertStringContainsString($needle, $assistant);
        }
        $this->assertStringContainsString('const [task, setTask]', $assistant);
        $this->assertStringContainsString('physical_quantity', $assistant);
        $this->assertStringContainsString('undoDecision', $assistant);
        $this->assertStringContainsString('connectionActivated && <Tabs', $page);
        $this->assertStringContainsString("(!connectionActivated || tab === 'wizard')", $page);
        $this->assertStringContainsString('<bdi>', $assistant);
        $this->assertStringContainsString('No, they are different items', $translations);
        $this->assertStringContainsString('لا، هما صنفان مختلفان', $translations);
        $this->assertStringContainsString('تنتظر محاسباً مخولاً', $translations);
        $this->assertStringContainsString('max-width:1440px', $styles);
        $this->assertStringContainsString('grid-template-columns:1fr 1fr', $styles);
        $this->assertStringNotContainsString('source_account_id', $assistant);
        $this->assertStringNotContainsString('signing', strtolower($assistant));
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
    public function deterministic_bulk_confirmation_is_versioned_audited_optimistically_locked_and_idempotent(): void
    {
        [, $item] = $this->seedConnectionFixture(false);
        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $preview = $wizard->finalPreview(TenantTestManager::ORG_A, $run['run_uuid']);
        $candidate = collect($preview['comparison'])->first(fn (array $row) => $row['entity_type'] === 'item'
            && $row['classification'] === 'exact_candidate_requires_owner_review');
        $this->assertNotNull($candidate);

        $result = $wizard->bulkDecide(TenantTestManager::ORG_A, $run['run_uuid'],
            'approve_exact_sku_candidates', [$candidate['fingerprint']], 'CONFIRM 1 RECORDS', 7001,
            $preview['lock_version'], true);
        $auditCount = DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->count();
        $decision = DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->where('candidate_fingerprint', $candidate['fingerprint'])->first();
        $this->assertSame('approve_exact_binding', $decision->action);
        $this->assertSame(1, (int) $decision->decision_version);
        $this->assertSame(1, DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->where('action', 'confirmed_bulk_decision')->count());

        $again = $wizard->bulkDecide(TenantTestManager::ORG_A, $run['run_uuid'],
            'approve_exact_sku_candidates', [$candidate['fingerprint']], 'CONFIRM 1 RECORDS', 7001,
            $result['lock_version'], true);
        $this->assertSame('already_saved', $again['bulk_result']);
        $this->assertSame($auditCount, DB::connection('tenant')->table('integration_connection_wizard_audits')
            ->where('run_uuid', $run['run_uuid'])->count());
        $this->assertSame(1, (int) DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->value('decision_version'));
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
        $this->assertSame($before, $this->mutationCounters());
    }

    #[Test]
    public function final_polish_ui_contract_keeps_status_setup_and_draft_actions_safe(): void
    {
        $page = file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx'));
        $assistant = file_get_contents(resource_path('js/solastock/components/GuidedConnectionAssistant.jsx'));
        $translations = file_get_contents(resource_path('js/solastock/i18n/settingsPages.js'));

        foreach (['CompactIntegrationStatus', 'wizardResumeStep', 'connection-status-list', 'connectionActivated'] as $needle) {
            $this->assertStringContainsString($needle, $page.$assistant.$translations);
        }
        foreach (['Result preview', 'معاينة نتيجة الربط', 'Saved automatically', 'تم الحفظ',
            'Item in SolaBooks', 'الصنف في SolaBooks', 'Proposed match in SolaStock',
            'المطابقة المقترحة في SolaStock', 'The item name and SKU match in both systems.',
            'الاسم ورمز الصنف متطابقان في النظامين.'] as $copy) {
            $this->assertStringContainsString($copy, $translations);
        }
        $this->assertStringContainsString('focus-check-list', $assistant);
        $this->assertStringContainsString('bulkReviewOpen', $assistant);
        foreach (['items', 'units', 'categories', 'customers', 'suppliers', 'warehouses', 'currencies'] as $section) {
            $this->assertStringContainsString("integration.focus.section.{$section}", $assistant.$translations);
        }
        $this->assertLessThan(
            strpos($assistant, "['items', itemRows]"),
            strpos($assistant, "['units', ownerRows.filter"),
            'Units must appear before items in the review order.'
        );
        $this->assertLessThan(
            strpos($assistant, "['items', itemRows]"),
            strpos($assistant, "['categories', ownerRows.filter"),
            'Categories must appear before items in the review order.'
        );
        $this->assertStringContainsString('focus-category-nav', $assistant);
        $this->assertStringContainsString('focus-active-section', $assistant);
        $this->assertStringContainsString('focus-item-comparison', $assistant);
        $this->assertStringContainsString('integration.assistant.originalFinanceItem', $assistant.$translations);
        $this->assertStringContainsString('integration.assistant.proposedStockItem', $assistant.$translations);
        $this->assertStringContainsString('focus-item-catalog-summary', $assistant);
        $this->assertStringContainsString('integration.focus.itemsInBoth', $assistant.$translations);
        $this->assertStringContainsString('integration.focus.itemsBooksOnly', $assistant.$translations);
        $this->assertStringContainsString('integration.focus.itemsStockOnly', $assistant.$translations);
        $service = file_get_contents(app_path('Services/Integration/ConnectionWizardService.php'));
        $this->assertStringContainsString("'propose_unit_creation'", $service);
        $this->assertStringContainsString("'affected_items' => \$affected", $service);
        $this->assertStringContainsString('integration.focus.missingFinanceUnit', $assistant.$translations);
        $this->assertStringContainsString('integration.focus.proposeCategoryCreation', $assistant.$translations);
        $this->assertStringContainsString('wizard_selected_record_required', $service);
        $this->assertStringContainsString('valid_for_current_candidate', $service.$page);
        $this->assertStringContainsString('focus-affected-items', $assistant);
        $this->assertStringContainsString('integration.focus.reviewAffectedItems', $assistant.$translations);
        $this->assertStringContainsString('dangling_finance_item_unit_reference', $service.$assistant);
        $this->assertStringContainsString('integration.focus.missingUnitInventoryWarning', $assistant.$translations);
        $this->assertStringContainsString("setOpenOwnerSection(section)", $assistant);
        $this->assertStringContainsString('setOpenOwnerSection', $assistant);
        $this->assertStringContainsString('expected_lock_version', $page);
        $this->assertStringContainsString('createSerializedMutationQueue', $page);
        $this->assertStringContainsString('unwrapCanonicalDraft', $page);
        $this->assertStringContainsString('confirmedDecisions', $assistant);
        $this->assertStringContainsString('wizard_save_confirmation_mismatch', file_get_contents(app_path('Services/Integration/ConnectionWizardService.php')));
        $this->assertStringContainsString('return array_merge($approvalCore', file_get_contents(app_path('Services/Integration/ConnectionWizardService.php')));
        $this->assertStringContainsString("saveState === 'conflict'", $assistant);
        $this->assertStringContainsString('aria-modal="true"', $assistant);
        $this->assertStringNotContainsString('approvalAvailable &&', $assistant);
        $this->assertStringNotContainsString('activateIntegration', $assistant);
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
        try {
            $wizard->decide(
                TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
                'classify_inventory_item', [], [(string) $booksId], [], 7001,
                $preview['lock_version'], $candidate['candidate_before_hash'], true, false,
            );
            $this->fail('The redundant internal inventory classification must not be offered or accepted.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
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
    public function finance_only_item_exposes_one_inventory_creation_decision_not_duplicate_internal_actions(): void
    {
        $page = file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx'));
        $service = file_get_contents(app_path('Services/Integration/ConnectionWizardService.php'));
        $expected = "missing_solastock_record: ['create_solastock_record', 'classify_service_non_inventory', 'exclude_initial_connection', 'physical_count_required', 'retain_blocked']";
        $this->assertStringContainsString($expected, $page);
        $this->assertStringContainsString("'missing_solastock_record' => ['create_solastock_record', 'classify_service_non_inventory', 'exclude_initial_connection', 'physical_count_required', 'retain_blocked']", $service);
        $this->assertStringNotContainsString("missing_solastock_record: ['create_solastock_record', 'classify_inventory_item'", $page);
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

    #[Test]
    public function unit_conversion_requires_a_complete_equation_and_remains_an_idempotent_draft_only_decision(): void
    {
        $this->seedConnectionFixture(false);
        DB::connection('tenant')->table('units')->insert([
            'id' => 7001, 'organization_id' => 14, 'code' => 'BOX', 'name' => 'Box',
            'symbol' => 'box', 'kind' => 'count', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('inventory_items')->insert([
            'organization_id' => 14, 'sku' => 'BOXED-ITEM', 'name' => 'Boxed item',
            'unit_id' => 7001, 'qty_on_hand' => 0, 'average_cost' => 0,
            'valuation_method' => 'average', 'tracking_type' => 'none',
        ]);

        $wizard = app(ConnectionWizardService::class);
        $before = $this->mutationCounters();
        $run = $wizard->start(TenantTestManager::ORG_A, 7001);
        $candidate = collect($run['comparison'])->first(fn (array $row) =>
            $row['entity_type'] === 'unit' && (string) ($row['solabooks']['id'] ?? '') === '7001'
        );
        $this->assertNotNull($candidate);
        $target = collect($candidate['safe_details']['available_stock_units'])->firstWhere('name', 'Each');
        $this->assertNotNull($target);
        $this->assertContains('define_unit_conversion', (new \ReflectionMethod($wizard, 'allowedActionsForCandidate'))->invoke($wizard, $candidate));

        foreach (['', '0', '-2'] as $invalidFactor) {
            try {
                $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
                    'define_unit_conversion', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
                    ['selected_record_id' => (string) $target['id'], 'conversion_factor' => $invalidFactor],
                    7001, $run['lock_version'], $candidate['candidate_before_hash'], true, false);
                $this->fail('An incomplete or invalid conversion must not be saved.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }

        $saved = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'define_unit_conversion', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['selected_record_id' => (string) $target['id'], 'conversion_factor' => '12'],
            7001, $run['lock_version'], $candidate['candidate_before_hash'], true, false);
        $this->assertSame('12', $saved['canonical_decision']['safe_details']['conversion_factor']);
        $this->assertSame('Each', $saved['canonical_decision']['safe_details']['target_unit_name']);
        $this->assertSame('solabooks_to_solastock', $saved['canonical_decision']['safe_details']['conversion_direction']);

        $again = $wizard->decide(TenantTestManager::ORG_A, $run['run_uuid'], $candidate['fingerprint'],
            'define_unit_conversion', $candidate['solastock_record_ids'], $candidate['solabooks_record_ids'],
            ['selected_record_id' => (string) $target['id'], 'conversion_factor' => '12'],
            7001, $saved['lock_version'], $candidate['candidate_before_hash'], true, false);
        $this->assertSame('already_saved', $again['persistence_result']);
        $this->assertSame(1, DB::connection('tenant')->table('integration_connection_wizard_decisions')
            ->where('run_uuid', $run['run_uuid'])->where('candidate_fingerprint', $candidate['fingerprint'])->count());
        $this->assertSame(0, IntegrationMasterDataMapping::query()->count());
        $this->assertSame($before, $this->mutationCounters());
    }

    #[Test]
    public function an_exact_unit_match_does_not_offer_a_redundant_conversion(): void
    {
        $wizard = app(ConnectionWizardService::class);
        $candidate = [
            'entity_type' => 'unit',
            'solastock' => ['id' => '1', 'name' => 'Each'],
            'safe_details' => ['available_stock_units' => [['id' => '1', 'name' => 'Each']]],
        ];
        $actions = (new \ReflectionMethod($wizard, 'allowedActionsForCandidate'))->invoke($wizard, $candidate);
        $this->assertSame(['select_unit', 'retain_blocked'], $actions);
        $this->assertNotContains('define_unit_conversion', $actions);
    }

    #[Test]
    public function guided_setup_auto_resolves_finance_authority_and_keeps_only_real_exceptions(): void
    {
        $rows = [
            $this->guidedRow('account_role', 'candidate_requires_accountant_approval', 'account-ok', ['10'], ['role' => 'inventory_asset']),
            $this->guidedRow('account_role', 'unresolved_account_role', 'account-missing', [], ['role' => 'grni']),
            $this->guidedRow('tax', 'owner_review_required', 'tax-ok', ['20'], ['active' => true]),
            $this->guidedRow('currency', 'owner_review_required', 'jod', ['30'], ['active' => true, 'configured_rate' => '1.000000'], 'JOD'),
            $this->guidedRow('currency', 'owner_review_required', 'xts', ['31'], ['active' => true, 'configured_rate' => '1.000000'], 'XTS'),
            $this->guidedRow('currency', 'owner_review_required', 'xxx', ['32'], ['active' => true, 'configured_rate' => '1.000000'], 'XXX'),
        ];
        $method = new \ReflectionMethod(ConnectionWizardService::class, 'guidedSetup');
        $profile = $method->invoke(app(ConnectionWizardService::class), $rows, 'JOD', 0);

        $this->assertSame(1, $profile['checks']['accounts_resolved']);
        $this->assertSame(1, $profile['checks']['account_exceptions']);
        $this->assertSame(1, $profile['checks']['taxes_resolved']);
        $this->assertSame(['JOD'], $profile['currency_summary']['operational']);
        $this->assertSame(2, $profile['currency_summary']['advanced_remediation_count']);
        $this->assertSame(['XTS', 'XXX'], $profile['currency_summary']['reserved_codes']);
        $this->assertSame(['account-missing'], $profile['exception_groups']['accounting']);
        $this->assertCount(3, $profile['automatic_bindings']);
        $this->assertSame('new_both', $profile['customer_scenario']);
        $this->assertFalse($profile['operational_mutation_allowed']);

        $changed = $rows;
        $changed[0]['solabooks_record_ids'] = ['11'];
        $changedProfile = $method->invoke(app(ConnectionWizardService::class), $changed, 'JOD', 0);
        $this->assertNotSame($profile['source_invalidation_hash'], $changedProfile['source_invalidation_hash']);
    }

    #[Test]
    public function guided_setup_detects_each_customer_history_scenario_without_mutation(): void
    {
        $method = new \ReflectionMethod(ConnectionWizardService::class, 'guidedSetup');
        $base = $this->guidedRow('item', 'owner_review_required', 'scenario', [], []);

        $finance = $base;
        $finance['solabooks_record_ids'] = ['10'];
        $finance['solabooks'] = ['id' => '10', 'quantity' => '2.0000', 'inventory_value' => '5.00'];
        $this->assertSame('finance_first', $method->invoke(app(ConnectionWizardService::class), [$finance], 'JOD', 0)['customer_scenario']);

        $stock = $base;
        $stock['solastock_record_ids'] = ['20'];
        $stock['solastock'] = ['id' => '20', 'quantity' => '2.0000', 'inventory_value' => '5.00'];
        $this->assertSame('stock_first', $method->invoke(app(ConnectionWizardService::class), [$stock], 'JOD', 0)['customer_scenario']);

        $both = array_replace($finance, ['solastock_record_ids' => ['20'], 'solastock' => $stock['solastock']]);
        $this->assertSame('previously_separate', $method->invoke(app(ConnectionWizardService::class), [$both], 'JOD', 0)['customer_scenario']);
        $this->assertSame('new_both', $method->invoke(app(ConnectionWizardService::class), [$base], 'JOD', 0)['customer_scenario']);
    }

    #[Test]
    public function multiple_finance_account_candidates_are_an_exception_and_never_auto_selected(): void
    {
        $this->seedConnectionFixture(false);
        DB::connection('tenant')->table('accounts')->insert([
            ['id' => 9101, 'organization_id' => 14, 'code' => 'INV-A', 'name' => 'Inventory asset A', 'type' => 'asset', 'is_active' => 1, 'is_postable' => 1],
            ['id' => 9102, 'organization_id' => 14, 'code' => 'INV-B', 'name' => 'Inventory asset B', 'type' => 'asset', 'is_active' => 1, 'is_postable' => 1],
        ]);

        $preview = app(ConnectionWizardService::class)->discover(TenantTestManager::ORG_A);
        $inventoryRole = collect($preview['comparison'])->first(fn (array $row) =>
            $row['entity_type'] === 'account_role'
            && ($row['safe_details']['role'] ?? null) === 'inventory_asset'
        );

        $this->assertSame('ambiguous_account_role', $inventoryRole['classification']);
        $this->assertSame(2, $inventoryRole['safe_details']['candidate_count']);
        $this->assertSame([], $inventoryRole['solabooks_record_ids']);
        $this->assertContains($inventoryRole['fingerprint'], $preview['guided_setup']['exception_groups']['accounting']);
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

    private function guidedRow(string $entityType, string $classification, string $fingerprint,
        array $financeIds, array $details, ?string $code = null): array
    {
        return [
            'fingerprint' => $fingerprint,
            'entity_type' => $entityType,
            'classification' => $classification,
            'solastock_record_ids' => [],
            'solabooks_record_ids' => $financeIds,
            'solastock' => null,
            'solabooks' => $financeIds === [] ? null : ['id' => $financeIds[0], 'code' => $code],
            'quantity_difference' => '0.0000',
            'value_difference' => '0.00',
            'blocking_reason' => 'review_required',
            'mapping_confidence' => 'review_required',
            'decision_class' => $entityType === 'account_role' ? 'accountant_decision' : 'owner_decision',
            'safe_details' => $details,
        ];
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
