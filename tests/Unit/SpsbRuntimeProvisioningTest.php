<?php
namespace Tests\Unit;

use App\Services\Tenancy\SecureTenantProvisioner;
use App\Services\Tenancy\TenantSchemaAuditService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SpsbRuntimeProvisioningTest extends TestCase
{
    protected function tearDown(): void { \Mockery::close(); Facade::clearResolvedInstances(); parent::tearDown(); }

    private function configure(bool $ready): void
    {
        $app = new Container; Container::setInstance($app); Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['tenancy' => ['external_orchestrator_only' => false]]));
        Artisan::swap(\Mockery::mock(\Illuminate\Contracts\Console\Kernel::class));
        Artisan::shouldReceive('call')->never();
        $tenant = \Mockery::mock(); $tenant->shouldReceive('getDatabaseName')->andReturn('tenant_000024');
        // Any attempt to select mysql_admin/tenant_admin fails this expectation.
        DB::swap(\Mockery::mock(\Illuminate\Database\DatabaseManager::class));
        DB::shouldReceive('connection')->with('tenant')->andReturn($tenant);
        $audit = \Mockery::mock(TenantSchemaAuditService::class);
        $audit->shouldReceive('audit')->with('tenant')->andReturn(['ok' => $ready]);
        $app->instance(TenantSchemaAuditService::class, $audit);
    }

    public function test_verified_static_schema_never_runs_historical_migrations(): void
    {
        $this->configure(true);
        $result = (new SecureTenantProvisioner)->provisionInventory(24, 'tenant_000024');
        self::assertFalse($result['migrated']); self::assertFalse($result['created']);
        self::assertSame('central_spsb', $result['setup_mode']);
    }

    public function test_incomplete_static_schema_fails_without_migration_fallback(): void
    {
        $this->configure(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('approved SPSB setup');
        (new SecureTenantProvisioner)->provisionInventory(24, 'tenant_000024');
    }

    public function test_setup_refuses_to_verify_a_different_tenant(): void
    {
        $this->configure(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('active tenant database');
        (new SecureTenantProvisioner)->provisionInventory(25, 'tenant_000025');
    }
}
