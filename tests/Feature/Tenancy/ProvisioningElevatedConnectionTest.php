<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\SecureTenantProvisioner;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Production provisioning is external. Application tests prove the legacy seam
 * is disabled without requiring or loading production DDL credentials.
 */
class ProvisioningElevatedConnectionTest extends TestCase
{
    #[Test]
    public function the_elevated_provisioning_connection_is_configured_and_separate_from_runtime(): void
    {
        $this->assertSame('tenant_admin', config('tenancy.provision_connection'));
        $this->assertNotSame(
            config('tenancy.tenant_connection'),
            config('tenancy.provision_connection'),
            'provisioning connection must be distinct from the runtime tenant connection'
        );
        $this->assertIsArray(config('database.connections.tenant_admin'));
    }

    #[Test]
    public function production_compatibility_connections_have_no_credentials(): void
    {
        $this->assertNull(config('database.connections.mysql_admin.username'));
        $this->assertNull(config('database.connections.mysql_admin.password'));
        $this->assertNull(config('database.connections.tenant_admin.username'));
        $this->assertNull(config('database.connections.tenant_admin.password'));
    }

    #[Test]
    public function external_policy_refuses_local_provisioning_before_any_ddl(): void
    {
        config()->set('tenancy.external_orchestrator_only', true);
        config()->set('database.connections.tenant.database', 'solastock_test_a');
        DB::purge('tenant');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('external canonical orchestrator');
        app(SecureTenantProvisioner::class)->provisionInventory(970001, 'tenant_prov_it');
    }
}
