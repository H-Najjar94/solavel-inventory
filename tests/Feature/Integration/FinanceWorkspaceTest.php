<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\PurchaseValuationHold;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseZone;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Integration\IntegrationStatusService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContractBuilder;
use App\Services\InventoryWorkspace\MigrationCatalogScope;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use App\Services\Purchasing\PostedPurchaseSettlementService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class FinanceWorkspaceTest extends TestCase
{
    use TenantAware { tearDown as tenantTearDown; }

    private const SECRET = 'isolated-workspace-signature-secret-0000000000';

    private const ACTOR = 980071;

    private const CLIENT = 980072;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('finance_workspace.secret', self::SECRET);
        config()->set('inventory.demo_tenant.enabled', false);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('inventory_entitlements.feature_enforcement', true);
        $this->centralFixtureSchema();
        // The disposable shared-Finance projection must satisfy the actual
        // onboarding contract; an application grant alone is not readiness.
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
            'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $central = DB::connection('mysql');
        $central->beginTransaction();
        $central->table('clients')->insert(['id' => self::CLIENT, 'is_active' => true]);
        $central->table('organizations')->updateOrInsert(['id' => TenantTestManager::ORG_A], ['client_id' => self::CLIENT,
            'name' => 'Isolated workspace', 'database_name' => 'workspace-fixture', 'is_active' => true]);
        $central->table('users')->insert(['id' => self::ACTOR, 'client_id' => self::CLIENT,
            'name' => 'Synthetic owner', 'email' => 'workspace-owner@example.invalid', 'password' => 'not-a-login', 'status' => 'active']);
        $central->table('user_organizations')->insert(['user_id' => self::ACTOR, 'organization_id' => TenantTestManager::ORG_A, 'role' => 'client_owner', 'status' => 'active']);
        foreach (['finance' => 980073, 'inventory' => 980074] as $slug => $id) {
            $central->table('projects')->insert(['id' => $id, 'slug' => $slug, 'is_active' => true]);
            $central->table('organization_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'is_active' => true]);
            $central->table('user_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'user_id' => self::ACTOR, 'is_active' => true]);
        }
        $central->table('entitlement_state_snapshots')->updateOrInsert(['organization_id' => TenantTestManager::ORG_A], ['underlying_subscription_state' => 'paid_active',
            'effective_access_state' => 'paid_active', 'state_hash' => str_repeat('a', 64),
            'state_payload' => json_encode([
                'client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
                'integration_capabilities' => ['connection_activation_delivery_entitled' => true],
                'applications' => ['finance' => ['accessible' => true, 'commercially_entitled' => true],
                    'inventory' => ['accessible' => true, 'commercially_entitled' => true]],
            ]),
        ]);
        $cache = $this->createStub(EntitlementsCache::class);
        $cache->method('currentClientId')->willReturn(self::CLIENT);
        $cache->method('getProjectSnapshot')->willReturn(['accessible' => true, 'commercially_entitled' => true,
            'tier' => 'enterprise', 'access_until' => now()->addMonth()->toIso8601String(),
            'allowed_features' => ['stock.locations_bins', 'stock.transfers', 'stock.counts']]);
        $this->app->instance(EntitlementsCache::class, $cache);
        // Keep the rollback transaction and resolve only the fixed disposable database.
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('resolveDatabaseName')->with(self::CLIENT)->willReturn('solastock_test_a');
        $tenants->method('useTenant')->with(TenantTestManager::ORG_A, 'solastock_test_a');
        $this->app->instance(TenantManager::class, $tenants);
        IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => self::CLIENT,
            'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => 'solastock_test_a',
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active',
            'base_currency_code' => 'JOD', 'verified_at' => now(),
        ]);
        IntegrationSetting::query()->create(['organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14, 'meta' => ['transport_enabled_workflows' => []]]);
        $status = $this->createStub(IntegrationStatusService::class);
        $status->method('status')->willReturnCallback(fn ($id) => ['readiness' => ['state' => IntegrationSetting::where('organization_id', $id)->value('mode') === 'active' ? 'CONNECTED_READY' : 'CONNECTION_BLOCKED']]);
        $this->app->instance(IntegrationStatusService::class, $status);
        // Central is the production authority; this isolated server has no
        // network access and supplies the same decision from synthetic fixtures.
        $access = $this->createStub(CentralAppAccess::class);
        $access->method('decision')->willReturnCallback(function (int $userId, int $organizationId, string $appKey): array {
            $central = DB::connection('mysql');
            $project = $central->table('projects')->where('slug', $appKey)->where('is_active', true)->value('id');
            $allowed = $project && $central->table('user_organizations')->where('user_id', $userId)
                ->where('organization_id', $organizationId)->where('status', 'active')->exists()
                && $central->table('user_projects')->where('user_id', $userId)->where('organization_id', $organizationId)
                    ->where('project_id', $project)->where('is_active', true)->exists();
            $role = $central->table('user_organizations')->where('user_id', $userId)->where('organization_id', $organizationId)->value('role');

            return ['allowed' => (bool) $allowed, 'reason' => $allowed ? 'allowed' : 'access_required',
                'owner' => $allowed && $role === 'client_owner',
                'roles' => $role === 'viewer' ? ['scoped_inventory_viewer'] : ($role === 'client_owner' ? [] : [$role])];
        });
        $this->app->instance(CentralAppAccess::class, $access);
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->rollBack();
        $this->tenantTearDown();
    }

    public function test_signed_create_retry_edit_revision_and_authoritative_persistence(): void
    {
        $create = ['action' => 'warehouses.store', 'data' => ['name' => 'Synthetic warehouse', 'code' => 'WS-1', 'type' => 'warehouse'],
            'idempotency_key' => 'workspace-create-warehouse-001'];
        $response = $this->send($create)->assertCreated();
        $id = $response->json('data.id');
        $this->assertNotEmpty($id);
        $this->send($create)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame(1, Warehouse::query()->where('code', 'WS-1')->count());
        $this->send(array_replace_recursive($create, ['data' => ['name' => 'Conflicting retry']]))->assertConflict();
        $show = $this->send(['action' => 'warehouses.show', 'parameters' => ['warehouse' => $id]])->assertOk();
        $revision = $show->json('workspace_revision');
        $this->assertSame(64, strlen($revision));
        $edit = ['action' => 'warehouses.update', 'parameters' => ['warehouse' => $id],
            'data' => ['name' => 'Renamed warehouse', 'code' => 'WS-1', 'type' => 'warehouse'],
            'idempotency_key' => 'workspace-update-warehouse-001', 'revision' => $revision];
        $this->send($edit)->assertOk();
        $this->assertSame('Renamed warehouse', Warehouse::query()->findOrFail($id)->name);
        $this->send(array_replace($edit, ['idempotency_key' => 'workspace-stale-warehouse-002']))->assertConflict();
        $this->assertSame(2, InventoryAuditLog::query()->where('entity_type', 'finance_workspace_command')->count());
    }

    public function test_hold_and_action_allowlist_never_mutate(): void
    {
        config()->set('integration_safety.solabooks_delivery_enabled', false);
        $this->send(['action' => 'warehouses.store', 'data' => ['name' => 'Held', 'code' => 'HELD', 'type' => 'warehouse'],
            'idempotency_key' => 'held-workspace-command-001'])->assertStatus(423);
        $this->send(['action' => 'tenant.provision'])->assertNotFound();
        $this->assertSame(0, Warehouse::query()->where('code', 'HELD')->count());
    }

    public function test_signed_historical_grn_preserves_source_number_date_and_replays_once(): void
    {
        $warehouse = Warehouse::create(['name' => 'Historical fixture', 'code' => 'HIST', 'type' => 'warehouse']);
        $unit = Unit::create(['name' => 'Historical piece', 'code' => 'H-PCS', 'kind' => 'count', 'is_active' => true]);
        $category = ItemCategory::create(['name' => 'Historical category', 'code' => 'H-CAT', 'is_active' => true]);
        $item = Item::create(['name' => 'Historical item', 'sku' => 'H-ITEM', 'base_unit_id' => $unit->id,
            'category_id' => $category->id, 'item_type' => 'inventory', 'tracking_type' => 'none', 'costing_method' => 'fifo', 'is_active' => true]);
        $command = ['action' => 'grn.store', 'idempotency_key' => 'historical-grn-source-00001', 'data' => [
            'grn_number' => '00001', 'receipt_date' => '2024-01-03', 'warehouse_id' => $warehouse->id,
            'lines' => [['item_id' => $item->id, 'received_qty' => '2', 'unit_cost' => '3.25', 'entered_unit_id' => $unit->id]],
        ]];
        $created = $this->send($command)->assertCreated()->assertJsonPath('data.grn_number', '00001');
        $id = $created->json('data.id');
        $this->send($command)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.id', $id);
        $grn = GoodsReceipt::findOrFail($id);
        $this->assertSame('2024-01-03', $grn->receipt_date->toDateString());
        $this->assertSame('draft', $grn->status);
        $this->assertSame(1, GoodsReceipt::where('grn_number', '00001')->count());
        $this->assertSame(0, StockLedger::where('source_type', GoodsReceipt::class)->where('source_id', $id)->count());
        $show = $this->send(['action' => 'grn.show', 'parameters' => ['goods_receipt' => $id]])->assertOk();
        $this->assertSame(64, strlen($show->json('workspace_revision')));
        $changed = $command;
        $changed['data']['receipt_date'] = '2024-01-04';
        $this->send($changed)->assertConflict();
    }

    public function test_reviewed_reference_links_are_scoped_immutable_and_idempotent(): void
    {
        $unit = Unit::create(['name' => 'Piece', 'code' => 'REVIEW-PCS', 'kind' => 'count', 'is_active' => true]);
        $financeId = DB::connection('tenant')->table('inventory_units')->insertGetId(['name' => 'Piece', 'symbol' => 'pcs', 'created_at' => now(), 'updated_at' => now()]);
        $otherId = DB::connection('tenant')->table('inventory_units')->insertGetId(['name' => 'Box', 'symbol' => 'bx', 'created_at' => now(), 'updated_at' => now()]);
        $command = ['action' => 'migration-references.link', 'idempotency_key' => 'reviewed-reference-command-001', 'data' => [
            'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
            'entity_type' => 'unit', 'stock_record_id' => $unit->id, 'finance_record_id' => $financeId,
            'reviewed' => true, 'evidence' => 'Reviewed counted Piece equivalence', 'source_hash' => hash('sha256', 'review fixture'),
        ]];
        $this->send($command)->assertOk();
        $this->send($command)->assertOk()->assertHeader('X-Workspace-Replayed', 'true');
        $this->assertSame(1, IntegrationMasterDataMapping::where('entity_type', 'unit')->count());
        $conflict = $command;
        $conflict['idempotency_key'] = 'reviewed-reference-command-002';
        $conflict['data']['finance_record_id'] = $otherId;
        $this->send($conflict)->assertConflict();
        $foreign = $command;
        $foreign['idempotency_key'] = 'reviewed-reference-command-003';
        $foreign['data']['organization_mapping_uuid'] = (string) Str::uuid();
        $this->send($foreign)->assertNotFound();
        $unreviewed = $command;
        $unreviewed['idempotency_key'] = 'reviewed-reference-command-004';
        $unreviewed['data']['reviewed'] = false;
        $this->send($unreviewed)->assertUnprocessable();
        $this->assertSame(1, IntegrationMasterDataMapping::where('entity_type', 'unit')->count());
    }

    public function test_signed_identity_does_not_bypass_membership_and_organization_scope(): void
    {
        $this->send(['action' => 'warehouses.index', 'organization_id' => TenantTestManager::ORG_B])->assertForbidden();
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['status' => 'inactive']);
        $this->send(['action' => 'warehouses.index'])->assertForbidden();
    }

    public function test_signature_and_nonce_replay_fail_closed(): void
    {
        $nonce = bin2hex(random_bytes(24));
        $this->send(['action' => 'warehouses.index'], $nonce)->assertOk();
        $this->send(['action' => 'warehouses.index'], $nonce)->assertConflict();
        $this->postJson(WorkspaceSignature::PATH, ['action' => 'warehouses.index'])->assertForbidden();
    }

    public function test_canonical_capability_revocation_is_forbidden_not_a_server_error(): void
    {
        DB::connection('mysql')->table('entitlement_state_snapshots')->where('organization_id', TenantTestManager::ORG_A)
            ->update(['state_payload' => json_encode(['client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A])]);
        $this->send(['action' => 'warehouses.index'])->assertForbidden();
    }

    public function test_viewer_cannot_mutate_and_cannot_read_unassigned_warehouse(): void
    {
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $warehouse = Warehouse::create(['name' => 'Unassigned warehouse', 'code' => 'DENIED-W', 'type' => 'warehouse']);
        $this->send(['action' => 'warehouses.store', 'data' => ['name' => 'Forbidden', 'code' => 'DENIED-C', 'type' => 'warehouse'],
            'idempotency_key' => 'denied-viewer-command-001'])->assertForbidden();
        $this->send(['action' => 'warehouses.show', 'parameters' => ['warehouse' => $warehouse->id]])->assertNotFound();
        $this->assertSame(0, Warehouse::query()->where('code', 'DENIED-C')->count());
    }

    public function test_location_revisions_allow_edits_and_deny_stale_updates(): void
    {
        $warehouse = Warehouse::create(['name' => 'Location parent', 'code' => 'LOC-W', 'type' => 'warehouse']);
        $zone = WarehouseZone::create(['warehouse_id' => $warehouse->id, 'code' => 'Z-1', 'name' => 'Zone one']);
        $show = $this->send(['action' => 'warehouses.show', 'parameters' => ['warehouse' => $warehouse->id]])->assertOk();
        $revision = $show->json('workspace_revisions.zone:'.$zone->id);
        $this->assertSame(64, strlen($revision));
        $edit = ['action' => 'zones.update', 'parameters' => ['zone' => $zone->id],
            'data' => ['code' => 'Z-1', 'name' => 'Zone revised'], 'revision' => $revision, 'idempotency_key' => 'zone-revision-edit-001'];
        $this->send($edit)->assertOk();
        $this->assertSame('Zone revised', $zone->fresh()->name);
        $this->send(array_replace($edit, ['idempotency_key' => 'zone-revision-stale-02']))->assertConflict();
    }

    public function test_context_is_available_before_mapping_without_granting_operations(): void
    {
        IntegrationOrganizationMapping::query()->delete();
        IntegrationSetting::query()->delete();
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.mapped', false)->assertJsonPath('data.writable', false)
            ->assertJson(fn ($json) => $json->where('data.actions', fn ($actions) => $actions['warehouses.store']['allowed'] === false)->etc());
        $this->send(['action' => 'warehouses.index'])->assertStatus(409);
        $this->send(['action' => 'workspace.context', 'finance_organization_id' => 999])->assertForbidden();
    }

    public function test_context_uses_real_role_and_does_not_turn_entitlement_into_permission(): void
    {
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->send(['action' => 'workspace.context'])->assertOk()->assertJson(fn ($json) => $json->where('data.actions', fn ($actions) => $actions['warehouses.store']['allowed'] === false)->etc());
    }

    private function send(array $input, ?string $nonce = null)
    {
        $payload = array_replace(['client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
            'finance_organization_id' => 14, 'actor_id' => self::ACTOR], $input);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce ??= bin2hex(random_bytes(24));

        return $this->call('POST', WorkspaceSignature::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WORKSPACE_TIMESTAMP' => $timestamp, 'HTTP_X_WORKSPACE_NONCE' => $nonce,
            'HTTP_X_WORKSPACE_SIGNATURE' => WorkspaceSignature::sign($body, $timestamp, $nonce, self::SECRET),
        ], $body);
    }

    public function test_context_matches_dispatch_readiness_without_conferring_owner_permissions(): void
    {
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.ready', true)->assertJsonPath('data.can_open_stock', true)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.index']['allowed'] === true);
        IntegrationSetting::query()->update(['mode' => 'connected_pending_mapping']);
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.index']['allowed'] === false)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.index']['reason'] === 'workspace_connection_not_ready');
        $this->send(['action' => 'warehouses.index'])->assertStatus(409);
        IntegrationSetting::query()->update(['mode' => 'paused']);
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.ready', false)->assertJsonPath('data.writable', false)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.index']['allowed'] === false)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.store']['allowed'] === false);
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.store']['allowed'] === false);
    }

    public function test_global_delivery_enablement_does_not_unhold_a_mapping(): void
    {
        IntegrationOrganizationMapping::query()->update(['status' => 'verified_hold', 'activation_state' => 'maintenance_hold']);
        $this->send(['action' => 'workspace.context'])->assertOk()->assertJsonPath('data.writable', false);
        $this->send(['action' => 'warehouses.index'])->assertStatus(409);
        $this->send(['action' => 'warehouses.store', 'data' => ['name' => 'Still held', 'code' => 'STILL-HELD', 'type' => 'warehouse'],
            'idempotency_key' => 'held-mapping-global-enable-001'])->assertStatus(409);
        $this->assertSame(0, Warehouse::query()->where('code', 'STILL-HELD')->count());
    }

    public function test_trace_reads_are_bounded_and_respect_selected_warehouse(): void
    {
        $warehouse = StockTestFactory::warehouse();
        $other = StockTestFactory::warehouse();
        $item = StockTestFactory::lotItem();
        $lot = StockTestFactory::lot($item);
        for ($i = 1; $i <= 26; $i++) {
            app(StockLedgerService::class)->post([
                new StockMovement(direction: 'in', itemId: $item->id,
                    warehouseId: $warehouse->id, quantity: '1', sourceType: 'isolated_trace',
                    sourceId: $i, lotId: $lot->id, unitCost: '2.50'),
            ], 'workspace-trace:'.$i);
        }
        $this->send(['action' => 'lots.show', 'parameters' => ['lot' => $lot->id]])->assertOk()
            ->assertJsonCount(25, 'data.movements')->assertJsonPath('data.movements_meta.total', 26);
        $this->send(['action' => 'lots.show', 'parameters' => ['lot' => $lot->id], 'data' => ['page' => 2]])->assertOk()
            ->assertJsonCount(1, 'data.movements');
        $this->send(['action' => 'lots.index', 'data' => ['warehouse_id' => $warehouse->id]])->assertOk()->assertJsonCount(1, 'data');
        $this->send(['action' => 'lots.index', 'data' => ['warehouse_id' => $other->id]])->assertOk()->assertJsonCount(0, 'data');
        $this->send(['action' => 'warehouses.show', 'parameters' => ['warehouse' => $warehouse->id], 'data' => ['reference_only' => true]])
            ->assertOk()->assertJsonMissingPath('data.balances')->assertJsonMissingPath('data.zones');
    }

    public function test_opening_draft_uses_native_validation_revision_and_durable_replay(): void
    {
        $warehouse = StockTestFactory::warehouse();
        $item = StockTestFactory::item(['name' => 'رصيد مخزون افتتاحي']);
        $create = ['action' => 'opening.store', 'idempotency_key' => 'migration-opening-draft-001',
            'data' => ['warehouse_id' => $warehouse->id, 'opening_date' => '2026-09-01',
                'lines' => [['item_id' => $item->id, 'quantity' => '4.0000', 'unit_cost' => '10.0000']]]];
        $response = $this->send($create)->assertCreated()->assertJsonPath('data.total_value', '40.00');
        $id = $response->json('data.id');
        $this->send($create)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame(1, OpeningStockEntry::query()->count());
        $this->assertSame(0, StockLedger::query()->count());
        $show = $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $id]])->assertOk();
        $revision = $show->json('workspace_revision');
        $edit = ['action' => 'opening.update', 'parameters' => ['entry' => $id],
            'idempotency_key' => 'migration-opening-edit-001', 'revision' => $revision,
            'data' => array_replace($create['data'], ['notes' => 'Reviewed cutover'])];
        $this->send($edit)->assertOk()->assertJsonPath('data.notes', 'Reviewed cutover');
        $this->send(array_replace($edit, ['idempotency_key' => 'migration-stale-edit-002']))->assertConflict();
        $this->send(array_replace_recursive($create, ['data' => ['lines' => [['quantity' => '-4']]]]))->assertConflict();
        $this->send(array_replace_recursive($create, ['idempotency_key' => 'migration-invalid-003',
            'data' => ['lines' => [['quantity' => '-4']]]]))->assertUnprocessable();
        // Native posting checks reviewed accounting mappings before a stock mutation.
        $fresh = $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $id]])->assertOk();
        $this->send(['action' => 'opening.post', 'parameters' => ['entry' => $id],
            'idempotency_key' => 'migration-unmapped-post-001', 'revision' => $fresh->json('workspace_revision')])->assertUnprocessable();
        $this->assertSame(0, StockLedger::query()->count());
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->send(array_replace($create, ['idempotency_key' => 'migration-viewer-001']))->assertForbidden();
        $this->assertSame(1, OpeningStockEntry::withoutGlobalScopes()->where('organization_id', TenantTestManager::ORG_A)->count());
    }

    public function test_opening_posts_through_owner_ledger_and_outbox_and_replays_without_duplicates(): void
    {
        $org = TenantTestManager::ORG_A;
        $warehouse = StockTestFactory::warehouse();
        $unit = Unit::create(['code' => 'OPEN-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = StockTestFactory::item(['base_unit_id' => $unit->id]);
        foreach (['item' => [$item->id, 901], 'unit' => [$unit->id, 902]] as $type => [$stockId, $financeId]) {
            IntegrationMasterDataMapping::create([
                'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
                'central_client_id' => self::CLIENT, 'central_organization_id' => $org,
                'finance_organization_id' => 14, 'solastock_organization_id' => $org,
                'entity_type' => $type, 'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified',
            ]);
        }
        foreach (['inventory_asset' => [801, 'asset'], 'opening_offset' => [802, 'equity']] as $role => [$id, $type]) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'name' => $role, 'type' => $type]);
            IntegrationAccountMapping::create(['organization_id' => $org, 'integration' => 'solabooks',
                'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
        }
        IntegrationSetting::query()->firstOrFail()->update(['meta' => [
            'client_id' => self::CLIENT, 'central_organization_id' => $org, 'signing_key_id' => 'synthetic-test-key',
            'transport_enabled_workflows' => ['opening_stock.posted', 'opening_stock.reversed'],
            'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8,
                'inventory_valuation_basis' => FinanceBaseValuation::BASIS],
        ]]);
        $requirements = ['action' => 'opening.requirements', 'data' => ['warehouse_id' => $warehouse->id, 'finance_item_ids' => [901]]];
        $before = $this->send($requirements)->assertOk()->assertJsonPath('data.items.0.quantity', '0.0000')
            ->assertJsonPath('data.inventory_account_id', 801)->assertJsonPath('data.opening_offset_account_id', 802);
        $this->send(array_replace_recursive($requirements, ['data' => ['finance_item_ids' => [999999]]]))->assertUnprocessable();
        $identity = IntegrationMasterDataMapping::query()->where('entity_type', 'item')->where('solabooks_record_id', '901')->firstOrFail();
        $identity->update(['solabooks_archived' => true]);
        $this->send($requirements)->assertUnprocessable();
        $identity->update(['solabooks_archived' => false, 'error_state' => ['code' => 'synthetic_conflict']]);
        $this->send($requirements)->assertUnprocessable();
        $identity->update(['error_state' => null]);
        $this->assertSame(0, OpeningStockEntry::query()->count());
        $draft = $this->send(['action' => 'opening.store', 'idempotency_key' => 'migration-owner-opening-001',
            'data' => ['warehouse_id' => $warehouse->id, 'opening_date' => '2026-09-01',
                'lines' => [['item_id' => $item->id, 'quantity' => '4.0000', 'unit_cost' => '10.0000']]]])->assertCreated();
        $id = $draft->json('data.id');
        $show = $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $id]])->assertOk();
        $post = ['action' => 'opening.post', 'parameters' => ['entry' => $id], 'revision' => $show->json('workspace_revision'),
            'idempotency_key' => 'migration-owner-post-001'];
        $posted = $this->send($post);
        $this->assertSame(200, $posted->status(), $posted->getContent());
        $posted->assertJsonPath('data.status', 'posted');
        $this->send($post)->assertOk()->assertHeader('X-Workspace-Replayed', 'true');
        $this->assertSame('4.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $ledger = StockLedger::query()->where('source_type', OpeningStockEntry::class)->where('source_id', $id)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('40.00', $ledger[0]->total_cost);
        $events = IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.posted')->get();
        $this->assertCount(1, $events);
        $contract = app(SolaStockJournalContractBuilder::class)->build($events[0]);
        $this->assertSame(801, $contract['lines'][0]['account_id']);
        $this->assertSame('40.00', $contract['lines'][0]['base_debit']);
        $this->assertSame(802, $contract['lines'][1]['account_id']);
        $this->assertSame('40.00', $contract['lines'][1]['base_credit']);
        $this->assertSame(901, $contract['inventory_quantities'][0]['finance_item_id']);
        $this->assertSame('4.0000', $contract['inventory_quantities'][0]['base_quantity']);
        $after = $this->send($requirements)->assertOk()->assertJsonPath('data.items.0.quantity', '4.0000')->assertJsonPath('data.items.0.value', '40.00');
        $this->assertNotSame($before->json('data.version'), $after->json('data.version'));
        $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $id]])->assertOk()
            ->assertJsonCount(1, 'data.accounting_events')->assertJsonPath('data.accounting_events.0.event_uuid', $events[0]->event_uuid);
        $migration = ['action' => 'opening.migrate', 'idempotency_key' => 'migration-atomic-opening-001',
            'data' => ['session_id' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id,
                'cutover_date' => '2026-09-01', 'requirements_version' => $before->json('data.version'),
                'lines' => [['finance_item_id' => 901, 'quantity' => '4.0000', 'unit_cost' => '10.0000', 'total_value' => '40.00']]]];
        $this->send($migration)->assertUnprocessable();
        $this->assertSame(1, OpeningStockEntry::query()->count());
        $migration['data']['requirements_version'] = $after->json('data.version');
        $invalid = $migration;
        $invalid['data']['lines'][0]['total_value'] = '39.00';
        $this->send($invalid)->assertUnprocessable();
        $this->assertSame(1, OpeningStockEntry::query()->count());
        $this->send($migration)->assertOk()->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.positions.0.posted_value', '40.00')->assertJsonPath('data.positions.0.value_difference', '0.00');
        $this->send($migration)->assertOk()->assertHeader('X-Workspace-Replayed', 'true');
        $this->assertSame(2, OpeningStockEntry::query()->count());
        $this->assertSame(2, IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.posted')->count());
        $this->assertSame('8.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

    }

    /** @return array{0:Warehouse,1:Item,2:Unit} */
    private function connectedOpeningFixture(string $suffix, array $meta = []): array
    {
        $org = TenantTestManager::ORG_A;
        $warehouse = StockTestFactory::warehouse();
        $unit = Unit::create(['code' => 'OPEN-'.$suffix, 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = StockTestFactory::item(['base_unit_id' => $unit->id]);
        foreach (['item' => [$item->id, 911], 'unit' => [$unit->id, 912]] as $type => [$stockId, $financeId]) {
            IntegrationMasterDataMapping::create([
                'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
                'central_client_id' => self::CLIENT, 'central_organization_id' => $org,
                'finance_organization_id' => 14, 'solastock_organization_id' => $org,
                'entity_type' => $type, 'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified',
            ]);
        }
        foreach (['inventory_asset' => [801, 'asset'], 'opening_offset' => [802, 'equity']] as $role => [$id, $type]) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'name' => $role, 'type' => $type]);
            IntegrationAccountMapping::create(['organization_id' => $org, 'integration' => 'solabooks',
                'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
        }
        IntegrationSetting::query()->firstOrFail()->update(['meta' => [
            'client_id' => self::CLIENT, 'central_organization_id' => $org, 'signing_key_id' => 'synthetic-test-key',
            'transport_enabled_workflows' => ['opening_stock.posted', 'opening_stock.reversed'],
            'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8,
                'inventory_valuation_basis' => FinanceBaseValuation::BASIS],
        ] + $meta]);

        return [$warehouse, $item, $unit];
    }

    public function test_connected_opening_reversal_is_an_exact_linked_inverse_of_the_opening_journal(): void
    {
        [$warehouse, $item] = $this->connectedOpeningFixture('REV-EA');
        $service = app(\App\Services\Documents\OpeningStockService::class);
        $entry = $service->createDraft(['warehouse_id' => $warehouse->id, 'opening_date' => '2026-09-01'],
            [['item_id' => $item->id, 'quantity' => '4.0000', 'unit_cost' => '10.0000']]);
        $service->post($entry);

        // Before the fix the reversal summed IN+OUT to 0 and refused with event_no_value.
        $service->reverse($entry->fresh(), 'Opening entered twice');
        $service->reverse($entry->fresh(), 'Opening entered twice');

        $this->assertSame('reversed', $entry->fresh()->status);
        $this->assertSame('0.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $reversal = \App\Models\Tenant\InventoryReversal::query()->where('source_type', 'opening_stock')->where('source_id', $entry->id)->sole();
        $posted = IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.posted')->sole();
        $reversed = IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.reversed')->sole();
        $this->assertSame('InventoryReversal', $reversed->aggregate_type);
        $this->assertSame($reversal->id, (int) $reversed->aggregate_id);
        $this->assertSame('-40.00', $reversed->payload['total_inventory_value_change']);
        $this->assertSame($posted->event_uuid, $reversed->payload['original_source']['event_uuid']);
        $this->assertSame($posted->event_uuid, $reversed->depends_on_event_uuid);
        $this->assertSame($posted->event_uuid, $reversal->original_event_uuid);
        $this->assertSame($reversed->event_uuid, $reversal->reversal_event_uuid);
        $this->assertSame(1, StockLedger::query()->where('source_type', \App\Models\Tenant\InventoryReversal::class)
            ->where('source_id', $reversal->id)->where('direction', 'out')->count());

        $original = app(SolaStockJournalContractBuilder::class)->build($posted);
        $contract = app(SolaStockJournalContractBuilder::class)->build($reversed);
        $this->assertNull($original['source']['reversal']);
        $this->assertSame($posted->idempotency_key, $contract['source']['reversal']['original_source_key']);
        $this->assertSame([801, 802], array_column($contract['lines'], 'account_id'));
        $this->assertSame(['0.00', '40.00'], array_column($contract['lines'], 'base_debit'));
        $this->assertSame(['40.00', '0.00'], array_column($contract['lines'], 'base_credit'));
        $this->assertSame(array_column($original['lines'], 'base_debit'), array_column($contract['lines'], 'base_credit'));
        $this->assertSame(array_column($original['lines'], 'base_credit'), array_column($contract['lines'], 'base_debit'));
        $this->assertSame('4.0000', $contract['inventory_quantities'][0]['base_quantity']);
        $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $entry->id]])->assertOk()
            ->assertJsonCount(2, 'data.accounting_events')->assertJsonCount(2, 'data.ledger')
            ->assertJsonPath('data.accounting_events.1.event_uuid', $reversed->event_uuid);
    }

    public function test_journals_recorded_while_paused_are_promoted_once_after_resume_but_historical_pending_is_untouched(): void
    {
        $org = TenantTestManager::ORG_A;
        $mapping = IntegrationOrganizationMapping::query()->firstOrFail();
        // No wizard run in this fixture: the activation baseline is the mapping's verification.
        $mapping->update(['verified_at' => now()->subDay()]);
        [$warehouse, $item, $unit] = $this->connectedOpeningFixture('PROMO-EA', ['transport_enabled' => false]);
        IntegrationSetting::query()->firstOrFail()->update(['mode' => 'paused']);
        $historical = IntegrationOutboxEvent::query()->create([
            'organization_id' => $org, 'event_uuid' => (string) Str::uuid(), 'integration' => 'solabooks',
            'event_type' => 'opening_stock.posted', 'aggregate_type' => 'OpeningStockEntry', 'aggregate_id' => 990001,
            'aggregate_number' => 'OS-HISTORICAL', 'occurred_at' => now()->subDays(2),
            'payload' => ['total_inventory_value_change' => '5.00'], 'status' => 'pending', 'mapping_status' => 'incomplete',
            'attempts' => 0, 'idempotency_key' => 'solabooks:opening_stock.posted:OpeningStockEntry:990001',
            'contract_version' => 'solastock-journal.v2', 'workflow_key' => 'opening_stock.posted',
            'ordering_key' => 'OpeningStockEntry:990001',
        ]);
        $service = app(\App\Services\Documents\OpeningStockService::class);
        $service->post($service->createDraft(['warehouse_id' => $warehouse->id, 'opening_date' => '2026-09-01'],
            [['item_id' => $item->id, 'quantity' => '2.0000', 'unit_cost' => '10.0000']]));
        $event = IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.posted')->where('id', '!=', $historical->id)->sole();
        $this->assertSame('pending', $event->status);
        $this->assertNull($event->transport_eligible_at);
        $hash = $event->payload_hash;
        $outbox = app(\App\Services\Integration\IntegrationOutboxService::class);

        // Still paused: nothing moves.
        $this->assertSame(0, $outbox->promoteEligiblePending($org)['promoted']);
        $this->assertSame('pending', $event->fresh()->status);

        // Resumed (what ConnectionWizardService::resume restores) but the unit is not
        // mapped yet: catalog comes first, the journal waits.
        $setting = IntegrationSetting::query()->firstOrFail();
        $setting->update(['mode' => 'active', 'meta' => ['transport_enabled' => true] + (array) $setting->meta]);
        $unitMapping = IntegrationMasterDataMapping::query()->where('entity_type', 'unit')->where('solastock_record_id', (string) $unit->id)->sole();
        $unitMapping->update(['conflict_code' => 'synthetic_unit_conflict']);
        $this->assertSame(0, $outbox->promoteEligiblePending($org)['promoted']);
        $this->assertSame('pending', $event->fresh()->status);

        $unitMapping->update(['conflict_code' => null]);
        $this->assertSame(['examined' => 1, 'promoted' => 1], $outbox->promoteEligiblePending($org));
        $promoted = $event->fresh();
        $this->assertSame('ready', $promoted->status);
        $this->assertSame('complete', $promoted->mapping_status);
        $this->assertNotNull($promoted->transport_eligible_at);
        $this->assertSame($hash, $promoted->payload_hash);
        $this->assertSame('pending', $historical->fresh()->status);
        $this->assertNull($historical->fresh()->transport_eligible_at);

        // Idempotent: a second pass (e.g. the next supervisor tick) changes nothing.
        $this->assertSame(0, $outbox->promoteEligiblePending($org)['promoted']);
        $this->assertSame(1, DB::connection('tenant')->table('integration_outbox_transition_audits')
            ->where('event_id', $event->id)->where('reason_code', 'pending_promoted_after_activation')->count());

        // The durable worker now claims it exactly once (no delivery attempted here).
        $transport = app(\App\Services\Integration\DurableOutboxTransportService::class);
        $claimed = $transport->claim($org, 'promotion-test-worker');
        $this->assertSame($event->id, $claimed?->id);
        $this->assertNull($transport->claim($org, 'promotion-test-worker'));
    }

    public function test_opening_access_requires_completed_finance_onboarding(): void
    {
        DB::connection('tenant')->table('organizations')->where('id', 14)->update(['finance_setup_completed_at' => null]);
        $this->send(['action' => 'opening.index'])->assertForbidden()->assertJsonPath('message', 'workspace_integration_not_entitled');
    }

    public function test_migration_purchase_default_requires_same_verified_base_and_replays_without_movements(): void
    {
        $category = ItemCategory::create(['name' => 'Migration category', 'code' => 'MIG-CAT', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'code' => 'MIG-EACH', 'kind' => 'count', 'is_active' => true]);
        $org = TenantTestManager::ORG_A;
        foreach (['category' => [$category->id, 991], 'unit' => [$unit->id, 992]] as $type => [$stockId,$financeId]) {
            IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
                'central_client_id' => self::CLIENT, 'central_organization_id' => $org, 'finance_organization_id' => 14, 'solastock_organization_id' => $org,
                'entity_type' => $type, 'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified']);
        }
        $mapping = IntegrationOrganizationMapping::query()->firstOrFail();
        $mapping->update(['currency_verified_at' => now()]);
        $setting = IntegrationSetting::query()->firstOrFail();
        $setting->update(['meta' => ['transport_enabled_workflows' => [], 'finance_currency_contract' => [
            'base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD', 'USD']]]]);
        $data = ['source_hash' => hash('sha256', 'purchase source'), 'name' => 'Purchase default', 'sku' => 'MIG-COST-001',
            'finance_category_id' => 991, 'finance_unit_id' => 992, 'unit_price' => '500.0000',
            'purchase_price' => '490.1234', 'purchase_currency_code' => 'JOD', 'item_type' => 'inventory', 'valuation_method' => 'fifo'];
        $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['purchase_currency_code' => 'USD'])])->assertUnprocessable();
        $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['purchase_currency_code' => 'XYZ'])])->assertUnprocessable();
        $missing = $data;
        unset($missing['purchase_currency_code']);
        $this->send(['action' => 'items.migration-requirements', 'data' => $missing])->assertUnprocessable();
        $missing = $data;
        unset($missing['purchase_price']);
        $this->send(['action' => 'items.migration-requirements', 'data' => $missing])->assertUnprocessable();
        $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['purchase_price' => '490.12345'])])->assertUnprocessable();
        $setting->update(['solabooks_organization_id' => 999]);
        $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertConflict();
        $setting->update(['solabooks_organization_id' => 14]);
        $mapping->update(['base_currency_code' => 'USD']);
        $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertUnprocessable();
        $mapping->update(['base_currency_code' => 'JOD']);
        $mapping->update(['currency_verified_at' => null]);
        $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertUnprocessable();
        $mapping->update(['currency_verified_at' => now()]);
        $facts = $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertOk()->json('data');
        $this->assertSame('JOD', $facts['purchase_currency_code']);
        $changed = $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['purchase_price' => '491.0000'])])->assertOk()->json('data');
        $this->assertNotSame($facts['version'], $changed['version']);
        $command = ['action' => 'items.migration-create', 'data' => $data + ['requirements_version' => $facts['version']],
            'idempotency_key' => 'migration-cost-default-command-001'];
        $created = $this->send($command)->assertCreated();
        $id = $created->json('data.stock_item_id');
        $this->send($command)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.stock_item_id', $id);
        $item = Item::findOrFail($id);
        $this->assertSame('490.1234', $item->purchase_price);
        $this->assertSame('500.0000', $item->sales_price);
        $this->assertSame(1, Item::where('sku', 'MIG-COST-001')->count());
        $this->assertSame(0, StockLedger::where('item_id', $id)->count());
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $id)->count());
        $this->assertSame(0, DB::connection('tenant')->table('integration_outbox_events')->count());
    }

    public function test_migration_catalog_uses_native_creation_then_durable_explicit_mapping(): void
    {
        $category = ItemCategory::create(['name' => 'Migration category', 'code' => 'MIG-CAT', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'code' => 'MIG-EACH', 'kind' => 'count', 'is_active' => true]);
        $org = TenantTestManager::ORG_A;
        foreach (['category' => [$category->id, 991], 'unit' => [$unit->id, 992]] as $type => [$stockId,$financeId]) {
            IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(),
                'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
                'central_client_id' => self::CLIENT, 'central_organization_id' => $org, 'finance_organization_id' => 14, 'solastock_organization_id' => $org,
                'entity_type' => $type, 'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified']);
        }
        $data = ['source_hash' => hash('sha256', 'synthetic source'), 'name' => 'صنف جديد', 'sku' => 'MIG-00001', 'barcode' => '0000987654321',
            'finance_category_id' => 991, 'finance_unit_id' => 992, 'unit_price' => '37.241', 'item_type' => 'inventory', 'valuation_method' => 'fifo'];
        $facts = $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertOk()->json('data');
        $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['unit_price' => '37.2415'])])->assertOk();
        $this->send(['action' => 'items.migration-requirements', 'data' => array_replace($data, ['unit_price' => '37.24159'])])->assertUnprocessable();
        $this->assertSame(0, Item::where('sku', 'MIG-00001')->count());
        $key = 'migration-catalog:'.hash('sha256', 'stable source identity');
        $command = ['action' => 'items.migration-create', 'data' => $data + ['requirements_version' => $facts['version']], 'idempotency_key' => $key];
        $created = $this->send($command)->assertCreated();
        $id = $created->json('data.stock_item_id');
        $this->send($command)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.stock_item_id', $id);
        $this->assertSame(1, Item::where('sku', 'MIG-00001')->count());
        $this->assertSame('37.2410', Item::findOrFail($id)->sales_price);
        $this->assertSame('0.0000', Item::findOrFail($id)->purchase_price);
        $this->assertSame(1, ItemBarcode::where('item_id', $id)->where('barcode', '0000987654321')->count());
        $this->assertSame(0, StockLedger::where('item_id', $id)->count());
        $this->assertSame(0, IntegrationMasterDataMapping::where('entity_type', 'item')->count());
        $link = ['action' => 'items.migration-link', 'data' => ['creation_key' => $key, 'source_hash' => $data['source_hash'], 'stock_item_id' => $id, 'finance_item_id' => 993],
            'idempotency_key' => $key.':link'];
        $this->send($link)->assertOk();
        $this->send($link)->assertOk()->assertHeader('X-Workspace-Replayed', 'true');
        $this->assertSame(1, IntegrationMasterDataMapping::where('entity_type', 'item')->where('solabooks_record_id', '993')->count());
        $wrong = $link;
        $wrong['data']['finance_item_id'] = 994;
        $wrong['idempotency_key'] .= '2';
        $this->send($wrong)->assertConflict();
        $wrong = $command;
        $wrong['data']['name'] = 'Different source';
        $this->send($wrong)->assertConflict();
        $this->send(['action' => 'items.migration-requirements', 'data' => $data])->assertUnprocessable();
        $this->assertFalse(app(MigrationCatalogScope::class)->active());
    }

    private function centralFixtureSchema(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasTable('app_permission_grants')) {
            $schema->create('app_permission_grants', function ($t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('app_key');
                $t->string('permission_key');
                $t->string('effect');
                $t->timestamp('expires_at')->nullable();
            });
        }
        if (! $schema->hasColumn('users', 'deleted_at')) {
            $schema->table('users', fn ($t) => $t->softDeletes());
        }
        foreach (['clients', 'projects', 'organization_projects', 'user_projects'] as $name) {
            if (! $schema->hasTable($name)) {
                $schema->create($name, function ($t) use ($name) {
                    $t->id();
                    $t->boolean('is_active')->default(true);
                    $t->softDeletes();
                    if ($name === 'projects') {
                        $t->string('slug');
                    }
                    if (in_array($name, ['organization_projects', 'user_projects'], true)) {
                        $t->unsignedBigInteger('project_id');
                        $t->unsignedBigInteger('organization_id');
                    }
                    if ($name === 'user_projects') {
                        $t->unsignedBigInteger('user_id');
                    }
                });
            }
        }
    }

    /** Finance immutable JE proof is the explicit remote stub boundary; Stock services/ledger are real. */
    public function test_service_settlement_holds_real_partial_receipt_cost_and_applies_replays_reverses_once(): void
    {
        $org = TenantTestManager::ORG_A;
        $mapping = IntegrationOrganizationMapping::sole();
        $warehouse = StockTestFactory::warehouse();
        $unit = Unit::create(['code' => 'SET-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = StockTestFactory::averageItem(['base_unit_id' => $unit->id]);
        $supplier = Supplier::create(['code' => 'SET-SUP', 'name' => 'Synthetic supplier', 'is_active' => true]);
        foreach (['inventory_asset' => 801, 'grni' => 802] as $role => $id) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'name' => $role, 'type' => $role === 'grni' ? 'liability' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $account = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $pairs['account_role'][] = [$account->id, $id];
        }
        $pairs += ['item' => [[$item->id, 901]], 'unit' => [[$unit->id, 902]], 'supplier' => [[$supplier->id, 903]]];
        foreach ($pairs as $type => $entries) {
            foreach ($entries as [$native, $external]) {
                IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'central_client_id' => self::CLIENT, 'central_organization_id' => $org, 'finance_organization_id' => 14,
                    'solastock_organization_id' => $org, 'entity_type' => $type, 'solastock_record_id' => (string) $native,
                    'solabooks_record_id' => (string) $external, 'status' => 'verified']);
            }
        }
        IntegrationSetting::sole()->update(['meta' => ['client_id' => self::CLIENT, 'central_organization_id' => $org,
            'signing_key_id' => 'fixture', 'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $item->id, 'entered_unit_id' => $unit->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        $lifecycle = IntegrationDocumentLifecycleMapping::query()->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $receipt->id)->sole();
        $facts = ['settlement_uuid' => (string) Str::uuid(), 'position_uuid' => (string) Str::uuid(), 'source_bill_id' => 900,
            'bill_journal_id' => 901, 'bill_revision' => str_repeat('f', 64), 'receipt_id' => $receipt->id,
            'receipt_line_id' => $receipt->lines()->sole()->id, 'receipt_mapping_uuid' => $lifecycle->mapping_uuid,
            'receipt_journal_key' => IntegrationOutboxEvent::where('event_type', 'grn.posted')->sole()->idempotency_key,
            'quantity' => '8', 'invoice_net_unit_cost' => '6', 'nonrecoverable_tax_unit_cost' => '1'];
        // Real shared-Finance lifecycle projection, without implicit DDL commits.
        $projection = DB::connection('tenant');
        foreach ([
            'bills' => 'id BIGINT, organization_id BIGINT, journal_entry_id BIGINT',
            'journal_entries' => 'id BIGINT, organization_id BIGINT, status VARCHAR(30), posted_at DATETIME, voided_at DATETIME NULL, deleted_at DATETIME NULL',
            'finance_purchase_positions' => 'position_uuid CHAR(36), organization_id BIGINT, organization_mapping_uuid CHAR(36), bill_id BIGINT, bill_journal_id BIGINT, bill_line_id BIGINT, bill_revision CHAR(64), state VARCHAR(30)',
        ] as $table => $columns) {
            $projection->statement("CREATE TEMPORARY TABLE `$table` ($columns)");
        }
        $projection->table('bills')->insert(['id' => 900, 'organization_id' => 14, 'journal_entry_id' => 901]);
        $projection->table('journal_entries')->insert(['id' => 901, 'organization_id' => 14, 'status' => 'posted', 'posted_at' => now()]);
        $projection->table('finance_purchase_positions')->insert(['position_uuid' => $facts['position_uuid'], 'organization_id' => 14,
            'organization_mapping_uuid' => $mapping->mapping_uuid, 'bill_id' => 900, 'bill_journal_id' => 901,
            'bill_line_id' => 902, 'bill_revision' => $facts['bill_revision'], 'state' => 'active']);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizePurchaseSettlement')
            ->andReturnUsing(fn ($input, $operation) => $input + ['allowed' => true, 'operation' => $operation,
                'bill_id' => 900, 'bill_line_id' => 902, 'item_external_id' => 901, 'unit_external_id' => 902,
                'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'receipt_exchange_rate' => '1',
                'finance_journal_id' => 905, 'finance_journal_key' => 'purchase-settlement:'.$input['settlement_uuid'],
                'finance_reversal_journal_id' => 906, 'finance_reversal_journal_key' => 'purchase-settlement-reversal:'.$input['settlement_uuid']]);
        $service = app(PostedPurchaseSettlementService::class);
        $organization = DB::connection('mysql')->table('organizations')->find($org);
        $call = fn ($action, $data) => $service->dispatch(['action' => 'purchasing.settlement.'.$action, 'authority_kind' => 'posted_purchase_settlement',
            'actor_id' => 0, 'finance_organization_id' => 14, 'data' => $data], $organization);
        $legacy = IntegrationFinancialLineAllocation::create([
            'allocation_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => self::CLIENT, 'central_organization_id' => $org,
            'tenant_database_identity' => $mapping->tenant_database_identity, 'finance_organization_id' => 14,
            'solastock_organization_id' => $org, 'source_document_mapping_uuid' => $lifecycle->mapping_uuid,
            'source_document_type' => 'goods_receipt', 'source_document_id' => (string) $receipt->id,
            'source_line_id' => $receipt->lines()->sole()->id, 'destination_document_type' => 'supplier_bill',
            'destination_document_id' => 950, 'destination_line_id' => 951, 'allocation_kind' => 'bill',
            'entered_quantity' => '3', 'base_quantity' => '3', 'destination_quantity' => '3',
            'source_unit_price' => '5', 'destination_unit_price' => '5', 'source_gross' => '15',
            'destination_gross' => '15', 'source_net' => '15', 'destination_net' => '15',
            'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'exchange_rate' => '1',
            'destination_revision' => str_repeat('a', 64), 'source_fingerprint' => str_repeat('b', 64),
            'destination_fingerprint' => str_repeat('a', 64), 'idempotency_key' => 'isolated-legacy-overlap',
            'state' => 'draft_reserved',
        ]);
        try {
            $call('prepare', $facts);
            $this->fail('New settlement overlapped a legacy reserved quantity.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, PurchaseValuationHold::count());
        $legacy->update(['state' => 'released']);
        // A once-valid remote authority response cannot publish a hold after reversal starts.
        $projection->table('finance_purchase_positions')->update(['state' => 'reversal_pending']);
        try {
            $call('prepare', $facts);
            $this->fail('Stale forward proof published a hold during reversal.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, PurchaseValuationHold::count());
        $projection->table('finance_purchase_positions')->update(['state' => 'active']);
        $projection->table('journal_entries')->update(['voided_at' => now()]);
        try {
            $call('prepare', $facts);
            $this->fail('Inactive financial journal published a hold.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $projection->table('journal_entries')->update(['voided_at' => null]);
        $quote = $call('prepare', $facts);
        $this->assertSame('40.00', $quote['receipt_base_amount']);
        $this->assertSame('56.00', $quote['invoice_acquisition_at_receipt_base']);
        $this->assertSame('active', PurchaseValuationHold::sole()->state);
        $apply = $facts + ['plan_fingerprint' => $quote['plan_fingerprint']];
        $projection->table('finance_purchase_positions')->update(['state' => 'reversal_pending']);
        try {
            $call('apply', $apply);
            $this->fail('Stale forward proof applied valuation during reversal.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('50.00', StockBalance::sole()->total_value);
        $projection->table('finance_purchase_positions')->update(['state' => 'active']);
        $call('apply', $apply);
        $call('apply', $apply);
        $this->assertSame('10.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('66.00', StockBalance::sole()->total_value);
        $this->assertSame(1, StockLedger::count());
        $projection->table('finance_purchase_positions')->update(['state' => 'reversal_pending']);
        $reverse = $call('prepare', $facts + ['direction' => 'reverse']);
        $call('reverse', $facts + ['plan_fingerprint' => $reverse['plan_fingerprint']]);
        $this->assertSame('50.00', StockBalance::sole()->total_value);
        $this->assertSame(1, StockLedger::count());
    }

    public function test_service_actor_zero_cannot_call_human_or_unscoped_workspace_actions(): void
    {
        foreach (['warehouses.store', 'purchasing.receiving.execute', 'purchasing.request.upsert'] as $action) {
            $this->send(['actor_id' => 0, 'authority_kind' => 'posted_purchase_settlement', 'action' => $action])->assertForbidden();
        }
        $this->send(['actor_id' => 0, 'action' => 'purchasing.settlement.prepare'])->assertForbidden();
        $this->assertSame(0, Warehouse::query()->count());
    }

    public function test_finance_accountant_cannot_use_receiving_service_without_stock_assignment(): void
    {
        $central = DB::connection('mysql');
        $project = $central->table('projects')->where('slug', 'inventory')->value('id');
        $central->table('user_projects')->where('user_id', self::ACTOR)->where('project_id', $project)->update(['is_active' => false]);
        foreach (['options', 'prepare', 'execute', 'status', 'approve'] as $operation) {
            $this->send(['action' => 'purchasing.receiving.'.$operation, 'data' => ['source_bill_id' => 1]])->assertForbidden();
        }
        $this->assertSame(0, GoodsReceipt::count());
    }
    public function test_cash219_signed_wire_rejects_actor_zero_and_foreign_organization_without_effects():void
    {
        $before=StockLedger::count();
        $payload=['action'=>'financial-origin.cash-refund-demand','data'=>['source_document_type'=>'sales_receipt','source_document_id'=>850,'source_journal_id'=>95,'request_uuid'=>(string)Str::uuid(),'source_revision'=>str_repeat('a',64),'operation_uuid'=>(string)Str::uuid(),'refund_receipt_id'=>991,'purpose'=>'prepare']];
        $this->send($payload+['actor_id'=>0,'authority_kind'=>'posted_financial_origin_settlement'])->assertForbidden();
        $this->send($payload+['organization_id'=>999000])->assertForbidden();
        $this->assertSame($before,StockLedger::count());
        if(Schema::connection('tenant')->hasTable('stock_cash_refund_demands'))$this->assertSame(0,DB::connection('tenant')->table('stock_cash_refund_demands')->count());
    }


    /**
     * Connected landed cost: refused until an owner enables the workflow with a
     * reviewed clearing account; then Dr inventory (on hand) + Dr COGS (sold) /
     * Cr landed cost clearing, and an exact linked inverse on reversal.
     */
    public function test_connected_landed_cost_needs_the_enabled_clearing_workflow_then_journals_and_reverses_exactly(): void
    {
        $org = TenantTestManager::ORG_A;
        $mapping = IntegrationOrganizationMapping::query()->firstOrFail();
        $warehouse = StockTestFactory::warehouse();
        $unit = Unit::create(['code' => 'LC-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = StockTestFactory::averageItem(['base_unit_id' => $unit->id]);
        $supplier = Supplier::create(['code' => 'LC-SUP', 'name' => 'Freight supplier', 'is_active' => true]);
        foreach ([801 => ['1301', 'asset'], 802 => ['2150', 'liability'], 803 => ['5001', 'expense'], 804 => ['6804', 'expense'],
            806 => ['1580', 'asset'], 807 => ['1590', 'asset']] as $id => [$code, $type]) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'code' => $code, 'name' => 'Account '.$code,
                'type' => $type, 'is_active' => true, 'is_postable' => true]);
        }
        $pairs = ['item' => [[$item->id, 901]], 'unit' => [[$unit->id, 902]], 'supplier' => [[$supplier->id, 903]]];
        foreach (['inventory_asset' => 801, 'grni' => 802, 'cogs' => 803, 'adjustment_loss' => 804] as $role => $id) {
            $account = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $pairs['account_role'][] = [$account->id, $id];
        }
        foreach ($pairs as $type => $entries) {
            foreach ($entries as [$native, $external]) {
                IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'central_client_id' => self::CLIENT, 'central_organization_id' => $org, 'finance_organization_id' => 14,
                    'solastock_organization_id' => $org, 'entity_type' => $type, 'solastock_record_id' => (string) $native,
                    'solabooks_record_id' => (string) $external, 'status' => 'verified']);
            }
        }
        IntegrationSetting::sole()->update(['meta' => ['client_id' => self::CLIENT, 'central_organization_id' => $org,
            'signing_key_id' => 'fixture', 'transport_enabled_workflows' => ['grn.posted', 'shipment.posted'],
            'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD', 'USD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $item->id, 'entered_unit_id' => $unit->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        app(StockLedgerService::class)->post([new StockMovement('out', $item->id, $warehouse->id, '4', \App\Models\Tenant\Shipment::class, 92001)], 'landed-cost-connected-sale');
        $service = app(\App\Services\Documents\LandedCostService::class);
        $doc = $service->createDraft(['allocation_method' => 'quantity', 'landed_cost_date' => '2026-10-07', 'supplier_reference' => 'FWD-77'],
            [['charge_type' => 'freight', 'amount' => '20']], [$receipt->lines()->sole()->id]);
        $this->assertSame('JOD', $doc->currency_code);
        $this->assertSame('JOD', $doc->base_currency_code);

        // Not in the reviewed workflow scope yet: refused, nothing revalued.
        try {
            $service->post($doc);
            $this->fail('An existing connection must enable landed costs first.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(__('inventory.landed_cost.connection_not_enabled'), collect($e->errors())->flatten()->first());
        }
        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame('30.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));

        $workflow = app(\App\Services\Integration\LandedCostWorkflow::class);
        $status = $workflow->status($org);
        $this->assertSame('connected', $status['mode']);
        $this->assertFalse($status['enabled']);
        $this->assertSame(['landed_cost_clearing'], $status['missing_roles']);
        $this->assertSame(806, $status['candidates'][0]['id']);
        $this->assertTrue($status['candidates'][0]['recommended']);
        foreach ([801 => 'clearing_same_as_inventory', 803 => 'clearing_account_invalid'] as $account => $code) {
            try {
                $workflow->enable($org, $account, self::ACTOR);
                $this->fail('Invalid clearing account '.$account);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertSame(__('inventory.landed_cost.'.$code), collect($e->errors())->flatten()->first());
            }
        }
        $this->assertTrue($workflow->enable($org, 806, self::ACTOR)['enabled']);
        $this->assertTrue($workflow->enable($org, 806, self::ACTOR)['enabled']);
        try {
            $workflow->enable($org, 807, self::ACTOR);
            $this->fail('A reviewed clearing binding is immutable.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(__('inventory.landed_cost.clearing_immutable'), collect($e->errors())->flatten()->first());
        }
        $meta = IntegrationSetting::sole()->meta;
        $this->assertSame(['grn.posted', 'shipment.posted', 'landed_cost.posted', 'landed_cost.reversed'], $meta['transport_enabled_workflows']);
        $this->assertSame(['grn.posted', 'landed_cost.posted', 'landed_cost.reversed'], \App\Services\Integration\LandedCostWorkflow::preserve($meta, ['grn.posted']));
        $clearing = IntegrationAccountMapping::query()->where('mapping_type', 'landed_cost_clearing')->sole();
        $this->assertSame(['806', 'verified'], [(string) $clearing->solabooks_account_id, $clearing->status]);
        $this->assertSame(1, IntegrationMasterDataMapping::query()->where('entity_type', 'account_role')
            ->where('solastock_record_id', (string) $clearing->id)->where('solabooks_record_id', '806')->where('status', 'verified')->count());

        $service->post($doc->fresh());
        $service->post($doc->fresh());
        $balance = StockBalance::query()->where('item_id', $item->id)->sole();
        $this->assertSame('42.00', (string) $balance->total_value);
        $this->assertSame('7.0000', (string) $balance->average_cost);
        $posted = IntegrationOutboxEvent::query()->where('event_type', 'landed_cost.posted')->sole();
        $this->assertSame('LandedCost', $posted->aggregate_type);
        $this->assertSame('solabooks:landed_cost.posted:LandedCost:'.$doc->id, $posted->idempotency_key);
        $this->assertSame('12.00', $posted->payload['total_inventory_value_change']);
        $this->assertSame(['inventory_asset' => '12.00', 'cogs' => '8.00', 'adjustment_loss' => '0.00', 'landed_cost_clearing' => '20.00'], $posted->payload['landed_cost']['journal']);
        $this->assertSame($posted->event_uuid, $doc->fresh()->event_uuid);
        $this->assertSame(1, IntegrationDocumentLifecycleMapping::query()->where('source_document_type', 'landed_cost')
            ->where('source_document_id', (string) $doc->id)->where('accounting_source_key', $posted->idempotency_key)->count());

        $original = app(SolaStockJournalContractBuilder::class)->build($posted);
        $this->assertSame('landed_cost.posted', $original['event_type']);
        $this->assertSame([801, 803, 806], array_column($original['lines'], 'account_id'));
        $this->assertSame(['inventory_asset', 'cogs', 'landed_cost_clearing'], array_column($original['lines'], 'account_role'));
        $this->assertSame(['12.00', '8.00', '0.00'], array_column($original['lines'], 'base_debit'));
        $this->assertSame(['0.00', '0.00', '20.00'], array_column($original['lines'], 'base_credit'));
        $this->assertSame(['JOD', '1', 'identity'], [$original['currency']['transaction_code'], $original['currency']['exchange_rate'], $original['currency']['rate_source']]);
        $this->assertSame('10.0000', $original['inventory_quantities'][0]['base_quantity']);
        $this->assertNull($original['source']['reversal']);

        $reversal = $service->reverse($doc->fresh(), 'Freight belonged to another shipment');
        $service->reverse($doc->fresh(), 'Duplicate');
        $this->assertSame('30.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
        $this->assertSame('5.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('average_cost'));
        $reversed = IntegrationOutboxEvent::query()->where('event_type', 'landed_cost.reversed')->sole();
        $this->assertSame(['InventoryReversal', $reversal->id], [$reversed->aggregate_type, (int) $reversed->aggregate_id]);
        $this->assertSame($posted->event_uuid, $reversed->payload['original_source']['event_uuid']);
        $this->assertSame($posted->event_uuid, $reversed->depends_on_event_uuid);
        $this->assertSame('-12.00', $reversed->payload['total_inventory_value_change']);
        $contract = app(SolaStockJournalContractBuilder::class)->build($reversed);
        $this->assertSame($posted->idempotency_key, $contract['source']['reversal']['original_source_key']);
        $this->assertSame([801, 803, 806], array_column($contract['lines'], 'account_id'));
        $this->assertSame(array_column($original['lines'], 'base_debit'), array_column($contract['lines'], 'base_credit'));
        $this->assertSame(array_column($original['lines'], 'base_credit'), array_column($contract['lines'], 'base_debit'));
        $this->assertSame($original['currency'], $contract['currency']);
        $strip = fn (array $q) => array_map(function ($s) { unset($s['ledger_entry_ids']); return $s; }, $q);
        $this->assertSame($strip($original['inventory_quantities']), $strip($contract['inventory_quantities']));
    }
}
