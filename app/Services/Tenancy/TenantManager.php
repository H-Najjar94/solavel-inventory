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
        if (config('tenancy.use_derived_db_user', false)) {
            $creds = $this->resolveDerivedCredentials($database); // returns array or aborts(503)
            Config::set("database.connections.{$connection}.username", $creds['db_user']);
            Config::set("database.connections.{$connection}.password", $creds['db_pass']);
        }

        DB::purge($connection);
        DB::reconnect($connection);
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
