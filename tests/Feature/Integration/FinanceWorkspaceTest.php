<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Warehouse;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        $central->table('entitlement_state_snapshots')->updateOrInsert(['organization_id' => TenantTestManager::ORG_A], [ 'underlying_subscription_state' => 'paid_active',
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
            'integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14]);
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
        $zone = \App\Models\Tenant\WarehouseZone::create(['warehouse_id' => $warehouse->id, 'code' => 'Z-1', 'name' => 'Zone one']);
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
            ->assertJsonPath('data.ready', true)->assertJsonPath('data.writable', false)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.index']['allowed'] === true)
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.store']['allowed'] === false);
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->app->forgetInstance(\App\Services\Access\InventoryPermissionService::class);
        $this->send(['action' => 'workspace.context'])->assertOk()
            ->assertJsonPath('data.actions', fn ($a) => $a['warehouses.store']['allowed'] === false);
    }

    public function test_global_delivery_enablement_does_not_unhold_a_mapping(): void
    {
        IntegrationOrganizationMapping::query()->update(['status' => 'verified_hold', 'activation_state' => 'maintenance_hold']);
        $this->send(['action' => 'workspace.context'])->assertOk()->assertJsonPath('data.writable', false);
        $this->send(['action' => 'warehouses.index'])->assertOk();
        $this->send(['action' => 'warehouses.store', 'data' => ['name' => 'Still held', 'code' => 'STILL-HELD', 'type' => 'warehouse'],
            'idempotency_key' => 'held-mapping-global-enable-001'])->assertStatus(409);
        $this->assertSame(0, Warehouse::query()->where('code', 'STILL-HELD')->count());
    }

    public function test_trace_reads_are_bounded_and_respect_selected_warehouse(): void
    {
        $warehouse = \Tests\Support\StockTestFactory::warehouse();
        $other = \Tests\Support\StockTestFactory::warehouse();
        $item = \Tests\Support\StockTestFactory::lotItem();
        $lot = \Tests\Support\StockTestFactory::lot($item);
        for ($i = 1; $i <= 26; $i++) {
            app(\App\Services\Stock\StockLedgerService::class)->post([
                new \App\Services\Stock\StockMovement(direction: 'in', itemId: $item->id,
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
        $warehouse = \Tests\Support\StockTestFactory::warehouse();
        $item = \Tests\Support\StockTestFactory::item(['name' => 'رصيد مخزون افتتاحي']);
        $create = ['action' => 'opening.store', 'idempotency_key' => 'migration-opening-draft-001',
            'data' => ['warehouse_id' => $warehouse->id, 'opening_date' => '2026-09-01',
                'lines' => [['item_id' => $item->id, 'quantity' => '4.0000', 'unit_cost' => '10.0000']]]];
        $response = $this->send($create)->assertCreated()->assertJsonPath('data.total_value', '40.00');
        $id = $response->json('data.id');
        $this->send($create)->assertCreated()->assertHeader('X-Workspace-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame(1, \App\Models\Tenant\OpeningStockEntry::query()->count());
        $this->assertSame(0, \App\Models\Tenant\StockLedger::query()->count());
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
        $this->assertSame(0, \App\Models\Tenant\StockLedger::query()->count());
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->app->forgetInstance(\App\Services\Access\InventoryPermissionService::class);
        $this->send(array_replace($create, ['idempotency_key' => 'migration-viewer-001']))->assertForbidden();
        $this->assertSame(1, \App\Models\Tenant\OpeningStockEntry::withoutGlobalScopes()->where('organization_id', TenantTestManager::ORG_A)->count());
    }

    public function test_opening_posts_through_owner_ledger_and_outbox_and_replays_without_duplicates(): void
    {
        $org = TenantTestManager::ORG_A;
        $warehouse = \Tests\Support\StockTestFactory::warehouse();
        $unit = \App\Models\Tenant\Unit::create(['code' => 'OPEN-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = \Tests\Support\StockTestFactory::item(['base_unit_id' => $unit->id]);
        foreach (['item' => [$item->id, 901], 'unit' => [$unit->id, 902]] as $type => [$stockId, $financeId]) {
            \App\Models\Tenant\IntegrationMasterDataMapping::create([
                'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => IntegrationOrganizationMapping::query()->firstOrFail()->mapping_uuid,
                'central_client_id' => self::CLIENT, 'central_organization_id' => $org,
                'finance_organization_id' => 14, 'solastock_organization_id' => $org,
                'entity_type' => $type, 'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified',
            ]);
        }
        foreach (['inventory_asset' => [801, 'asset'], 'opening_offset' => [802, 'equity']] as $role => [$id, $type]) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'name' => $role, 'type' => $type]);
            \App\Models\Tenant\IntegrationAccountMapping::create(['organization_id' => $org, 'integration' => 'solabooks',
                'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
        }
        IntegrationSetting::query()->firstOrFail()->update(['meta' => [
            'client_id' => self::CLIENT, 'central_organization_id' => $org, 'signing_key_id' => 'synthetic-test-key',
            'transport_enabled_workflows' => ['opening_stock.posted', 'opening_stock.reversed'],
            'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8,
                'inventory_valuation_basis' => \App\Services\Integration\FinanceBaseValuation::BASIS],
        ]]);
        $requirements=['action'=>'opening.requirements','data'=>['warehouse_id'=>$warehouse->id,'finance_item_ids'=>[901]]];
        $before=$this->send($requirements)->assertOk()->assertJsonPath('data.items.0.quantity','0.0000')
            ->assertJsonPath('data.inventory_account_id',801)->assertJsonPath('data.opening_offset_account_id',802);
        $this->send(array_replace_recursive($requirements,['data'=>['finance_item_ids'=>[999999]]]))->assertUnprocessable();
        $identity=\App\Models\Tenant\IntegrationMasterDataMapping::query()->where('entity_type','item')->where('solabooks_record_id','901')->firstOrFail();
        $identity->update(['solabooks_archived'=>true]);
        $this->send($requirements)->assertUnprocessable();
        $identity->update(['solabooks_archived'=>false,'error_state'=>['code'=>'synthetic_conflict']]);
        $this->send($requirements)->assertUnprocessable();
        $identity->update(['error_state'=>null]);
        $this->assertSame(0,\App\Models\Tenant\OpeningStockEntry::query()->count());
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
        $this->assertSame('4.0000', \App\Models\Tenant\StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $ledger = \App\Models\Tenant\StockLedger::query()->where('source_type', \App\Models\Tenant\OpeningStockEntry::class)->where('source_id', $id)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('40.00', $ledger[0]->total_cost);
        $events = \App\Models\Tenant\IntegrationOutboxEvent::query()->where('event_type', 'opening_stock.posted')->get();
        $this->assertCount(1, $events);
        $contract = app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($events[0]);
        $this->assertSame(801, $contract['lines'][0]['account_id']);
        $this->assertSame('40.00', $contract['lines'][0]['base_debit']);
        $this->assertSame(802, $contract['lines'][1]['account_id']);
        $this->assertSame('40.00', $contract['lines'][1]['base_credit']);
        $this->assertSame(901, $contract['inventory_quantities'][0]['finance_item_id']);
        $this->assertSame('4.0000', $contract['inventory_quantities'][0]['base_quantity']);
        $after=$this->send($requirements)->assertOk()->assertJsonPath('data.items.0.quantity','4.0000')->assertJsonPath('data.items.0.value','40.00');
        $this->assertNotSame($before->json('data.version'),$after->json('data.version'));
        $this->send(['action' => 'opening.show', 'parameters' => ['entry' => $id]])->assertOk()
            ->assertJsonCount(1, 'data.accounting_events')->assertJsonPath('data.accounting_events.0.event_uuid', $events[0]->event_uuid);
        $migration=['action'=>'opening.migrate','idempotency_key'=>'migration-atomic-opening-001',
            'data'=>['session_id'=>(string) \Illuminate\Support\Str::uuid(),'warehouse_id'=>$warehouse->id,
                'cutover_date'=>'2026-09-01','requirements_version'=>$before->json('data.version'),
                'lines'=>[['finance_item_id'=>901,'quantity'=>'4.0000','unit_cost'=>'10.0000','total_value'=>'40.00']]]];
        $this->send($migration)->assertUnprocessable();
        $this->assertSame(1,\App\Models\Tenant\OpeningStockEntry::query()->count());
        $migration['data']['requirements_version']=$after->json('data.version');
        $invalid=$migration; $invalid['data']['lines'][0]['total_value']='39.00';
        $this->send($invalid)->assertUnprocessable();
        $this->assertSame(1,\App\Models\Tenant\OpeningStockEntry::query()->count());
        $this->send($migration)->assertOk()->assertJsonPath('data.status','posted')
            ->assertJsonPath('data.positions.0.posted_value','40.00')->assertJsonPath('data.positions.0.value_difference','0.00');
        $this->send($migration)->assertOk()->assertHeader('X-Workspace-Replayed','true');
        $this->assertSame(2,\App\Models\Tenant\OpeningStockEntry::query()->count());
        $this->assertSame(2,\App\Models\Tenant\IntegrationOutboxEvent::query()->where('event_type','opening_stock.posted')->count());
        $this->assertSame('8.0000',\App\Models\Tenant\StockBalance::query()->where('item_id',$item->id)->value('on_hand_qty'));

    }

    public function test_opening_access_requires_completed_finance_onboarding(): void
    {
        DB::connection('tenant')->table('organizations')->where('id', 14)->update(['finance_setup_completed_at' => null]);
        $this->send(['action' => 'opening.index'])->assertForbidden()->assertJsonPath('message', 'workspace_integration_not_entitled');
    }

    private function centralFixtureSchema(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasColumn('users', 'deleted_at')) {
            $schema->table('users', fn ($t) => $t->softDeletes());
        }
        foreach (['clients', 'projects', 'organization_projects', 'user_projects'] as $name) {
            if (! $schema->hasTable($name)) {
                $schema->create($name, function ($t) use ($name) {
                    $t->id(); $t->boolean('is_active')->default(true); $t->softDeletes();
                    if ($name === 'projects') { $t->string('slug'); }
                    if (in_array($name, ['organization_projects', 'user_projects'], true)) {
                        $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('organization_id');
                    }
                    if ($name === 'user_projects') { $t->unsignedBigInteger('user_id'); }
                });
            }
        }
    }
}
