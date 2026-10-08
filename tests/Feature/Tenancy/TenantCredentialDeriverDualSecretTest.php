<?php

namespace Tests\Feature\Tenancy;

use App\Services\Tenancy\TenantCredentialDeriver;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PIC #35 — TenantCredentialDeriver dual-secret / key-version support
 * (mirrors the accepted SolaBooks #30 / SolaProjects #33 / SolaHR #34 implementation).
 *
 * Proves the optional previous-secret + version support is backward compatible:
 * single-secret behavior, the derived username/db-name, and the primary-derived
 * password are unchanged; the primary still fails closed; and the previous
 * secret is optional, validated, and never breaks the primary.
 *
 * Uses test-only literal secrets via config() — never the real TENANT_DERIVE_SECRET.
 */
class TenantCredentialDeriverDualSecretTest extends TestCase
{
    private const PRIMARY = 'primary-test-secret-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // >=32
    private const PREVIOUS = 'previous-test-secret-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'; // >=32, != primary

    private function deriver(?string $primary, ?string $previous = null, ?string $version = null): TenantCredentialDeriver
    {
        config([
            'tenancy.derive_secret' => $primary,
            'tenancy.derive_previous_secret' => $previous,
            'tenancy.derive_secret_version' => $version,
        ]);

        return new TenantCredentialDeriver();
    }

    /** The exact documented algorithm, computed independently to lock the format. */
    private function expectedPass(int $clientId, string $secret): string
    {
        $hmac = hash_hmac('sha256', "tenant-pass:v1:{$clientId}", $secret, true);

        return substr(rtrim(strtr(base64_encode($hmac), '+/', '-_'), '='), 0, 40);
    }

    public function test_single_secret_behavior_is_unchanged(): void
    {
        $d = $this->deriver(self::PRIMARY);

        $this->assertSame('tenant_000123', $d->deriveDbName(123));
        $this->assertSame('t_000123', $d->deriveDbUser(123));
        $this->assertSame($this->expectedPass(123, self::PRIMARY), $d->deriveDbPass(123));
        $this->assertFalse($d->hasPreviousSecret());
        $this->assertNull($d->derivePreviousDbPass(123));
        $this->assertSame('v1', $d->secretVersion());
    }

    public function test_derived_password_format_is_locked(): void
    {
        $pass = $this->deriver(self::PRIMARY)->deriveDbPass(7);

        $this->assertSame(40, strlen($pass));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{40}$/', $pass); // base64url, no +/=
        $this->assertSame($this->expectedPass(7, self::PRIMARY), $pass);
        $this->assertSame($pass, $this->deriver(self::PRIMARY)->deriveDbPass(7)); // deterministic
    }

    public function test_missing_primary_secret_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deriver(null);
    }

    public function test_short_primary_secret_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deriver('too-short');
    }

    public function test_previous_secret_is_optional(): void
    {
        $d = $this->deriver(self::PRIMARY, null);
        $this->assertFalse($d->hasPreviousSecret());
        $this->assertNull($d->derivePreviousDbPass(1));
    }

    public function test_invalid_short_previous_secret_is_ignored_and_primary_still_works(): void
    {
        $d = $this->deriver(self::PRIMARY, 'short-prev');

        $this->assertFalse($d->hasPreviousSecret(), 'A too-short previous secret must be ignored.');
        $this->assertNull($d->derivePreviousDbPass(1));
        $this->assertSame($this->expectedPass(99, self::PRIMARY), $d->deriveDbPass(99));
    }

    public function test_previous_secret_equal_to_primary_is_ignored(): void
    {
        $d = $this->deriver(self::PRIMARY, self::PRIMARY);

        $this->assertFalse($d->hasPreviousSecret(), 'previous == primary must be treated as no-op.');
        $this->assertNull($d->derivePreviousDbPass(1));
    }

    public function test_valid_previous_secret_enables_fallback_without_changing_primary(): void
    {
        $withPrev = $this->deriver(self::PRIMARY, self::PREVIOUS);
        $primaryOnly = $this->deriver(self::PRIMARY);

        $this->assertTrue($withPrev->hasPreviousSecret());
        $this->assertSame($primaryOnly->deriveDbPass(123), $withPrev->deriveDbPass(123));

        $prevPass = $withPrev->derivePreviousDbPass(123);
        $this->assertSame($this->expectedPass(123, self::PREVIOUS), $prevPass);
        $this->assertNotSame($withPrev->deriveDbPass(123), $prevPass);
    }

    public function test_key_version_is_configurable_and_non_sensitive(): void
    {
        $this->assertSame('v2', $this->deriver(self::PRIMARY, null, 'v2')->secretVersion());
        $this->assertSame('v1', $this->deriver(self::PRIMARY, null, null)->secretVersion());
    }

    public function test_safe_getters_never_expose_secret_values(): void
    {
        $d = $this->deriver(self::PRIMARY, self::PREVIOUS, 'v2');

        $this->assertNotSame(self::PRIMARY, $d->secretVersion());
        $this->assertNotSame(self::PREVIOUS, $d->secretVersion());
        $this->assertNotSame(self::PRIMARY, $d->deriveDbPass(5));
        $this->assertNotSame(self::PREVIOUS, $d->derivePreviousDbPass(5));
    }
}
