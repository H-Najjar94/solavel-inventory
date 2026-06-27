<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * PIC #36 — connection-layer previous-secret fallback during a rotation window
 * (mirrors the accepted SolaBooks / SolaProjects / SolaHR reference implementation).
 *
 * In SolaStock the fallback lives in TenantManager::switchToDatabase() and is ONLY
 * ever reached when the per-tenant derived DB user is in use
 * (INVENTORY_USE_DERIVED_TENANT_DB_USER / tenancy.use_derived_db_user = true). With
 * the derived user OFF (default) it is a strict no-op (database switch only), so the
 * INVENTORY_USE_DERIVED_TENANT_DB_USER behavior is unchanged.
 *
 * Verifies the OPT-IN, off-by-default fallback:
 *  - derived-user OFF → never probes (no-op), regardless of the fallback flag
 *  - fallback flag disabled (default) → never probes (identical to today)
 *  - enabled but no previous secret → identical to today
 *  - primary succeeds → previous is not used
 *  - primary AUTH-fails + previous succeeds → previous password kept + SAFE
 *    metadata logged (never the secret/password)
 *  - primary AUTH-fails + previous fails → primary restored (fail-closed preserved)
 *  - NON-auth primary failure → no fallback attempted
 *
 * Uses the reserved safe test tenant (tenant_990010 -> t_990010) and test-only
 * literal secrets via config(); never the real TENANT_DERIVE_SECRET. The DB probe is
 * overridden in a test double so no live mismatched-auth server is required.
 */
class PreviousSecretConnectionFallbackTest extends TestCase
{
    private const PRIMARY = 'primary-test-secret-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const PREVIOUS = 'previous-test-secret-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    private const SAFE_DB = 'tenant_990010';
    private const CLIENT_ID = 990010;

    private function manager(?string $authOkFor, string $errorClass = 'auth'): TestableTenantManager
    {
        $m = new TestableTenantManager(app(OrganizationContext::class));
        $m->authOkFor = $authOkFor;
        $m->errorClass = $errorClass;

        return $m;
    }

    private function passFor(string $secret): string
    {
        return substr(
            rtrim(strtr(base64_encode(hash_hmac('sha256', 'tenant-pass:v1:'.self::CLIENT_ID, $secret, true)), '+/', '-_'), '='),
            0,
            40
        );
    }

    private function enableDerivedUser(string $primary, ?string $previous = null): void
    {
        Config::set('tenancy.use_derived_db_user', true);
        Config::set('tenancy.derive_secret', $primary);
        Config::set('tenancy.derive_previous_secret', $previous);
        Config::set('tenancy.derive_secret_version', 'v9');
    }

    public function test_derived_user_off_is_a_strict_noop_even_with_fallback_enabled(): void
    {
        Config::set('tenancy.use_derived_db_user', false);
        Config::set('tenancy.derive_previous_secret_fallback', true);
        Config::set('tenancy.derive_secret', self::PRIMARY);
        Config::set('tenancy.derive_previous_secret', self::PREVIOUS);

        $m = $this->manager(authOkFor: null);
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(0, $m->probeCount, 'With the derived user OFF the fallback must never run.');
        $this->assertSame(self::SAFE_DB, config('database.connections.tenant.database'));
    }

    public function test_fallback_disabled_by_default_never_probes(): void
    {
        $this->enableDerivedUser(self::PRIMARY, self::PREVIOUS);
        Config::set('tenancy.derive_previous_secret_fallback', false);

        $m = $this->manager(authOkFor: null);
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(0, $m->probeCount, 'Fallback disabled must not probe the connection.');
        $this->assertSame($this->passFor(self::PRIMARY), config('database.connections.tenant.password'));
    }

    public function test_enabled_without_previous_secret_is_identical_to_today(): void
    {
        $this->enableDerivedUser(self::PRIMARY, null);
        Config::set('tenancy.derive_previous_secret_fallback', true);

        $m = $this->manager(authOkFor: null);
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(0, $m->probeCount, 'No previous secret → no probe/fallback.');
        $this->assertSame($this->passFor(self::PRIMARY), config('database.connections.tenant.password'));
    }

