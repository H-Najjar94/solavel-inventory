<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Provisions SolaStock's tables inside the SHARED per-client tenant database
 * (tenant_{clientId}) — the Solavel co-tenant model. It:
 *   - creates the shared DB if missing (elevated mysql_admin connection),
 *   - runs ONLY SolaStock's tenant migrations (database/migrations/tenant)
 *     against it, recorded under SolaStock's own migration marker.
 *
 * It NEVER drops/imports/writes Finance or Projects tables; it only adds
 * SolaStock's own tables. If the process lacks privileges to create the DB or
 * migrate, it throws so the caller can surface the exact admin command.
 */
class SecureTenantProvisioner
{
    /** Databases that must never be provisioned/altered by SolaStock. */
    private function isForbidden(string $db): bool
    {
        return $db === '' || in_array($db, (array) config('inventory.forbidden_demo_databases', []), true);
    }

    /** True if the shared tenant DB already has SolaStock tables. */
    public function isInventoryReady(string $db): bool
    {
        try {
            $rows = DB::connection('mysql')->select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, 'stock_ledger']
            );

            return count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Create (if needed) + migrate SolaStock tables into the shared tenant DB.
     *
     * @return array{database:string, created:bool, migrated:bool, connection:string}
     */
    public function provisionInventory(int $clientId, string $db): array
    {
        if ($clientId <= 0) {
            throw new RuntimeException('provisionInventory requires a positive client id.');
        }
        if ($this->isForbidden($db)) {
            throw new RuntimeException("Refusing to provision forbidden database '{$db}'.");
        }

        // 1. Create the shared DB if missing (elevated connection). This is
        //    additive — IF NOT EXISTS never drops or touches existing app tables.
        $created = $this->ensureDatabase($db);

        // 2. Migrate ONLY SolaStock's own path into the DB through the ELEVATED
        //    provisioning connection — NOT the runtime `tenant` connection. This
        //    is the seam that lets the runtime user later drop to DML-only:
        //    migrations (DDL) run as the provisioner here, while requests keep
        //    using the low-priv runtime connection. Today both resolve to the
        //    same admin credentials, so behaviour is unchanged.
        //
        //    Tenant migrations pin their connection via
        //    getConnection() => config('tenancy.tenant_connection'), so simply
        //    passing --database is not enough — the migration files would still
        //    use the runtime connection. We therefore point the elevated
        //    connection at the target DB and TRANSIENTLY retarget
        //    'tenancy.tenant_connection' to it for the duration of the migrate,
        //    restoring it in a finally block. The runtime `tenant` connection is
        //    never reconfigured, so provisioning cannot disturb the active
        //    request's connection. Other apps' tables in the DB are untouched.
        $conn = (string) config('tenancy.provision_connection', 'tenant_admin');
        Config::set("database.connections.{$conn}.database", $db);
        DB::purge($conn);
        DB::reconnect($conn);

        $originalTenantConnection = config('tenancy.tenant_connection', 'tenant');
        Config::set('tenancy.tenant_connection', $conn);
        try {
            $exit = Artisan::call('migrate', [
                '--database' => $conn,
                '--path' => config('tenancy.migrations_path', 'database/migrations/tenant'),
                '--force' => true,
            ]);
        } finally {
            Config::set('tenancy.tenant_connection', $originalTenantConnection);
        }
        if ($exit !== 0) {
            throw new RuntimeException('SolaStock tenant migration failed (exit '.$exit.').');
        }

        return ['database' => $db, 'created' => $created, 'migrated' => true, 'connection' => $conn];
    }

    /** CREATE DATABASE IF NOT EXISTS via the elevated admin connection. */
    private function ensureDatabase(string $db): bool
    {
        // Already there?
        try {
            $exists = collect(DB::connection('mysql')->select(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]
            ))->isNotEmpty();
            if ($exists) {
                return false;
            }
        } catch (\Throwable $e) {
            // fall through to attempt creation; if that also fails we throw
        }

        $admin = (string) config('tenancy.admin_connection', 'mysql_admin');
        $charset = (string) config('tenancy.db_charset', 'utf8mb4');
        $collation = (string) config('tenancy.db_collation', 'utf8mb4_unicode_ci');
        DB::connection($admin)->statement(
            "CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET {$charset} COLLATE {$collation}"
        );

        return true;
    }
}
