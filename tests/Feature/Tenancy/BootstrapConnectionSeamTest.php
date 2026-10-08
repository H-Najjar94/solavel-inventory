<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * Production tenant lifecycle belongs to Central's external orchestrator.
 * Compatibility connection definitions may remain, but they carry no active
 * production credentials and are never used by the application runtime.
 *
 * No live DB required.
 */
class BootstrapConnectionSeamTest extends TestCase
{
    public function test_bootstrap_compatibility_connection_has_no_active_credentials(): void
    {
        $this->assertSame('tenant_bootstrap', config('tenancy.bootstrap_connection'));

        $conn = config('database.connections.tenant_bootstrap');
        $this->assertIsArray($conn, 'tenant_bootstrap connection must exist (platform parity).');
        $this->assertSame('mysql', $conn['driver']);
        $this->assertNull($conn['database']);

        $this->assertNull($conn['username']);
        $this->assertNull($conn['password']);
    }

    public function test_external_orchestrator_policy_keeps_runtime_separate_from_legacy_seams(): void
    {
        config()->set('tenancy.external_orchestrator_only', true);
        $this->assertSame('tenant', config('tenancy.tenant_connection'));
        $this->assertSame('tenant_admin', config('tenancy.provision_connection'));
        $this->assertSame('tenant_bootstrap', config('tenancy.bootstrap_connection'));
        $this->assertNotSame(config('tenancy.tenant_connection'), config('tenancy.provision_connection'));
        $this->assertNotSame(config('tenancy.provision_connection'), config('tenancy.bootstrap_connection'));
        $this->assertTrue(config('tenancy.external_orchestrator_only'));
    }

    public function test_no_grant_all_in_provisioner_source(): void
    {
        // SolaStock never creates per-tenant users, so no GRANT ALL should exist.
        $src = file_get_contents(app_path('Services/Tenancy/SecureTenantProvisioner.php'));
        $this->assertStringNotContainsString('GRANT ALL', $src);
        $this->assertStringNotContainsString('CREATE USER', $src);
    }
}
