<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\SecureTenantProvisioner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 1 of DB privilege separation: provisioning/migrations run through a
 * dedicated ELEVATED connection (`tenant_admin`), SEPARATE from the runtime
 * `tenant` connection — so the runtime user can later be reduced to DML-only
 * without breaking new-client activation.
 *
 * These are real integration tests: they create + drop a disposable scratch
 * database (`tenant_prov_it`) on the live MySQL to prove activation still
 * creates and migrates a tenant DB. They never touch a real or reserved tenant.
 */
class ProvisioningElevatedConnectionTest extends TestCase
{
    private string $scratchDb = 'tenant_prov_it';

    protected function tearDown(): void
    {
        // Always drop the scratch DB, even on failure. Real tenants are numeric
        // (tenant_000123); this name can never collide with one.
        try {
            DB::connection('mysql_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratchDb}`");
            DB::purge('tenant_admin');
        } catch (\Throwable $e) {
            // best-effort cleanup
        }
        parent::tearDown();
    }

    private function dropScratch(): void
    {
        DB::connection('mysql_admin')->statement("DROP DATABASE IF EXISTS `{$this->scratchDb}`");
        DB::purge('tenant_admin');
    }

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
    public function provisioning_creates_and_migrates_via_the_elevated_connection(): void
    {
        $this->dropScratch();
        $runtimeDbBefore = config('database.connections.tenant.database');

        $result = app(SecureTenantProvisioner::class)->provisionInventory(970001, $this->scratchDb);

        // Ran through the elevated provisioning connection, NOT the runtime one.
        $this->assertSame('tenant_admin', $result['connection']);
        $this->assertNotSame(
            config('tenancy.tenant_connection', 'tenant'),
            $result['connection'],
            'migrations must not run on the runtime tenant connection'
        );

        // DB created + migrated.
        $this->assertTrue($result['created'], 'scratch DB should be created on first provision');
        $this->assertTrue($result['migrated']);
        $this->assertSame($this->scratchDb, $result['database']);

        // Real SolaStock tables exist in the new DB (verified via the elevated conn).
        Config::set('database.connections.tenant_admin.database', $this->scratchDb);
        DB::purge('tenant_admin');
        foreach (['inventory_settings', 'stock_ledger', 'cost_layers', 'cost_layer_consumptions'] as $table) {
            $this->assertTrue(
                Schema::connection('tenant_admin')->hasTable($table),
                "expected provisioned table '{$table}' to exist in {$this->scratchDb}"
            );
        }

        // Provisioning must NOT have mutated the runtime `tenant` connection.
        $this->assertSame(
            $runtimeDbBefore,
            config('database.connections.tenant.database'),
            'provisioning must not disturb the runtime tenant connection'
        );
    }

    #[Test]
    public function provisioning_is_idempotent_through_the_elevated_connection(): void
    {
        $this->dropScratch();

        $first = app(SecureTenantProvisioner::class)->provisionInventory(970001, $this->scratchDb);
        $this->assertTrue($first['created']);
        $this->assertSame('tenant_admin', $first['connection']);

        // Second activation of the same tenant: DB already exists → created=false,
        // migrations report nothing new, no error, still via the elevated conn.
        $second = app(SecureTenantProvisioner::class)->provisionInventory(970001, $this->scratchDb);
        $this->assertFalse($second['created'], 'existing DB must not be re-created');
        $this->assertTrue($second['migrated']);
        $this->assertSame('tenant_admin', $second['connection']);
    }
}
