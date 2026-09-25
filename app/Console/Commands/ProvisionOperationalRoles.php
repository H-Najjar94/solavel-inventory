<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProvisionOperationalRoles extends Command
{
    protected $signature = 'inventory:provision-operational-roles {client : Existing Central client ID} {--dry-run : Inspect only}';

    protected $description = 'Add missing operational preset definitions through the targeted tenant migration; preserve customized definitions and assignments.';

    public function handle(TenantManager $tenants): int
    {
        $id = filter_var($this->argument('client'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999]]);
        if (! $id) {
            $this->error('Invalid client ID.');

            return 1;
        }
        $database = $tenants->resolveDatabaseName($id);
        if (! preg_match('/^tenant_[0-9]{6}$/', $database)) {
            $this->error('Unexpected tenant database name.');

            return 1;
        }
        $connection = (string) config('tenancy.provision_connection', 'tenant_admin');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('stock_ledger')) {
            $this->error('Existing Inventory schema is required.');

            return 1;
        }
        $table = 'inventory_operational_role_sets';
        $existing = $schema->hasTable($table) ? DB::connection($connection)->table($table)->pluck('permissions', 'role_key')->all() : [];
        foreach (config('inventory_operational_roles') as $key => $definition) {
            $this->line($key.': '.(isset($existing[$key]) ? 'preserve existing definition' : 'new definition ('.count($definition['permissions']).' permissions)'));
        }
        $manager = config('inventory_operational_roles.scoped_inventory_manager.permissions', []);
        $previousManager = array_values(array_diff($manager, ['inventory.manage_purchase_orders']));
        $storedManager = json_decode($existing['scoped_inventory_manager'] ?? 'null', true);
        $this->line('scoped_inventory_manager purchase-order drafting: '.($storedManager === $previousManager
            ? 'add to exact seeded default'
            : 'preserve existing definition'));
        if ($this->option('dry-run')) {
            return 0;
        }
        $previous = config('tenancy.tenant_connection');
        config(['tenancy.tenant_connection' => $connection]);
        try {
            $status = Artisan::call('migrate', ['--database' => $connection, '--path' => 'database/migrations/tenant/2026_09_22_230000_create_inventory_operational_role_sets.php', '--force' => true]);
            $this->line(Artisan::output());

            if ($status !== 0) {
                return $status;
            }
            $status = Artisan::call('migrate', ['--database' => $connection, '--path' => 'database/migrations/tenant/2026_09_24_090000_narrow_default_inventory_manager_export.php', '--force' => true]);
            $this->line(Artisan::output());

            if ($status !== 0) {
                return $status;
            }
            $status = Artisan::call('migrate', ['--database' => $connection, '--path' => 'database/migrations/tenant/2026_09_25_090000_add_scoped_purchase_order_drafting.php', '--force' => true]);
            $this->line(Artisan::output());

            return $status;
        } finally {
            config(['tenancy.tenant_connection' => $previous]);
            DB::purge($connection);
        }
    }
}
