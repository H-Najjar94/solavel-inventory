<?php

namespace Tests\Feature\Stock;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class InventoryIntegrityRepairPlanCommandTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function repair_plan_reports_missing_balance_rows_without_applying_by_default(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();

        DB::connection('tenant')->table('stock_ledger')->insert([
            'organization_id' => TenantTestManager::ORG_A,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'direction' => 'in',
            'quantity' => '7.0000',
            'unit_cost' => '3.0000',
            'total_cost' => '21.00',
            'costing_method' => 'average',
            'source_type' => 'TestDocument',
            'source_id' => 1,
            'moved_at' => now(),
            'posted_at' => now(),
            'idempotency_key' => 'repair-plan-test-1',
            'balance_qty_after' => '7.0000',
            'balance_value_after' => '21.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:integrity-repair-plan', [
            '--org' => TenantTestManager::ORG_A,
            '--database' => 'tenant_990010',
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('"missing_balance_rows": 1');

        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->count());
    }

    #[Test]
    public function repair_plan_refuses_apply_without_backup_verification(): void
    {
        $this->useTenantA();

        $this->artisan('inventory:integrity-repair-plan', [
            '--org' => TenantTestManager::ORG_A,
            '--database' => 'tenant_990010',
            '--apply' => true,
            '--json' => true,
        ])->assertFailed()
            ->expectsOutputToContain('Refusing --apply without --backup-verified.');
    }
}
