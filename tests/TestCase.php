<?php

namespace Tests;

use App\Tenancy\TenancySafetyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ── Hard safety rails (mirrors solavel-finance TestCase) ──────────────

        // 1. RefreshDatabase would run migrate:fresh and could destroy a real
        //    schema. Tenant-backed tests must use Tests\Traits\TenantAware.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->fail(
                static::class.' uses RefreshDatabase, which is unsafe for db-per-tenant '
                .'testing. Use Tests\\Traits\\TenantAware (transaction rollback) instead.'
            );
        }

        // 2. Must be the testing environment.
        if (app()->environment() !== 'testing') {
            $this->fail('Tests must run with APP_ENV=testing. Got: '.app()->environment());
        }

        // 3. Resolved CONFIG (not just env) for the active TENANT connection must
        //    be a safe test database (reserved tenant_99xxxx range or the
        //    solastock_test_ namespace). This catches a stale
        //    bootstrap/cache/config.php that shadows phpunit.xml env values — the
        //    exact failure mode Finance documented. Fail loudly with the fix.
        $this->assertSafeResolvedConnections();
    }

    /**
     * Validate the *resolved* connection config (config(), not env()). A blank or
     * unsafe tenant database name almost always means the config cache is stale
     * and is shadowing phpunit.xml. Fail with the remedy rather than silently
     * using a wrong/production database.
     */
    private function assertSafeResolvedConnections(): void
    {
        // The tenant connection is the DEFAULT (Finance model) and is what tests
        // read/write — it must point at the reserved test tenant (tenant_990002).
        $tenant = (string) config('tenancy.tenant_connection', 'tenant');
        $tenantDb = config("database.connections.{$tenant}.database");

        if (empty($tenantDb)) {
            $this->fail(
                "Tenant connection '{$tenant}' has no database name. The config cache is likely "
                .'stale and shadowing phpunit.xml. Run: php artisan config:clear && php artisan cache:clear'
            );
        }

        try {
            TenancySafetyGuard::assertSafeTestDatabase($tenantDb);
        } catch (\Throwable $e) {
            $this->fail('Unsafe tenant test database: '.$e->getMessage());
        }

        // If the central/landlord connection has a database set, it must be safe
        // too (it may be unset in the Finance-style single-DB model).
        $central = (string) config('tenancy.central_connection', 'mysql');
        $centralDb = config("database.connections.{$central}.database");
        if (! empty($centralDb)) {
            try {
                TenancySafetyGuard::assertSafeTestDatabase($centralDb);
            } catch (\Throwable $e) {
                $this->fail('Unsafe central test database: '.$e->getMessage());
            }
        }
    }
}
