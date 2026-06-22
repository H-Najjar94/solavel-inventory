<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\LiveTenantResolver;
use App\Services\Tenancy\SecureTenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the Solavel-style live tenant resolution. The DB probe inside
 * the resolver fails closed to a clear state (tenant_unreachable) when MySQL is
 * not reachable from the test process — these assertions never require a live DB.
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
        $s = app(LiveTenantResolver::class)->state($this->request(['client_id' => 123, 'selected_central_org_id' => 123]));
        $this->assertSame('tenant_000123', $s['database']);
        $this->assertSame('live', $s['mode']);
        // DB is unreachable in the test process → a precise setup/unreachable state,
        // NEVER a silent sample fallback for a real org.
        $this->assertContains($s['state'], ['live_ready', 'tenant_missing', 'tenant_unmigrated', 'tenant_unreachable']);
        $this->assertNotSame('sample_preview', $s['state']);
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
        foreach (['api.v1.tenant.status', 'api.v1.tenant.select-demo', 'api.v1.tenant.clear', 'api.v1.tenant.provision'] as $n) {
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
        );
        $m = new \ReflectionMethod($ctrl, 'badgeFor');
        $m->setAccessible(true);

        $map = [
            'live_ready' => 'real',
            'demo_preview' => 'demo',
            'tenant_missing' => 'setup',
            'tenant_unmigrated' => 'setup',
            'tenant_unreachable' => 'setup',
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
    public function live_ready_carries_org_scope_and_client_db_key(): void
    {
        // org-scope (organization_id) and DB key (client_id) are distinct values.
        $s = app(LiveTenantResolver::class)->state($this->request([
            'client_id' => 8, 'selected_central_org_id' => 10,
        ]));
        $this->assertSame('tenant_000008', $s['database']);   // DB keyed by client
        $this->assertSame(10, $s['organization_id']);          // rows scoped by org
        $this->assertSame(8, $s['client_id']);
    }
}