    public function test_primary_succeeds_so_previous_is_not_used(): void
    {
        $this->enableDerivedUser(self::PRIMARY, self::PREVIOUS);
        Config::set('tenancy.derive_previous_secret_fallback', true);
        $primary = $this->passFor(self::PRIMARY);

        $m = $this->manager(authOkFor: $primary);
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(1, $m->probeCount, 'Exactly one (successful) primary probe.');
        $this->assertSame($primary, config('database.connections.tenant.password'));
    }

    public function test_primary_auth_fails_and_previous_succeeds_keeps_previous_and_logs_safe_metadata(): void
    {
        $this->enableDerivedUser(self::PRIMARY, self::PREVIOUS);
        Config::set('tenancy.derive_previous_secret_fallback', true);
        $primary = $this->passFor(self::PRIMARY);
        $previous = $this->passFor(self::PREVIOUS);
        $this->assertNotSame($primary, $previous);

        $captured = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$captured) {
            $captured[] = ['message' => $message, 'context' => $context];
        });
        Log::shouldReceive('critical')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $m = $this->manager(authOkFor: $previous);
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(2, $m->probeCount, 'Primary probe + one previous retry.');
        $this->assertSame($previous, config('database.connections.tenant.password'), 'Previous password kept.');
        $this->assertSame('t_990010', config('database.connections.tenant.username'), 'Derived user unchanged.');

        $entry = collect($captured)->firstWhere('message', 'Tenant connection used previous-secret fallback');
        $this->assertNotNull($entry, 'Safe fallback metadata must be logged. Captured: '.json_encode(array_column($captured, 'message')));
        $this->assertTrue($entry['context']['fallback_used']);
        $this->assertSame(self::CLIENT_ID, $entry['context']['client_id']);
        $this->assertSame(self::SAFE_DB, $entry['context']['db_name']);
        $this->assertSame('t_990010', $entry['context']['db_user']);
        $this->assertSame('v9', $entry['context']['secret_version']);

        $flat = json_encode($captured);
        $this->assertStringNotContainsString(self::PRIMARY, $flat);
        $this->assertStringNotContainsString(self::PREVIOUS, $flat);
        $this->assertStringNotContainsString($primary, $flat);
        $this->assertStringNotContainsString($previous, $flat);
    }

    public function test_primary_auth_fails_and_previous_fails_restores_primary_fail_closed(): void
    {
        $this->enableDerivedUser(self::PRIMARY, self::PREVIOUS);
        Config::set('tenancy.derive_previous_secret_fallback', true);
        $primary = $this->passFor(self::PRIMARY);

        $m = $this->manager(authOkFor: 'NO_PASSWORD_AUTHENTICATES');
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(2, $m->probeCount, 'Primary probe + one previous retry, both fail.');
        $this->assertSame(
            $primary,
            config('database.connections.tenant.password'),
            'On total failure the PRIMARY-derived password is restored so existing fail-closed behavior stands.'
        );
    }

    public function test_non_auth_primary_failure_does_not_trigger_fallback(): void
    {
        $this->enableDerivedUser(self::PRIMARY, self::PREVIOUS);
        Config::set('tenancy.derive_previous_secret_fallback', true);
        $primary = $this->passFor(self::PRIMARY);

        $m = $this->manager(authOkFor: null, errorClass: 'nonauth');
        $m->switchToDatabase(self::SAFE_DB);

        $this->assertSame(1, $m->probeCount, 'Non-auth failure must not retry with the previous secret.');
        $this->assertSame($primary, config('database.connections.tenant.password'), 'Primary left intact.');
    }
}

/**
 * Manager double: overrides only the DB probe so the fallback decision logic can be
 * tested without a live server. "Authentication" succeeds iff the currently
 * configured `tenant` password equals $authOkFor; otherwise it throws either an auth
 * (28000/1045) error or a non-auth error per $errorClass.
 */
class TestableTenantManager extends TenantManager
{
    public ?string $authOkFor = null;
    public string $errorClass = 'auth';
    public int $probeCount = 0;

    protected function probeTenantConnection(string $connection): void
    {
        $this->probeCount++;
        $current = (string) config("database.connections.{$connection}.password");

        if ($current === $this->authOkFor) {
            return; // authenticated
        }

        if ($this->errorClass === 'nonauth') {
            throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        }

        throw new PDOException('SQLSTATE[28000] [1045] Access denied for user');
    }
}
