<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Hard safety rails that refuse to let test/CI code touch anything that looks
 * like a real database. Every test-time database creation, migration, refresh,
 * or tenant switch must pass through assertSafeTestEnvironment() / assertSafeTestDatabase().
 *
 * These checks are intentionally paranoid: they fail closed.
 */
class TenancySafetyGuard
{
    /**
     * FIXED reserved test databases for SolaStock (Finance-style model — these
     * are pre-provisioned and NEVER created/dropped by the test suite):
     *   tenant_990002 = SolaStock tenant A
     *   tenant_990003 = SolaStock tenant B
     *   tenant_990004 = SolaStock central/landlord test database
     * tenant_990001 belongs to Finance and is explicitly forbidden here.
     */
    public const ALLOWED_TEST_DATABASES = [
        'tenant_990010', // SolaStock tenant A
        'tenant_990011', // SolaStock tenant B
        'tenant_990012', // SolaStock central / landlord
    ];

    /**
     * Reserved DBs owned by OTHER Solavel apps — SolaStock must never select them.
     *   tenant_990001 = Finance, tenant_990002 = Projects.
     */
    public const FOREIGN_RESERVED_DATABASES = [
        'tenant_990001',
        'tenant_990002',
    ];

    /**
     * Database name fragments that indicate a real/production tenant or app DB.
     */
    public const FORBIDDEN_FRAGMENTS = [
        'solavel_finance',
        'solavel_projects',
        'solavel_hr',
        'solavel_system',
        'solavel_central',
        'solavel_ebill',
        'solavel_inventory',   // the production inventory app DB — never in tests
        'production',
        'prod_',
    ];

    /**
     * Assert the process is running in the testing environment. Call before any
     * destructive test DB action.
     */
    public static function assertTestingEnvironment(): void
    {
        $env = app()->environment();

        if ($env !== 'testing') {
            throw new RuntimeException(
                "TenancySafetyGuard: refusing to run test database operations because APP_ENV is '{$env}', not 'testing'."
            );
        }
    }

    /**
     * Assert a database name is a safe, dedicated test database.
     * Rejects: empty names, names not prefixed with solastock_test_, and any
     * name containing a forbidden production/real-tenant fragment.
     */
    public static function assertSafeTestDatabase(?string $database): void
    {
        if ($database === null || trim($database) === '') {
            throw new RuntimeException('TenancySafetyGuard: empty database name is not a safe test database.');
        }

        $name = strtolower(trim($database));

        // Explicitly forbid other apps' reserved test DBs (Finance/Projects).
        if (in_array($name, self::FOREIGN_RESERVED_DATABASES, true)) {
            throw new RuntimeException(
                "TenancySafetyGuard: '{$database}' is reserved by another Solavel app "
                .'(tenant_990001=Finance, tenant_990002=Projects). SolaStock must never use it.'
            );
        }

        // Forbidden fragments (real apps / production) — rejected unconditionally.
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                throw new RuntimeException(
                    "TenancySafetyGuard: database '{$database}' contains forbidden fragment '{$fragment}' "
                    .'(looks like a real/production database). Refusing to proceed.'
                );
            }
        }

        // Strict allow-list: only the three fixed SolaStock reserved DBs.
        if (! in_array($name, self::ALLOWED_TEST_DATABASES, true)) {
            throw new RuntimeException(
                "TenancySafetyGuard: database '{$database}' is not an allowed SolaStock test database. "
                .'Allowed: '.implode(', ', self::ALLOWED_TEST_DATABASES).'. Refusing to proceed.'
            );
        }
    }

    /**
     * Single entry point before any test DB action. Asserts:
     *  - environment is testing
     *  - the active tenant DB is an allowed reserved test DB
     *  - the central DB (if set) is an allowed reserved test DB
     *  - central and tenant DO NOT resolve to the same database
     */
    public static function assertSafeTestEnvironment(): void
    {
        self::assertTestingEnvironment();

        $tenant = config('database.connections.'.config('tenancy.tenant_connection').'.database');
        self::assertSafeTestDatabase($tenant);

        $central = config('database.connections.'.config('tenancy.central_connection').'.database');
        if ($central !== null && trim((string) $central) !== '') {
            self::assertSafeTestDatabase($central);

            self::assertCentralAndTenantDiffer($central, $tenant);
        }
    }

    /** Central and tenant must never point at the same physical database. */
    public static function assertCentralAndTenantDiffer(?string $central, ?string $tenant): void
    {
        if ($central !== null && $tenant !== null
            && strtolower(trim($central)) === strtolower(trim($tenant))) {
            throw new RuntimeException(
                "TenancySafetyGuard: central and tenant connections resolve to the same database "
                ."('{$central}'). They must be distinct reserved databases."
            );
        }
    }
}
