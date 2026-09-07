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
