<?php

namespace Tests\Unit\Tenancy;

use App\Services\Tenancy\TenantCredentialDeriver;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Stage-Option-A: the ported deterministic deriver must produce the SAME
 * per-tenant credentials the other apps already provisioned, derived from the
 * tenant DATABASE NAME (not a raw org id).
 */
class TenantCredentialDeriverTest extends TestCase
{
    private const TEST_SECRET = 'test-derive-secret-0123456789-0123456789-0123456789-0123456789-xx'; // 64 chars

    private function deriver(): TenantCredentialDeriver
    {
        config()->set('tenancy.derive_secret', self::TEST_SECRET);

        return new TenantCredentialDeriver();
    }

    public function test_derives_user_from_database_name_tenant_000008_to_t_000008(): void
    {
        $creds = $this->deriver()->deriveFromDatabaseName('tenant_000008');

        $this->assertSame('t_000008', $creds['db_user']);
        $this->assertSame('tenant_000008', $creds['db_name']);
    }

    public function test_password_matches_the_canonical_hmac_algorithm(): void
    {
        $creds = $this->deriver()->deriveFromDatabaseName('tenant_000008');

        // tenant_000008 -> client id 8 -> message "tenant-pass:v1:8"
        $expected = substr(
            rtrim(strtr(base64_encode(hash_hmac('sha256', 'tenant-pass:v1:8', self::TEST_SECRET, true)), '+/', '-_'), '='),
            0,
            40
        );

        $this->assertSame(40, strlen($creds['db_pass']));
        $this->assertSame($expected, $creds['db_pass']);
    }

    public function test_invalid_database_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deriver()->deriveFromDatabaseName('not_a_tenant_db');
    }

    public function test_missing_secret_throws_on_construction(): void
    {
        config()->set('tenancy.derive_secret', '');
        $this->expectException(InvalidArgumentException::class);
        new TenantCredentialDeriver();
    }

    public function test_too_short_secret_throws_on_construction(): void
    {
        config()->set('tenancy.derive_secret', 'short');
        $this->expectException(InvalidArgumentException::class);
        new TenantCredentialDeriver();
    }
}
