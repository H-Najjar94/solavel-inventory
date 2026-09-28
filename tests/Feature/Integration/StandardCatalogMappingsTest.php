<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Services\Integration\ConnectionWizardService;
use App\Services\Integration\Phase2MappingDiscoveryService;
use App\Services\Integration\StandardCatalogMappings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Standard units/categories both apps ship with never become review tasks; real conflicts still do. */
final class StandardCatalogMappingsTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        DB::connection('tenant')->table('organizations')->updateOrInsert(['id' => 14], ['central_org_id' => TenantTestManager::ORG_A]);
        IntegrationSetting::query()->create(['organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks',
            'mode' => 'connected_pending_mapping', 'solabooks_organization_id' => 14]);
        $this->mapping = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => (string) DB::connection('tenant')->getDatabaseName(), 'finance_organization_id' => 14,
            'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified_hold', 'activation_state' => 'maintenance_hold', 'base_currency_code' => 'JOD', 'verified_at' => now(),
        ]);
        DB::connection('tenant')->table('units')->where('organization_id', TenantTestManager::ORG_A)->delete();
        DB::connection('tenant')->table('item_categories')->where('organization_id', TenantTestManager::ORG_A)->delete();
        DB::connection('tenant')->table('inventory_units')->delete();
        DB::connection('tenant')->table('inventory_categories')->delete();
        // SolaCount: standard defaults shared by the tenant (organization_id NULL), as provisioned.
        foreach ([['Piece', 'pcs'], ['Box', 'bx'], ['Kilogram', 'kg']] as [$name, $symbol]) {
            DB::connection('tenant')->table('inventory_units')->insert(['organization_id' => null, 'name' => $name, 'symbol' => $symbol, 'created_at' => now(), 'updated_at' => now()]);
        }
        $electrical = DB::connection('tenant')->table('inventory_categories')->insertGetId(['organization_id' => null, 'name' => 'Electrical', 'level' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('inventory_categories')->insert(['organization_id' => null, 'name' => 'Cables', 'parent_id' => $electrical, 'level' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('inventory_categories')->insert(['organization_id' => null, 'name' => 'Raw Materials', 'level' => 1, 'created_at' => now(), 'updated_at' => now()]);
        // SolaStock: its own per-organization copy (different local ids).
        foreach ([['Piece', 'pcs', 'count'], ['Box', 'bx', 'count'], ['Kilogram', 'kg', 'weight']] as [$name, $symbol, $kind]) {
            DB::connection('tenant')->table('units')->insert(['organization_id' => TenantTestManager::ORG_A, 'code' => strtoupper($symbol), 'name' => $name, 'symbol' => $symbol, 'kind' => $kind, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $stockElectrical = DB::connection('tenant')->table('item_categories')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'name' => 'Electrical', 'level' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('item_categories')->insert(['organization_id' => TenantTestManager::ORG_A, 'name' => 'Cables', 'parent_id' => $stockElectrical, 'level' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('item_categories')->insert(['organization_id' => TenantTestManager::ORG_A, 'name' => 'Raw Materials', 'level' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function mapped(string $type): int
    {
        return IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $this->mapping->mapping_uuid)->where('entity_type', $type)->count();
    }

    #[Test]
    public function fresh_defaults_map_once_by_canonical_key_and_retries_change_nothing(): void
    {
        $this->assertSame(['unit' => 3, 'category' => 3], app(StandardCatalogMappings::class)->ensure($this->mapping, 5));
        $this->assertSame(['unit' => 0, 'category' => 0], app(StandardCatalogMappings::class)->ensure($this->mapping, 5));
        $this->assertSame(3, $this->mapped('unit'));
        $this->assertSame(3, $this->mapped('category'));
        // Hierarchy preserved: SolaStock "Cables" under "Electrical" maps to SolaCount "Cables" under "Electrical".
        $stockCables = DB::connection('tenant')->table('item_categories')->where('organization_id', TenantTestManager::ORG_A)->where('name', 'Cables')->value('id');
        $booksCables = DB::connection('tenant')->table('inventory_categories')->where('name', 'Cables')->value('id');
        $this->assertSame((string) $booksCables, IntegrationMasterDataMapping::query()->where('entity_type', 'category')->where('solastock_record_id', (string) $stockCables)->value('solabooks_record_id'));
        // Nothing is created in either app.
        $this->assertSame(3, DB::connection('tenant')->table('inventory_units')->count());
        $this->assertSame(3, DB::connection('tenant')->table('units')->where('organization_id', TenantTestManager::ORG_A)->count());
    }

    #[Test]
    public function customised_duplicated_or_already_mapped_records_are_left_for_a_person(): void
    {
        // Renamed in SolaStock → no longer the standard record.
        DB::connection('tenant')->table('units')->where('organization_id', TenantTestManager::ORG_A)->where('symbol', 'bx')->update(['name' => 'Carton']);
        // Two SolaCount candidates for "Kilogram/kg" (shared + organization copy) → ambiguous.
        DB::connection('tenant')->table('inventory_units')->insert(['organization_id' => 14, 'name' => 'Kilogram', 'symbol' => 'kg', 'created_at' => now(), 'updated_at' => now()]);
        // Already mapped differently → never overwritten.
        $stockPiece = DB::connection('tenant')->table('units')->where('organization_id', TenantTestManager::ORG_A)->where('symbol', 'pcs')->value('id');
        IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'entity_type' => 'unit', 'solastock_record_id' => (string) $stockPiece, 'solabooks_record_id' => '999999', 'status' => 'verified',
            'contract_source_version' => 'manual', 'discovery_method' => 'owner_review', 'last_verified_at' => now()]);

        $this->assertSame(0, app(StandardCatalogMappings::class)->ensure($this->mapping)['unit']);
        $this->assertSame('999999', IntegrationMasterDataMapping::query()->where('entity_type', 'unit')->where('solastock_record_id', (string) $stockPiece)->value('solabooks_record_id'));
    }

    #[Test]
    public function discovery_sees_shared_finance_defaults_and_treats_mapped_pairs_as_ready(): void
    {
        $before = collect(app(Phase2MappingDiscoveryService::class)->discover($this->mapping->mapping_uuid)['results'] ?? []);
        // The old defect: every standard unit reported as a missing Finance record.
        $this->assertSame(0, $before->where('entity_type', 'unit')->where('classification', 'missing_finance_record')->count());

        app(StandardCatalogMappings::class)->ensure($this->mapping);
        $after = collect(app(Phase2MappingDiscoveryService::class)->discover($this->mapping->mapping_uuid)['results'] ?? []);
        $this->assertSame(3, $after->where('entity_type', 'unit')->where('classification', 'exact_match')->count());
        // Categories have no strong key; the verified canonical mapping is what makes them exact.
        $this->assertSame(3, $after->where('entity_type', 'category')->where('classification', 'exact_match')->count());
    }

    #[Test]
    public function reviewed_finance_only_unit_and_category_get_solastock_counterparts_without_duplicates(): void
    {
        $crate = DB::connection('tenant')->table('inventory_units')->insertGetId(['organization_id' => 14, 'name' => 'Crate', 'symbol' => 'cr', 'created_at' => now(), 'updated_at' => now()]);
        $create = new \ReflectionMethod(ConnectionWizardService::class, 'createStockReferenceFromFinance');
        $wizard = app(ConnectionWizardService::class);
        $box = DB::connection('tenant')->table('units')->where('organization_id', TenantTestManager::ORG_A)->where('symbol', 'bx')->value('id');

        $unitId = $create->invoke($wizard, $this->mapping, (object) ['entity_type' => 'unit', 'action' => 'define_unit_conversion',
            'safe_details' => json_encode(['selected_record_id' => $box, 'conversion_factor' => '12'])], (string) $crate);
        $unit = DB::connection('tenant')->table('units')->where('id', $unitId)->first();
        $this->assertSame(['CR', 'Crate', 'count'], [$unit->code, $unit->name, $unit->kind]);
        $this->assertSame('12.00000000', (string) DB::connection('tenant')->table('unit_conversions')->where('from_unit_id', $unitId)->where('to_unit_id', $box)->value('factor'));

        // A second attempt meets the existing record and asks for a selection instead of duplicating.
        try {
            $create->invoke($wizard, $this->mapping, (object) ['entity_type' => 'unit', 'action' => 'propose_unit_creation', 'safe_details' => '{}'], (string) $crate);
            $this->fail('Duplicate unit must not be created.');
        } catch (ValidationException $e) {
            $this->assertSame(['stock_unit_conflict_select_existing'], $e->errors()['connection_wizard']);
        }

        // A child category waits for its parent's mapping, then attaches under it.
        $parent = DB::connection('tenant')->table('inventory_categories')->insertGetId(['organization_id' => 14, 'name' => 'Spares', 'level' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $child = DB::connection('tenant')->table('inventory_categories')->insertGetId(['organization_id' => 14, 'name' => 'Belts', 'parent_id' => $parent, 'level' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $parentStock = $create->invoke($wizard, $this->mapping, (object) ['entity_type' => 'category', 'action' => 'propose_category_creation', 'safe_details' => '{}'], (string) $parent);
        IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid,
            'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'entity_type' => 'category', 'solastock_record_id' => (string) $parentStock, 'solabooks_record_id' => (string) $parent, 'status' => 'verified',
            'contract_source_version' => 'test', 'discovery_method' => 'owner_review', 'last_verified_at' => now()]);
        $childStock = $create->invoke($wizard, $this->mapping, (object) ['entity_type' => 'category', 'action' => 'propose_category_creation', 'safe_details' => '{}'], (string) $child);
        $this->assertSame((int) $parentStock, (int) DB::connection('tenant')->table('item_categories')->where('id', $childStock)->value('parent_id'));
    }
}
