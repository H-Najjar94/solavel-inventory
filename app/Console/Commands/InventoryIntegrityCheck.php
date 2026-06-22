<?php

namespace App\Console\Commands;

use App\Services\Stock\IntegrityChecker;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Read-only inventory integrity audit. Verifies ledger↔balance↔layer consistency
 * for a tenant. NO repairs (Phase 1 must be correct by construction).
 *
 *   php artisan inventory:integrity --org=990010
 */
class InventoryIntegrityCheck extends Command
{
    protected $signature = 'inventory:integrity
        {--org= : Organization id (its tenant DB will be activated)}
        {--database= : Explicit tenant database override (testing)}';

    protected $description = 'Read-only inventory integrity audit (ledger vs balances vs cost layers)';

    public function handle(TenantManager $tenants, IntegrityChecker $checker): int
    {
        $org = (int) $this->option('org');
        if ($org <= 0) {
            $this->error('Provide --org=<organization id>.');

            return self::FAILURE;
        }

        $tenants->useTenant($org, $this->option('database') ?: null);
        $connection = (string) config('tenancy.tenant_connection', 'tenant');

        $result = $checker->check($connection, $org);

        $this->info('Checked: '.json_encode($result['checked']));

        if ($result['ok']) {
            $this->info("✓ Integrity OK for organization {$org}.");

            return self::SUCCESS;
        }

        $this->error('✗ Integrity problems found:');
        foreach ($result['problems'] as $p) {
            $this->line('  - '.$p);
        }

        return self::FAILURE;
    }
}
