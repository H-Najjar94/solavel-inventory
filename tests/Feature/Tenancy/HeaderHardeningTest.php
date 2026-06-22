<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\LiveTenantResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * High (security) — the X-Organization-Id request header is a LOCAL/TEST dev hook
 * only. In production it must NEVER let an unauthenticated request select a
 * tenant/org (cross-tenant IDOR). Also proves a client with no active org
 * resolves to no_organization rather than crashing or guessing the client id.
 */
class HeaderHardeningTest extends TestCase
{
    private function headerRequest(int $org): Request
    {
        // No session, only the attacker-controlled header.
        return Request::create('/inventory/api/v1/items', 'GET', [], [], [], ['HTTP_X_ORGANIZATION_ID' => (string) $org]);
    }

    #[Test]
    public function x_organization_id_header_is_ignored_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $resolver = app(LiveTenantResolver::class);
        $this->assertSame(0, $resolver->clientId($this->headerRequest(777)), 'header must NOT select a client in prod');
        $this->assertSame(0, $resolver->organizationId($this->headerRequest(777)), 'header must NOT select an org in prod');
    }

    #[Test]
    public function x_organization_id_header_is_honored_only_in_local_dev(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        // In local the dev hook still works (so local tooling can select a tenant).
        $this->assertSame(777, app(LiveTenantResolver::class)->clientId($this->headerRequest(777)));
    }

    #[Test]
    public function client_without_an_active_org_is_no_organization_not_a_crash(): void
    {
        $req = Request::create('/x', 'GET');
        $store = app('session.store');
        $store->put('client_id', 555); // a client/tenant but NO selected org
        $req->setLaravelSession($store);

        $s = app(LiveTenantResolver::class)->state($req);
        $this->assertSame('no_organization', $s['state'], 'client without org → no_organization (no clientId-as-org guess, no crash)');
        $this->assertSame('tenant_000555', $s['database'], 'the per-client DB still resolves');
    }
}
