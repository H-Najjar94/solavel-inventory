<?php

namespace App\Services\Tenancy;

use App\Models\Landlord\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manages tenant database connection switching and organization context.
 *
 * Mirrors solavel-finance's App\Services\Tenancy\TenantManager switching pattern
 * (Config::set → DB::purge → DB::reconnect on the 'tenant' connection) and adds
 * OrganizationContext integration so the BelongsToOrganization global scope and
 * StockLedgerService know which org is active.
 *
 * Inventory uses its OWN databases (inventory_tenant_* in prod;
 * solastock_test_tenant_* in tests) and OWN credentials.
 */
class TenantManager
{
    protected ?int $currentOrganizationId = null;
    protected bool $initialized = false;

    public function __construct(private OrganizationContext $context) {}

    /** Build the production tenant database name for an organization id. */
    public function resolveDatabaseName(int $organizationId): string
    {
        $prefix = (string) config('tenancy.database_prefix', 'inventory_tenant_');
        $pad = (int) config('tenancy.database_pad', 6);

        return $prefix.str_pad((string) $organizationId, $pad, '0', STR_PAD_LEFT);
    }

    /** Point the tenant connection at a database (Finance-style switch). */
    public function switchToDatabase(string $database): void
    {
        $connection = (string) config('tenancy.tenant_connection', 'tenant');
        Config::set("database.connections.{$connection}.database", $database);
        DB::purge($connection);
        DB::reconnect($connection);
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
