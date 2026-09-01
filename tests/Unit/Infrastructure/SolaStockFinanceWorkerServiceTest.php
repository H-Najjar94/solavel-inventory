<?php

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class SolaStockFinanceWorkerServiceTest extends TestCase
{
    public function test_service_uses_server_owned_allowlist_supervisor_without_a_caller_tenant(): void
    {
        $unit = file_get_contents(dirname(__DIR__, 3).'/deploy/solastock-finance-v2-worker.service');

        $this->assertIsString($unit);
        $this->assertStringContainsString('integration:transport-supervise', $unit);
        $this->assertStringNotContainsString('TENANT_DATABASE', $unit);
        $this->assertStringNotContainsString('solapos', strtolower($unit));
        $this->assertStringContainsString('APP_CONFIG_CACHE=/run/solastock-finance-v2-worker-config.php', $unit);
        $this->assertStringContainsString('NoNewPrivileges=true', $unit);
        $this->assertStringContainsString('ProtectSystem=full', $unit);
        $this->assertStringContainsString('/var/lib/solavel/solastock-finance-v2', $unit);
    }
}
