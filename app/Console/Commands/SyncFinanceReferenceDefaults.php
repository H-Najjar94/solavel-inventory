<?php

namespace App\Console\Commands;

use App\Services\Catalog\FinanceReferenceDefaultsService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

final class SyncFinanceReferenceDefaults extends Command
{
    protected $signature = 'inventory:sync-finance-reference-defaults {database} {organization} {--apply}';
    protected $description = 'Dry-run or add canonical Finance units and deduplicated default categories to one SolaStock organization';

    public function handle(TenantManager $tenants, FinanceReferenceDefaultsService $service): int
    {
        $database = (string) $this->argument('database');
        if (! preg_match('/^tenant_\d{6}$/', $database)) {
            $this->error('A canonical tenant database identity is required.');
            return self::INVALID;
        }
        $tenants->switchToDatabase($database);
        $result = $service->sync((int) $this->argument('organization'), (bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
