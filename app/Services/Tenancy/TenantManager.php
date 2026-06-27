<?php

namespace App\Services\Tenancy;

use App\Models\Landlord\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Manages tenant database connection switching and organization context.
 *
 * Mirrors solavel-finance's App\Services\Tenancy\TenantManager switching pattern
 * (Config::set → DB::purge → DB::reconnect on the 'tenant' connection) and adds
 * OrganizationContext integration so the BelongsToOrganization global scope and
 * StockLedgerService know which org is active.
 *
 * DATABASE MODEL: SolaStock shares the SAME per-client tenant databases as
 * Books/Projects/HR — `tenant_{clientId}` (e.g. tenant_000008) — and owns ONLY
 * its own stock_* and inventory tables there (migration marker migrated_at_inv).
 * There are NO separate per-app tenant databases. The test namespace is the
 * reserved tenant_99xxxx range (see Tests\TenancySafetyGuard).
 *
 * RUNTIME DB USER: today the runtime connection uses the shared DB_USERNAME.
 * When `tenancy.use_derived_db_user` is TRUE (default FALSE), the runtime
 * connection instead uses the deterministic per-tenant user (t_XXXXXX) derived
 * from the selected tenant database name; on a derivation/secret failure it
 * fails closed (503) and never falls back to the shared user.
 */
class TenantManager
{
    protected ?int $currentOrganizationId = null;
    protected bool $initialized = false;

    public function __construct(private OrganizationContext $context) {}

    /** Build the production tenant database name for an organization id. */
    public function resolveDatabaseName(int $organizationId): string
    {
        // Default mirrors config/tenancy.php (env TENANT_DB_PREFIX, default
        // 'tenant_') — SolaStock shares the per-client tenant_{id} DBs.
        $prefix = (string) config('tenancy.database_prefix', 'tenant_');
        $pad = (int) config('tenancy.database_pad', 6);

        return $prefix.str_pad((string) $organizationId, $pad, '0', STR_PAD_LEFT);
    }

    /** Point the tenant connection at a database (Finance-style switch). */
    public function switchToDatabase(string $database): void
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');
        Config::set("database.connections.{$connection}.database", $database);

        // Option A (default OFF). When enabled, use the per-tenant derived DB
        // user for this database instead of the shared DB_USERNAME. When OFF,
        // behaviour is unchanged (database switch only). Runs BEFORE reconnect so
        // a fail-closed never reconnects on the shared user.
        $derivedCreds = null;
        if (config('tenancy.use_derived_db_user', false)) {
            $derivedCreds = $this->resolveDerivedCredentials($database); // returns array or aborts(503)
            Config::set("database.connections.{$connection}.username", $derivedCreds['db_user']);
            Config::set("database.connections.{$connection}.password", $derivedCreds['db_pass']);
        }

        DB::purge($connection);
        DB::reconnect($connection);

