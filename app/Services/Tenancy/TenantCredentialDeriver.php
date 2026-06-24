<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;

/**
 * Deterministic Tenant Credential Deriver (ported verbatim from solavel-finance
 * so SolaStock derives the SAME per-tenant credentials the other apps already
 * provisioned on the shared tenant_XXXXXX databases).
 *
 * Generates consistent, reproducible database credentials from a tenant id.
 * Uses HMAC-SHA256 with a master secret for password derivation.
 *
 * SECURITY:
 * - Passwords are never stored — always derived on-demand.
 * - The only secret is TENANT_DERIVE_SECRET (config/env); never hardcoded,
 *   logged, printed, or committed.
 * - Same tenant id always produces the same credentials.
 */
class TenantCredentialDeriver
{
    protected string $secret;
    protected string $userHost;

    public function __construct()
    {
        $this->secret = (string) config('tenancy.derive_secret');
        $this->userHost = (string) config('tenancy.db_user_host', '%');

        if (empty($this->secret)) {
            throw new InvalidArgumentException(
                'TENANT_DERIVE_SECRET must be set (minimum 64 characters recommended)'
            );
        }

        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException(
                'TENANT_DERIVE_SECRET is too short. Use at least 64 random characters.'
            );
        }
    }

    /**
     * Validate a tenant (client) id.
     *
     * @throws InvalidArgumentException
     */
    public function validateClientId(int $clientId): void
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException("Invalid tenant id: {$clientId}. Must be a positive integer.");
        }

        if ($clientId > 999999) {
            throw new InvalidArgumentException("Invalid tenant id: {$clientId}. Maximum supported value is 999999.");
        }
    }

    /** Derive database name from tenant id: 123 => tenant_000123. */
    public function deriveDbName(int $clientId): string
    {
        $this->validateClientId($clientId);

        return 'tenant_'.str_pad((string) $clientId, 6, '0', STR_PAD_LEFT);
    }

    /** Derive database username from tenant id: 123 => t_000123 (8 chars; < MySQL 32). */
    public function deriveDbUser(int $clientId): string
    {
        $this->validateClientId($clientId);

        return 't_'.str_pad((string) $clientId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Derive database password from tenant id via HMAC-SHA256.
     *
     * message = "tenant-pass:v1:{clientId}" -> HMAC-SHA256(secret) -> base64url ->
     * first 40 chars. Deterministic, unguessable without the secret. MUST match
     * the other apps' algorithm exactly.
     */
    public function deriveDbPass(int $clientId): string
    {
        $this->validateClientId($clientId);

        $message = "tenant-pass:v1:{$clientId}";
        $hmac = hash_hmac('sha256', $message, $this->secret, true);
        $base64 = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');

        return substr($base64, 0, 40);
    }

    /**
     * Full credential set for a tenant id.
     *
     * @return array{db_name: string, db_user: string, db_pass: string, db_host: string, db_port: int, user_host: string}
     */
    public function deriveCredentials(int $clientId): array
    {
        return [
            'db_name' => $this->deriveDbName($clientId),
            'db_user' => $this->deriveDbUser($clientId),
            'db_pass' => $this->deriveDbPass($clientId),
            'db_host' => (string) config('tenancy.db_host', config('database.connections.mysql.host', '127.0.0.1')),
            'db_port' => (int) config('tenancy.db_port', config('database.connections.mysql.port', 3306)),
            'user_host' => $this->userHost,
        ];
    }

    /**
     * Derive credentials from the actual SELECTED tenant DATABASE NAME, e.g.
     * "tenant_000008" => user "t_000008". Deriving from the resolved database name
     * (not a raw organization id) avoids the org-id vs client/tenant-id confusion.
     *
     * @throws InvalidArgumentException when the name is not a valid tenant DB name.
     */
    public function deriveFromDatabaseName(string $database): array
    {
        if (! preg_match('/^tenant_0*(\d+)$/', $database, $m)) {
            throw new InvalidArgumentException('Unrecognised tenant database name; cannot derive per-tenant credentials.');
        }

        return $this->deriveCredentials((int) $m[1]);
    }

    public function getUserHost(): string
    {
        return $this->userHost;
    }
}
