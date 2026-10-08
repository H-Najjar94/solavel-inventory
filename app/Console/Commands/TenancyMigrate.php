<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Run landlord (central registry) migrations and/or the tenant schema against
 * the active tenant connection.
 *
 *   php artisan tenancy:migrate --landlord     → migrate landlord DB
 *   php artisan tenancy:migrate --tenant       → migrate the currently-configured tenant DB
 *
 * Tenant DB selection is done by whoever configures the `tenant` connection
 * (TenantManager in app code; TenantTestManager in tests). This command does not
 * create databases; it only runs migrations into already-selected schemas.
 */
class TenancyMigrate extends Command
{
    protected $signature = 'tenancy:migrate
        {--landlord : Migrate the landlord (central) database}
        {--tenant : Migrate the active tenant database}
        {--fresh : Drop all tables first (DANGEROUS; tenant only)}';

    protected $description = 'Run SolaStock landlord and/or tenant migrations';

    public function handle(): int
    {
        $did = false;

        if ($this->option('landlord')) {
            $this->info('Migrating landlord database...');
            $this->call('migrate', [
                '--database' => config('tenancy.central_connection', 'mysql'),
                '--path' => config('tenancy.central_migrations_path', 'database/migrations/landlord'),
                '--force' => true,
            ]);
            $did = true;
        }

        if ($this->option('tenant')) {
            $conn = config('tenancy.tenant_connection', 'tenant');
            $cmd = $this->option('fresh') ? 'migrate:fresh' : 'migrate';
            $this->info("Running {$cmd} on tenant database...");
            $this->call($cmd, [
                '--database' => $conn,
                '--path' => config('tenancy.tenant_migrations_path', 'database/migrations/tenant'),
                '--force' => true,
            ]);
            $did = true;
        }

        if (! $did) {
            $this->error('Specify --landlord and/or --tenant.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
