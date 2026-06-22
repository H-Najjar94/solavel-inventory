<?php

namespace Tests\Support;

use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use App\Tenancy\TenancySafetyGuard;
use Illuminate\Support\Facades\DB;

/**
 * Test-only helper for the FIXED reserved-database model (mirrors Finance).
 *
 * It NEVER creates or drops databases. It switches the `tenant` connection
 * between the pre-provisioned reserved test databases and provides
 * transaction-based isolation (begin on entry, rollback on cleanup) so tests
 * leave no residue — exactly Finance's TenantAware approach, extended to two
 * tenant DBs (A and B) for cross-tenant isolation tests.
 *
 * Reserved DBs (configurable via env, defaulting to the agreed values):
 *   tenant A = tenant_990002   tenant B = tenant_990003   central = tenant_990004
 */
class TenantTestManager
{
    /** Organization ids used for the two reserved tenant DBs. */
    public const ORG_A = 990010;
    public const ORG_B = 990011;

    /**
     * The database we currently hold an open test transaction on, or null.
     *
     * We track the DATABASE, not the connection name: switching tenants does
     * Config::set + DB::purge + DB::reconnect on the single `tenant` connection,
     * which CLOSES the old PDO (MySQL auto-rolls-back its transaction) and opens
     * a fresh one against the new database. Keying by connection name caused the
     * second tenant DB in a multi-DB test to never get a transaction begun, so
     * its writes autocommitted and leaked into the reserved DB — corrupting
     * later runs (duplicate-key errors). Keying by database fixes that.
     */
    private ?string $activeTxnDatabase = null;

    public function __construct(
        private TenantManager $tenants,
        private OrganizationContext $context,
    ) {}

    public function tenantADatabase(): string
    {
        return (string) env('SOLASTOCK_TEST_TENANT_A', 'tenant_990010');
    }

    public function tenantBDatabase(): string
    {
        return (string) env('SOLASTOCK_TEST_TENANT_B', 'tenant_990011');
    }

    public function centralDatabase(): string
    {
        return (string) env('SOLASTOCK_TEST_CENTRAL', 'tenant_990012');
    }

    /** Map an org id to its fixed reserved database name. */
    public function databaseForOrg(int $organizationId): string
    {
        return match ($organizationId) {
            self::ORG_A => $this->tenantADatabase(),
            self::ORG_B => $this->tenantBDatabase(),
            default => throw new \RuntimeException(
                "TenantTestManager: org {$organizationId} has no reserved test database. Use ORG_A or ORG_B."
            ),
        };
    }

    /**
     * Activate a reserved tenant by org id: switch the tenant connection to its
     * fixed DB (guard-checked) and set org context. Begins a test transaction on
     * that connection for rollback-based cleanup (idempotent per connection).
     */
    public function useTenant(int $organizationId): string
    {
        TenancySafetyGuard::assertTestingEnvironment();
        $database = $this->databaseForOrg($organizationId);
        TenancySafetyGuard::assertSafeTestDatabase($database);

        // central must differ from this tenant DB.
        TenancySafetyGuard::assertCentralAndTenantDiffer($this->centralDatabase(), $database);

        // This purges + reconnects the `tenant` connection onto $database; any
        // transaction we had open on the PREVIOUS tenant DB is auto-rolled-back
        // by the connection close (so no residue is left behind there).
        $this->tenants->useTenant($organizationId, $database);

        $conn = (string) config('tenancy.tenant_connection', 'tenant');
        $this->beginForDatabase($conn, $database);

        return $database;
    }

    /**
     * Ensure an open test transaction exists on the now-active tenant database.
     * The reconnect in useTenant() discards any prior transaction, so whenever
     * the active database changes we must begin a fresh one — otherwise writes
     * to a second/subsequent tenant DB autocommit and leak.
     */
    private function beginForDatabase(string $connection, string $database): void
    {
        if ($this->activeTxnDatabase === $database) {
            return;
        }
        DB::connection($connection)->beginTransaction();
        $this->activeTxnDatabase = $database;
    }

    /**
     * Roll back every test transaction we opened (cleanup between tests). Safe to
     * call repeatedly and safe on failure paths. NEVER drops databases.
     */
    public function cleanup(): void
    {
        if ($this->activeTxnDatabase !== null) {
            $connection = (string) config('tenancy.tenant_connection', 'tenant');
            try {
                $c = DB::connection($connection);
                while ($c->transactionLevel() > 0) {
                    $c->rollBack();
                }
            } catch (\Throwable $e) {
                // best-effort cleanup; never mask the original test failure
            }
            $this->activeTxnDatabase = null;
        }
        $this->context->forget();
    }
}
