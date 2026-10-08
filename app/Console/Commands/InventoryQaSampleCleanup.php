<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryQaSampleCleanup extends Command
{
    protected $signature = 'inventory:qa-samples
        {--org= : Organization id used for org-scoped reads}
        {--database= : Explicit tenant database override}
        {--prefix=QA-SOLASTOCK-BETA-20260706 : QA sample prefix to inspect}';

    protected $description = 'Dry-run inventory QA sample cleanup report for a single prefix. Does not delete records.';

    public function handle(TenantManager $tenants): int
    {
        $org = (int) $this->option('org');
        if ($org <= 0) {
            $this->error('Provide --org=<organization id>.');

            return self::FAILURE;
        }

        $prefix = trim((string) $this->option('prefix'));
        if ($prefix === '' || ! str_starts_with($prefix, 'QA-SOLASTOCK-BETA-')) {
            $this->error('Refusing to inspect a non-QA prefix.');

            return self::FAILURE;
        }

        $database = $tenants->useTenant($org, $this->option('database') ?: null);
        $connection = (string) config('tenancy.tenant_connection', 'tenant');

        $this->info("Database: {$database}");
        $this->info("Organization: {$org}");
        $this->info("Prefix: {$prefix}");
        $this->warn('Dry-run only. No records are deleted by this command.');

        $like = $prefix.'%';
        $queries = [
            'items' => ['items', 'sku'],
            'warehouses' => ['warehouses', 'code'],
            'suppliers' => ['inventory_suppliers', 'code'],
            'opening_stock_entries' => ['opening_stock_entries', 'notes'],
            'purchase_orders' => ['inventory_purchase_orders', 'notes'],
            'goods_receipts' => ['goods_receipts', 'notes'],
            'stock_transfers' => ['stock_transfers', 'notes'],
            'stock_adjustments' => ['stock_adjustments', 'notes'],
            'stock_counts' => ['stock_counts', 'notes'],
            'sales_orders' => ['inventory_sales_orders', 'notes'],
        ];

        $summary = [];
        foreach ($queries as $label => [$table, $column]) {
            $rows = DB::connection($connection)->table($table)
                ->where('organization_id', $org)
                ->where($column, 'like', $like)
                ->orderBy('id')
                ->get(['id', $column]);

            $summary[] = [$label, $rows->count(), $rows->pluck('id')->implode(', ')];
        }

        $this->table(['Record type', 'Count', 'IDs'], $summary);
        $this->line('Cleanup policy: posted documents and ledger/cost-layer dependencies require a coordinated owner-approved cleanup window.');

        return self::SUCCESS;
    }
}
