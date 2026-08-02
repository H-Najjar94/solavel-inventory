<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\LiveTenantResolver;
use App\Services\Tenancy\SecureTenantProvisioner;
use App\Services\Tenancy\TenantManager;
use App\Services\Tenancy\TenantSchemaAuditService;
use App\Services\Tenancy\TenantResolver;
use App\Services\Access\InventoryPermissionService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Checks for the Solavel-style live tenant resolution. Customer launch follows
 * central enablement and permissions; schema diagnostics are admin-only.
 */
class LiveTenantResolverTest extends TestCase
{
    private function request(array $session = []): Request
    {
        $req = Request::create('/inventory/api/v1/items', 'GET');
        $store = app('session.store');
        foreach ($session as $k => $v) {
            $store->put($k, $v);
        }
        $req->setLaravelSession($store);

        return $req;
    }

    #[Test]
    public function no_session_is_sample_preview(): void
    {
        $s = app(LiveTenantResolver::class)->state($this->request());
        $this->assertSame('sample_preview', $s['state']);
        $this->assertSame('none', $s['mode']);
        $this->assertFalse($s['authenticated']);
    }

    #[Test]
    public function live_org_resolves_shared_tenant_db_name(): void
    {
        // client_id 123 → shared tenant_000123 (same convention as Finance/Projects/HR).
        // A live session carries BOTH a client and a selected org (the client id
        // is NOT used as an org — that was the wrong-org bug).
        DB::connection('mysql')->table('organizations')->updateOrInsert(
            ['id' => 123],
            ['central_organization_id' => 123, 'client_id' => 123, 'name' => 'Resolver fixture', 'database_name' => 'tenant_000123', 'is_active' => 1],
        );
        $s = app(LiveTenantResolver::class)->state($this->request(['client_id' => 123, 'selected_central_org_id' => 123]));
        $this->assertSame('tenant_000123', $s['database']);
        $this->assertSame('live', $s['mode']);
        $this->assertSame('live_ready', $s['state']);
    }

    #[Test]
    public function client_id_keys_the_tenant_db_not_the_org_id(): void
    {
        // The tenant DB is keyed by CLIENT id (tenant_{clientId}), like Finance.
        // When the handoff carries client_id directly, it wins — the org id is
        // only used to look up the owning client when client_id is absent.
        $s = app(LiveTenantResolver::class)->state($this->request([
            'client_id' => 123, 'selected_central_org_id' => 456,
        ]));
        $this->assertSame('tenant_000123', $s['database']);
    }

    #[Test]
    public function live_org_takes_precedence_over_demo_selection(): void
    {
        // Even with the demo flag set, a live org wins (live > demo).
        $s = app(LiveTenantResolver::class)->state($this->request([
            'client_id' => 123, 'selected_central_org_id' => 123, 'inventory_demo_tenant' => true,
        ]));
        $this->assertSame('live', $s['mode']);
        $this->assertSame('tenant_000123', $s['database']);
    }

    #[Test]
    public function resolver_never_uses_finance_or_projects_db_for_a_live_org(): void
    {
        // Whatever the client id, the resolved DB is tenant_{id} — SolaStock owns
        // only its own tables there; it never substitutes a Finance/Projects DB.
        $s = app(LiveTenantResolver::class)->state($this->request(['client_id' => 1, 'selected_central_org_id' => 1]));
        $this->assertSame('tenant_000001', $s['database']);
    }

    #[Test]
    public function provisioner_refuses_forbidden_databases(): void
    {
        $p = app(SecureTenantProvisioner::class);
        foreach (['tenant_990001', 'tenant_990002', 'solavel_finance', 'solavel'] as $bad) {
            try {
                $p->provisionInventory(123, $bad);
                $this->fail("Provisioner must refuse forbidden DB {$bad}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('forbidden', strtolower($e->getMessage()));
            }
        }
    }

    #[Test]
    public function tenant_and_provision_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach (['api.v1.tenant.status', 'api.v1.tenant.select-demo', 'api.v1.tenant.clear', 'api.v1.tenant.provision', 'api.v1.tenant.schema-audit'] as $n) {
            $this->assertContains($n, $names, "missing route {$n}");
        }
        // Tenant routes must NOT be tenant-gated (that's how status/selection work).
        $status = Route::getRoutes()->getByName('api.v1.tenant.status');
        $this->assertNotContains('inv.tenant', $status->gatherMiddleware());
    }

    /**
     * The /tenant/status data_state is the contract the frontend uses to decide
     * sample fallback. ONLY 'sample' may permit it; live/setup/no-access/no-org
     * must NOT — otherwise a logged-in user could see sample data.
     */
    #[Test]
    public function data_state_only_allows_sample_for_explicit_sample_preview(): void
    {
        $ctrl = new \App\Http\Controllers\Api\V1\TenantController(
            app(\App\Services\Tenancy\TenantResolver::class),
            app(LiveTenantResolver::class),
            app(\App\Services\Tenancy\TenantManager::class),
        );
        $m = new \ReflectionMethod($ctrl, 'badgeFor');
        $m->setAccessible(true);

        $map = [
            'live_ready' => 'real',
            'demo_preview' => 'demo',
            'tenant_missing' => 'setup',
            'tenant_unmigrated' => 'setup',
            'tenant_unreachable' => 'setup',
            'schema_failed' => 'setup',
            'no_organization' => 'no_organization',
            'no_access' => 'no_access',
            'sample_preview' => 'sample',
        ];
        foreach ($map as $state => $expectedDataState) {
            [$badge, $ds] = $m->invoke($ctrl, $state);
            $this->assertSame($expectedDataState, $ds, "state {$state} must map to data_state {$expectedDataState}");
        }

        // The ONLY data_state that enables sample fallback is 'sample'.
        $sampleStates = array_keys(array_filter($map, fn ($ds) => $ds === 'sample'));
        $this->assertSame(['sample_preview'], $sampleStates, 'only sample_preview may enable sample fallback');
    }

