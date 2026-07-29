<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Services\Integration\IntegrationStatusService;
use App\Services\Integration\Phase2MappingDiscoveryService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class Phase2MappingSafetyTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('integration_safety.solabooks_delivery_enabled', false);
        config()->set('integration_safety.legacy_finance_inventory_writes_blocked', true);
        config()->set('integration_safety.legacy_journal_contract_enabled', false);
        config()->set('integration_safety.historical_repair_enabled', false);
        config()->set('integration_safety.pending_event_replay_enabled', false);
    }

    #[Test]
    public function immutable_mapping_is_required_for_truthful_connection_status(): void
    {
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
            'mode' => 'paused',
            'solabooks_organization_id' => 14,
        ]);

        $without = app(IntegrationStatusService::class)->status(TenantTestManager::ORG_A);
        $this->assertFalse($without['connection_implemented']);
        $this->assertSame('connected_pending_mapping', $without['mode']);

        $mapping = $this->organizationMapping();
        $with = app(IntegrationStatusService::class)->status(TenantTestManager::ORG_A);
        $this->assertTrue($with['connection_implemented']);
        $this->assertSame($mapping->mapping_uuid, $with['organization_mapping']['mapping_uuid']);
        $this->assertSame('solastock', $with['inventory_authority']['inventory_source']);
        $this->assertSame('solabooks', $with['inventory_authority']['accounting_source']);
        $this->assertSame('maintenance_hold', $with['health']);
    }

    #[Test]
    public function master_mapping_is_scope_checked_immutable_unique_and_survives_rename(): void
    {
        $mapping = $this->organizationMapping();
        $item = Item::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'sku' => 'PH2-OLD',
            'name' => 'Phase 2 item',
            'item_type' => 'inventory',
            'tracking_type' => 'none',
            'purchase_price' => 0,
            'sales_price' => 0,
            'is_active' => true,
        ]);
        $master = $this->masterMapping($mapping, (string) $item->id, '501');

        $item->update(['sku' => 'PH2-RENAMED', 'name' => 'Renamed Phase 2 item']);
        $this->assertSame((string) $item->id, $master->fresh()->solastock_record_id);
        $this->assertSame('501', $master->fresh()->solabooks_record_id);

        try {
            $master->update(['finance_organization_id' => 99]);
            $this->fail('Immutable scope changes must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $this->masterMapping($mapping, (string) $item->id, '502');
    }

    #[Test]
    public function cross_organization_master_mapping_fails_closed(): void
    {
        $mapping = $this->organizationMapping();

        $this->expectException(ValidationException::class);
        IntegrationMasterDataMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(),
            'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => $mapping->central_client_id,
            'central_organization_id' => TenantTestManager::ORG_B,
            'finance_organization_id' => $mapping->finance_organization_id,
            'solastock_organization_id' => TenantTestManager::ORG_B,
            'entity_type' => 'item',
            'solastock_record_id' => '1',
            'solabooks_record_id' => '2',
            'status' => 'mapped',
        ]);
    }

    #[Test]
    public function provisioned_v2_scope_cannot_bypass_delivery_hold_or_mutate_event(): void
    {
        $mapping = $this->organizationMapping();
        $mapping->update([
            'v2_key_scope_status' => 'provisioned_held',
            'current_v2_signing_key_id' => 123,
        ]);
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
            'mode' => 'paused',
            'solabooks_organization_id' => 14,
            'meta' => [
                'client_id' => 7,
                'central_organization_id' => TenantTestManager::ORG_A,
                'signing_key_id' => 'phase2-held-key',
                'signing_secret_encrypted' => Crypt::encryptString('not-output-by-any-command'),
                'contract_version' => 'solastock-journal.v2',
            ],
        ]);
        $event = IntegrationOutboxEvent::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'event_uuid' => (string) Str::uuid(),
            'integration' => 'solabooks',
            'event_type' => 'adjustment.posted',
            'aggregate_type' => 'StockAdjustment',
            'aggregate_id' => 98765,
            'aggregate_number' => 'PH2-HOLD',
            'occurred_at' => now(),
            'payload' => ['document_date' => now()->toDateString(), 'lines' => []],
            'status' => 'pending',
            'mapping_status' => 'complete',
            'attempts' => 0,
            'idempotency_key' => 'phase2:hold:98765',
        ]);
        Http::fake();

        try {
            app(SolaBooksOutboxDeliveryService::class)->deliver($event);
            $this->fail('Delivery must remain held with a v2-scoped key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('currency-contract', $exception->getMessage());
        }

        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame(0, $event->fresh()->attempts);
        Http::assertNothingSent();
    }

    #[Test]
    public function phase2_maintenance_mapping_and_authority_states_exist_in_english_arabic_and_rtl_ui(): void
    {
        $translations = file_get_contents(resource_path('js/solastock/i18n/settingsPages.js'));
        $page = file_get_contents(resource_path('js/solastock/pages/IntegrationSettingsPage.jsx'));
        $layout = file_get_contents(resource_path('views/solastock-app.blade.php'));

        $this->assertStringContainsString('Inventory and accounting authority', $translations);
        $this->assertStringContainsString('مرجعية المخزون والمحاسبة', $translations);
        $this->assertStringContainsString('Review required', $translations);
        $this->assertStringContainsString('يتطلب مراجعة', $translations);
        $this->assertStringContainsString('inventory_authority', $page);
        $this->assertStringContainsString('organization_mapping', $page);
        $this->assertStringContainsString('dir="{{ $dir }}"', $layout);
    }

    #[Test]
    public function discovery_is_read_only_and_backfill_adds_only_reviewed_exact_one_to_one_matches(): void
    {
        try {
            DB::connection('tenant')->statement(
            'CREATE TABLE organizations (
                id BIGINT UNSIGNED PRIMARY KEY,
                central_org_id BIGINT UNSIGNED NOT NULL
            )'
            );
            DB::connection('tenant')->statement(
            'CREATE TABLE inventory_items (
                id BIGINT UNSIGNED PRIMARY KEY,
                organization_id BIGINT UNSIGNED NOT NULL,
                sku VARCHAR(191) NULL,
                barcode VARCHAR(191) NULL,
                name VARCHAR(191) NULL,
                deleted_at TIMESTAMP NULL
            )'
            );
            DB::connection('tenant')->table('organizations')->insert([
            'id' => 14,
            'central_org_id' => TenantTestManager::ORG_A,
        ]);
        DB::connection('tenant')->table('inventory_items')->insert([
            ['id' => 501, 'organization_id' => 14, 'sku' => 'PH2-EXACT', 'barcode' => null, 'name' => 'Exact'],
            ['id' => 502, 'organization_id' => 14, 'sku' => 'BOOK-A', 'barcode' => null, 'name' => 'Ambiguous'],
            ['id' => 503, 'organization_id' => 14, 'sku' => 'BOOK-B', 'barcode' => null, 'name' => 'Ambiguous'],
        ]);
        Item::query()->insert([
            [
                'organization_id' => TenantTestManager::ORG_A, 'sku' => 'PH2-EXACT',
                'name' => 'Exact', 'item_type' => 'inventory', 'tracking_type' => 'none',
                'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'organization_id' => TenantTestManager::ORG_A, 'sku' => 'STOCK-AMB',
                'name' => 'Ambiguous', 'item_type' => 'inventory', 'tracking_type' => 'none',
                'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'organization_id' => TenantTestManager::ORG_A, 'sku' => 'STOCK-MISSING',
                'name' => 'Missing', 'item_type' => 'inventory', 'tracking_type' => 'none',
                'purchase_price' => 0, 'sales_price' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        IntegrationSetting::query()->create([
            'organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks',
            'mode' => 'paused',
            'solabooks_organization_id' => 14,
        ]);
        $organization = $this->organizationMapping();
        $service = app(Phase2MappingDiscoveryService::class);

        $before = [
            'master' => IntegrationMasterDataMapping::query()->count(),
            'runs' => DB::connection('tenant')->table('integration_mapping_discovery_runs')->count(),
            'results' => DB::connection('tenant')->table('integration_mapping_discovery_results')->count(),
        ];
        $report = $service->discover($organization->mapping_uuid);

        $this->assertTrue($report['read_only']);
        $this->assertSame($before['master'], IntegrationMasterDataMapping::query()->count());
        $this->assertSame($before['runs'], DB::connection('tenant')->table('integration_mapping_discovery_runs')->count());
        $this->assertSame($before['results'], DB::connection('tenant')->table('integration_mapping_discovery_results')->count());
        $this->assertContains(
            'exact_match',
            array_column($report['results'], 'classification'),
            json_encode($report['results'], JSON_UNESCAPED_SLASHES)
        );
        $this->assertContains('ambiguous_match', array_column($report['results'], 'classification'));
        $this->assertContains('missing_finance_record', array_column($report['results'], 'classification'));

        $applied = $service->applyDeterministic($organization->mapping_uuid, $report['manifest_hash']);
        $this->assertSame(1, $applied['created_mappings']);
        $this->assertGreaterThan(0, $applied['unresolved']);
        $this->assertSame(1, IntegrationMasterDataMapping::query()->where('entity_type', 'item')->count());
            $this->assertSame(
            $applied['unresolved'],
            DB::connection('tenant')->table('integration_mapping_discovery_results')
                ->where('run_uuid', $applied['run_uuid'])
                ->where('resolution_status', 'unresolved')
                ->count()
            );
        } finally {
            DB::connection('tenant')->table('integration_mapping_discovery_results')->delete();
            DB::connection('tenant')->table('integration_mapping_discovery_runs')->delete();
            DB::connection('tenant')->table('integration_mapping_audits')->delete();
            DB::connection('tenant')->table('integration_master_data_mappings')->delete();
            DB::connection('tenant')->table('integration_organization_mappings')->delete();
            IntegrationSetting::query()->where('organization_id', TenantTestManager::ORG_A)->delete();
            Item::query()->whereIn('sku', ['PH2-EXACT', 'STOCK-AMB', 'STOCK-MISSING'])->forceDelete();
            DB::connection('tenant')->statement('DROP TABLE IF EXISTS inventory_items');
            DB::connection('tenant')->statement('DROP TABLE IF EXISTS organizations');
        }
    }

    private function organizationMapping(): IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->create([
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
    }

    private function masterMapping(
        IntegrationOrganizationMapping $organization,
        string $stockId,
        string $booksId,
    ): IntegrationMasterDataMapping {
        return IntegrationMasterDataMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(),
            'organization_mapping_uuid' => $organization->mapping_uuid,
            'central_client_id' => $organization->central_client_id,
            'central_organization_id' => $organization->central_organization_id,
            'finance_organization_id' => $organization->finance_organization_id,
            'solastock_organization_id' => $organization->solastock_organization_id,
            'entity_type' => 'item',
            'solastock_record_id' => $stockId,
            'solabooks_record_id' => $booksId,
            'status' => 'mapped',
        ]);
    }
}
