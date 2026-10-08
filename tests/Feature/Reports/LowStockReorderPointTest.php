<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\InventorySetting;
use App\Services\Documents\OpeningStockService;
use App\Services\Reports\DashboardMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class LowStockReorderPointTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function low_stock_uses_reorder_points_and_counts_items_not_rows(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $a = F::warehouse(['code' => 'LOW-A']);
        $b = F::warehouse(['code' => 'LOW-B']);
        $belowItemPoint = F::averageItem(['sku' => 'LOW-1', 'reorder_point' => '10']);
        $noPoint = F::averageItem(['sku' => 'LOW-2']);
        $twoWarehouses = F::averageItem(['sku' => 'LOW-3', 'reorder_point' => '100']);
        $warehouseRule = F::averageItem(['sku' => 'LOW-4', 'reorder_point' => '1']);
        $opening = app(OpeningStockService::class);
        $stock = function ($warehouse, array $lines) use ($opening) {
            static $n = 0;
            $opening->post($opening->createDraft(['entry_number' => 'LOW-OS-'.(++$n), 'warehouse_id' => $warehouse->id],
                array_map(fn ($l) => ['item_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => '1'], $lines)));
        };
        $stock($a, [[$belowItemPoint, '8'], [$noPoint, '3'], [$twoWarehouses, '2'], [$warehouseRule, '20']]);
        $stock($b, [[$twoWarehouses, '2']]);
        DB::connection('tenant')->table('warehouse_reorder_rules')->insert([
            'organization_id' => TenantTestManager::ORG_A, 'item_id' => $warehouseRule->id, 'warehouse_id' => $a->id,
            'reorder_point' => '25', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $metrics = app(DashboardMetricsService::class)->metrics();
        // LOW-1 (8 ≤ 10), LOW-3 (once, not per warehouse), LOW-4 (20 ≤ warehouse rule 25).
        // LOW-2 has 3 available but no reorder point: not low (the old fixed 5 said low).
        $this->assertSame(3, $metrics['low_stock']);
        $this->assertSame(0, $metrics['out_of_stock']);
        // The value tile is labelled with the organization currency, not "$".
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $metrics['currency_code']);

        $low = app(ItemController::class)->index(Request::create('/items', 'GET', ['stock_status' => 'low']))->getData(true)['data'];
        $this->assertEqualsCanonicalizing(['LOW-1', 'LOW-3', 'LOW-4'], array_column($low, 'sku'));
    }
}
