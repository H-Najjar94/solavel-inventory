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
        $this->assertLessThan(strpos($source, 'ensureDatabase($db)'), strpos($source, "config('tenancy.external_orchestrator_only'"));
    }
}