    #[Test]
    public function schema_audit_requires_settings_admin_permission_before_touching_tenant(): void
    {
        $live = \Mockery::mock(LiveTenantResolver::class);
        $live->shouldReceive('state')->once()->andReturn([
            'state' => 'live_ready',
            'mode' => 'live',
            'organization_id' => 660066,
            'database' => 'tenant_660066',
            'can_access' => true,
        ]);

        $tenants = \Mockery::mock(TenantManager::class);
        $tenants->shouldNotReceive('useTenant');
        $auditor = \Mockery::mock(TenantSchemaAuditService::class);
        $auditor->shouldNotReceive('audit');
        $auditor->shouldNotReceive('auditDatabase');

        $permissions = new class(app(OrganizationContext::class)) extends InventoryPermissionService {
            public function can(?object $user, string $permission): bool
            {
                return false;
            }
        };

        $ctrl = new \App\Http\Controllers\Api\V1\TenantController(
            app(TenantResolver::class),
            $live,
            $tenants,
        );

        $res = $ctrl->schemaAudit($this->request(['client_id' => 660066, 'selected_central_org_id' => 660066]), $auditor, $permissions);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('forbidden', $res->getData(true)['error']['code']);
    }

    #[Test]
    public function schema_audit_reports_missing_database_read_only_without_switching_tenant(): void
    {
        $live = \Mockery::mock(LiveTenantResolver::class);
        $live->shouldReceive('state')->once()->andReturn([
            'state' => 'tenant_missing',
            'mode' => 'live',
            'organization_id' => 660066,
            'database' => 'tenant_660066',
            'can_access' => true,
        ]);

        $tenants = \Mockery::mock(TenantManager::class);
        $tenants->shouldNotReceive('useTenant');
        $auditor = \Mockery::mock(TenantSchemaAuditService::class);
        $auditor->shouldReceive('auditDatabase')->once()->with('tenant_660066', 'mysql')->andReturn([
            'ok' => false,
            'status' => 'fail',
            'database' => 'tenant_660066',
            'missing_database' => true,
            'missing_tables' => [],
            'missing_columns' => [],
            'missing_indexes' => [],
            'warnings' => ['Tenant database does not exist.'],
        ]);

        $permissions = new class(app(OrganizationContext::class)) extends InventoryPermissionService {
            public function can(?object $user, string $permission): bool
            {
                return $permission === 'inventory.manage_settings';
            }
        };

        $ctrl = new \App\Http\Controllers\Api\V1\TenantController(
            app(TenantResolver::class),
            $live,
            $tenants,
        );

        $res = $ctrl->schemaAudit($this->request(['client_id' => 660066, 'selected_central_org_id' => 660066]), $auditor, $permissions);
        $payload = $res->getData(true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertFalse($payload['data']['ok']);
        $this->assertTrue($payload['data']['missing_database']);
    }

    #[Test]
    public function app_provisioning_refuses_missing_database_tenants(): void
    {
        $live = \Mockery::mock(LiveTenantResolver::class);
        $live->shouldReceive('state')->once()->andReturn([
            'state' => 'tenant_missing',
            'mode' => 'live',
            'organization_id' => 660066,
            'client_id' => 660066,
            'database' => 'tenant_660066',
            'can_access' => true,
            'can_provision' => false,
        ]);

        $provisioner = \Mockery::mock(SecureTenantProvisioner::class);
        $provisioner->shouldNotReceive('provisionInventory');

        $ctrl = new \App\Http\Controllers\Api\V1\TenantController(
            app(TenantResolver::class),
            $live,
            app(TenantManager::class),
        );

        $res = $ctrl->provision($this->request(['client_id' => 660066, 'selected_central_org_id' => 660066]), $provisioner);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame('not_provisionable', $res->getData(true)['error']['code']);
    }

    #[Test]
    public function live_ready_carries_org_scope_and_client_db_key(): void
    {
        // org-scope (organization_id) and DB key (client_id) are distinct values.
        DB::connection('mysql')->table('organizations')->updateOrInsert(
            ['id' => 10],
            ['central_organization_id' => 10, 'client_id' => 8, 'name' => 'Resolver fixture', 'database_name' => 'tenant_000008', 'is_active' => 1],
        );
        $s = app(LiveTenantResolver::class)->state($this->request([
            'client_id' => 8, 'selected_central_org_id' => 10,
        ]));
        $this->assertSame('tenant_000008', $s['database']);   // DB keyed by client
        $this->assertSame(10, $s['organization_id']);          // rows scoped by org
        $this->assertSame(8, $s['client_id']);
    }
}
