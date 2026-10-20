<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExternalTenantOrchestratorPolicyTest extends TestCase
{
    #[Test]
    public function external_orchestrator_guard_precedes_all_local_provisioning_ddl(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/app/Services/Tenancy/SecureTenantProvisioner.php');

        $this->assertStringContainsString('TENANT_EXTERNAL_ORCHESTRATOR_ONLY', file_get_contents($root.'/config/tenancy.php'));
        $this->assertStringNotContainsString('ensureDatabase(', $source);
        $this->assertStringContainsString("audit('tenant')", $source);
        $this->assertStringNotContainsString("env('TENANT_DB_ADMIN_USER', env('DB_USERNAME'", file_get_contents($root.'/config/database.php'));
    }
}
