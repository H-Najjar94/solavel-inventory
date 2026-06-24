<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Stage Option A — SolaStock tenant-derived runtime credentials behind a
 * DEFAULT-OFF flag (tenancy.use_derived_db_user / INVENTORY_USE_DERIVED_TENANT_DB_USER).
 *
 * Uses the reserved safe test tenant (tenant_990010) for connection switches; the
 * exact tenant_000008 -> t_000008 mapping is proven in TenantCredentialDeriverTest.
 * No query is run, so no real connection is opened.
 */
class StockDerivedTenantUserTest extends TestCase
{
    private const TEST_SECRET = 'test-derive-secret-0123456789-0123456789-0123456789-0123456789-xx'; // 64 chars
    private const SAFE_DB = 'tenant_990010';

    private function manager(): TenantManager
    {
        return app(TenantManager::class);
    }

    public function test_flag_off_keeps_todays_behaviour_database_only(): void
    {
        config()->set('tenancy.use_derived_db_user', false);
        $sharedUser = config('database.connections.tenant.username');

        $this->manager()->switchToDatabase(self::SAFE_DB);

        $this->assertSame(self::SAFE_DB, config('database.connections.tenant.database'), 'Database should switch as before.');
        $this->assertSame($sharedUser, config('database.connections.tenant.username'), 'Flag OFF must not change the runtime DB user.');
    }

    public function test_flag_on_sets_derived_per_tenant_user_and_password(): void
    {
        config()->set('tenancy.use_derived_db_user', true);
        config()->set('tenancy.derive_secret', self::TEST_SECRET);

        $this->manager()->switchToDatabase(self::SAFE_DB);

        $this->assertSame('t_990010', config('database.connections.tenant.username'), 'Flag ON must use the derived per-tenant user.');

        $pass = config('database.connections.tenant.password');
        $expected = substr(
            rtrim(strtr(base64_encode(hash_hmac('sha256', 'tenant-pass:v1:990010', self::TEST_SECRET, true)), '+/', '-_'), '='),
            0,
            40
        );
        $this->assertSame(40, strlen((string) $pass));
        $this->assertSame($expected, $pass, 'Derived password must match the canonical algorithm.');
    }

    public function test_flag_on_with_missing_secret_fails_closed_and_never_uses_shared_user(): void
    {
        Log::spy();

        config()->set('tenancy.use_derived_db_user', true);
        config()->set('tenancy.derive_secret', ''); // missing secret -> cannot derive
        $sharedUser = config('database.connections.tenant.username');

        $status = null;
        try {
            $this->manager()->switchToDatabase(self::SAFE_DB);
            $this->fail('Expected a 503 fail-closed when the derive secret is missing.');
        } catch (HttpException $e) {
            $status = $e->getStatusCode();
        }

        $this->assertSame(503, $status, 'Missing secret must fail closed with 503.');
        $this->assertSame($sharedUser, config('database.connections.tenant.username'), 'Must NOT fall back to the shared/superuser DB user.');

        // Exactly one safe security event; no secret/password/DSN/exception message logged.
        Log::shouldHaveReceived('critical')->once()->withArgs(function ($message, $context = []) {
            $okEvent = is_array($context) && ($context['event'] ?? null) === 'tenant_credential_resolution_failure';
            $hasClass = array_key_exists('exception_class', $context);
            $noSecrets = empty(array_intersect(array_keys($context), ['password', 'db_pass', 'secret', 'message', 'error']));

            return is_string($message) && $okEvent && $hasClass && $noSecrets;
        });
    }

    public function test_flag_on_does_not_affect_a_request_that_never_switches_tenant(): void
    {
        // Public / no-tenant requests never call switchToDatabase(), so enabling
        // the flag must have no effect and must not fail closed.
        config()->set('tenancy.use_derived_db_user', true);
        config()->set('tenancy.derive_secret', self::TEST_SECRET);

        $before = config('database.connections.tenant.username');
        $this->manager(); // resolve, but do not switch tenants

        $this->assertSame($before, config('database.connections.tenant.username'));
    }

    public function test_stale_tenant_manager_docblock_is_corrected(): void
    {
        $src = file_get_contents((new \ReflectionClass(TenantManager::class))->getFileName());

        $this->assertStringNotContainsString('inventory_tenant_', $src, 'Stale inventory_tenant_ wording must be removed.');
        $this->assertStringNotContainsString('uses its OWN databases', $src, 'Stale "OWN databases" claim must be removed.');
        $this->assertStringContainsString('shares the SAME per-client tenant databases', $src);
    }
}