        // Optional, transitional previous-secret fallback (#36, mirrors the siblings).
        // Off by default and ONLY ever considered when the per-tenant derived DB user
        // is actually in use — when use_derived_db_user is OFF (default) the runtime
        // connection uses the shared DB user and there is no derived password to fall
        // back to, so $derivedCreds stays null and this is a strict no-op.
        if ($derivedCreds !== null) {
            $this->maybeApplyPreviousSecretFallback($connection, $database, $derivedCreds['db_user'], $derivedCreds['db_pass']);
        }
    }

    /**
     * Transitional connection-layer fallback to the PREVIOUS-derived password.
     *
     * Strictly opt-in and disabled by default. Only reached when the per-tenant
     * derived DB user is in use (see switchToDatabase). Returns immediately (leaving
     * the primary-derived connection exactly as configured) unless ALL of:
     *  - tenancy.derive_previous_secret_fallback is true,
     *  - a client id can be parsed from the tenant database name, and
     *  - a valid previous secret is configured (deriver->hasPreviousSecret()).
     *
     * When active it probes the freshly configured runtime connection. On success it
     * does nothing. ONLY when the probe fails with an authentication/access-denied
     * error (SQLSTATE 28000 / MySQL 1045) does it retry the runtime connection ONCE
     * with the previous-derived password. If that probe succeeds the working
     * (previous-secret) connection is kept and SAFE metadata is logged. If it fails,
     * or the original error was not an auth error, the PRIMARY-derived password is
     * restored so the existing fail-closed behavior is preserved. Never logs secrets
     * or passwords; never touches the username/db/host/port.
     */
    protected function maybeApplyPreviousSecretFallback(
        string $connection,
        string $database,
        string $dbUser,
        string $primaryPass
    ): void {
        if (! (bool) config('tenancy.derive_previous_secret_fallback', false)) {
            return; // opt-in switch is off (default) — identical to today
        }

        // Parse the numeric client id from the tenant database name (tenant_000008 ->
        // 8), matching TenantCredentialDeriver::deriveFromDatabaseName().
        if (! preg_match('/^tenant_0*(\d+)$/', $database, $m)) {
            return; // not a recognised tenant DB name — nothing to derive against
        }
        $clientId = (int) $m[1];

        $deriver = app(TenantCredentialDeriver::class);
        if (! $deriver->hasPreviousSecret()) {
            return; // no usable previous secret — nothing to fall back to
        }

        // Probe the primary runtime connection. If it works, the normal path stands.
        try {
            $this->probeTenantConnection($connection);

            return;
        } catch (\Throwable $primaryError) {
            // Only an authentication/access-denied failure is a rotation signal. Any
            // other error (host down, unknown database, etc.) must NOT trigger fallback
            // and is left to surface through the existing handling.
            if (! $this->isAuthError($primaryError)) {
                return;
            }
        }

        $previousPass = $deriver->derivePreviousDbPass($clientId);
        if ($previousPass === null || $previousPass === $primaryPass) {
            return; // nothing distinct to try
        }

        // Retry ONCE with the previous-derived password (password only).
        $this->applyTenantPassword($connection, $previousPass);

        try {
            $this->probeTenantConnection($connection);
        } catch (\Throwable $previousError) {
            // Previous secret also rejected — restore primary so fail-closed stands.
            $this->applyTenantPassword($connection, $primaryPass);

            return;
        }

        // Previous-derived password works. Keep it and log SAFE metadata only.
        Log::warning('Tenant connection used previous-secret fallback', [
            'client_id'      => $clientId,
            'db_name'        => $database,
            'db_user'        => $dbUser,
            'fallback_used'  => true,
            'secret_version' => $deriver->secretVersion(),
        ]);
    }

    /**
     * Lightweight liveness probe of the currently configured runtime tenant
     * connection. Throws (PDOException/QueryException) on failure — including auth
     * failures — exactly as a normal query would. Isolated so the fallback decision
     * logic can be exercised in tests without a live server.
     */
    protected function probeTenantConnection(string $connection): void
    {
        DB::connection($connection)->select('SELECT 1');
    }

    /**
     * Re-point the runtime tenant connection's PASSWORD only and reconnect. Used by
     * the previous-secret fallback to swap between primary and previous derivations
     * without touching username/db/host/port.
     */
    private function applyTenantPassword(string $connection, string $password): void
    {
        Config::set("database.connections.{$connection}.password", $password);
        DB::purge($connection);
        DB::reconnect($connection);
    }

    /**
     * Whether a connection failure is an authentication/access-denied error
     * (SQLSTATE 28000 / MySQL native 1045), as opposed to an unrelated DB error.
     */
    private function isAuthError(\Throwable $e): bool
    {
        if ((string) $e->getCode() === '28000') {
            return true;
        }

        $previous = $e->getPrevious();
        if ($previous !== null && (string) $previous->getCode() === '28000') {
            return true;
        }

        $message = $e->getMessage();
        if ($previous !== null) {
            $message .= ' '.$previous->getMessage();
        }

        return str_contains($message, '1045') || str_contains($message, 'SQLSTATE[28000]');
    }

    /**
     * Resolve the deterministic per-tenant DB credentials for a tenant database
     * name (e.g. tenant_000008 -> t_000008). Fails CLOSED (503 + security alert)
     * if the derive secret is missing or credentials cannot be derived — it never
     * returns/falls back to the shared DB user.
     *
     * @return array{db_user: string, db_pass: string}
     */
    protected function resolveDerivedCredentials(string $database): array
    {
        try {
            $creds = app(TenantCredentialDeriver::class)->deriveFromDatabaseName($database);
        } catch (\Throwable $e) {
            $this->failClosedOnTenantCredentials($database, $e);
        }

        if (empty($creds['db_user']) || empty($creds['db_pass'])) {
            $this->failClosedOnTenantCredentials($database, null);
        }

        return $creds;
    }

    /**
     * Refuse to serve a tenant request when per-tenant credentials cannot be
     * resolved. Logs a security alert (NO secret/password/DSN/exception message —
     * only class/code) and returns 503. Never falls back to the shared DB user.
     */
    protected function failClosedOnTenantCredentials(string $database, ?\Throwable $e): never
    {
        Log::critical('[Tenancy][SECURITY] Tenant credential resolution failed — refusing to fall back to the shared DB user.', [
            'event'           => 'tenant_credential_resolution_failure',
            'database'        => $database,
            'exception_class' => $e ? get_class($e) : null,
            'exception_code'  => $e ? $e->getCode() : null,
        ]);

        abort(503, 'This workspace is temporarily unavailable. Please try again shortly.');
    }

    /**
     * Activate a tenant by organization id: switch the DB AND set org context.
     * Returns the resolved database name.
     */
    public function useTenant(int $organizationId, ?string $databaseOverride = null): string
    {
        if ($organizationId <= 0) {
            throw new RuntimeException('TenantManager::useTenant() requires a positive organization id.');
        }

        $database = $databaseOverride ?? $this->resolveDatabaseName($organizationId);
        $this->switchToDatabase($database);
        $this->context->set($organizationId);
        $this->currentOrganizationId = $organizationId;
        $this->initialized = true;

        return $database;
    }

    public function useOrganization(Organization $organization): string
    {
        $database = $organization->database_name ?: $this->resolveDatabaseName((int) $organization->id);

        return $this->useTenant((int) $organization->id, $database);
    }

    public function currentOrganizationId(): ?int
    {
        return $this->currentOrganizationId;
    }

    public function currentDatabase(): ?string
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');

        return Config::get("database.connections.{$connection}.database");
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /** End the current tenancy (clears context and tenant DB selection). */
    public function end(): void
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');
        $this->context->forget();
        $this->currentOrganizationId = null;
        $this->initialized = false;
        Config::set("database.connections.{$connection}.database", null);
        DB::purge($connection);
    }

    /**
     * Run a callback within a given tenant, restoring the previous tenant/context
     * afterwards (even on exception).
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function runForTenant(int $organizationId, callable $callback, ?string $databaseOverride = null): mixed
    {
        $previousOrg = $this->currentOrganizationId;
        $previousDb = $this->currentDatabase();

        $this->useTenant($organizationId, $databaseOverride);

        try {
            return $callback();
        } finally {
            if ($previousOrg !== null && $previousDb) {
                $this->useTenant($previousOrg, $previousDb);
            } else {
                $this->end();
            }
        }
    }
}
