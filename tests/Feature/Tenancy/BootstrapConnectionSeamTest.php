<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * Platform-wide DB privilege-separation — Model A, Phase A (SolaStock parity).
 *
 * SolaStock does NOT create per-tenant DB users (provisioning is CREATE DATABASE
 * only), so the bootstrap connection is UNUSED here. These tests assert SolaStock
 * carries the SAME env/config contract as the other apps so it is not a special
 * case, and that no GRANT ALL exists in its provisioning code.
 *
 * No live DB required.
 */
class BootstrapConnectionSeamTest extends TestCase
{
    public function test_bootstrap_connection_and_config_exist_for_parity(): void
    {
        $this->assertSame('tenant_bootstrap', config('tenancy.bootstrap_connection'));

        $conn = config('database.connections.tenant_bootstrap');
        $this->assertIsArray($conn, 'tenant_bootstrap connection must exist (platform parity).');
        $this->assertSame('mysql', $conn['driver']);
        $this->assertNull($conn['database']);

        $expected = env('TENANT_DB_BOOTSTRAP_USER', env('TENANT_DB_ADMIN_USER', env('DB_USERNAME', 'root')));
        $this->assertSame($expected, config('database.connections.tenant_bootstrap.username'));
    }

    public function test_three_roles_are_separated_in_config(): void
    {
        // SolaStock's reference model: runtime / provisioner / bootstrap distinct.
        $this->assertSame('tenant', config('tenancy.tenant_connection'));
        $this->assertSame('tenant_admin', config('tenancy.provision_connection'));
        $this->assertSame('tenant_bootstrap', config('tenancy.bootstrap_connection'));
        $this->assertNotSame(config('tenancy.tenant_connection'), config('tenancy.provision_connection'));
        $this->assertNotSame(config('tenancy.provision_connection'), config('tenancy.bootstrap_connection'));
    }

    public function test_no_grant_all_in_provisioner_source(): void
    {
        // SolaStock never creates per-tenant users, so no GRANT ALL should exist.
        $src = file_get_contents(app_path('Services/Tenancy/SecureTenantProvisioner.php'));
        $this->assertStringNotContainsString('GRANT ALL', $src);
        $this->assertStringNotContainsString('CREATE USER', $src);
    }
}
