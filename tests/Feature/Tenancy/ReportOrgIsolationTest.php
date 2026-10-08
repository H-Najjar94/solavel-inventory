<?php

namespace Tests\Feature\Tenancy;

use App\Services\Documents\OpeningStockService;
use App\Services\Reports\DashboardMetricsService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportFilters;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * C2 — proves the raw-query reporting layer (dashboard + reports) is isolated
 * per ORGANIZATION within a SHARED per-client tenant DB. Two orgs live in the
 * same tenant database (tenant_990010); each must see ONLY its own items, stock
 * value and report rows, never the other org's. These services use the query
 * builder directly (bypassing the Eloquent OrganizationScope), so this guards
 * the explicit organization_id filters we added.
 */
class ReportOrgIsolationTest extends TestCase
{
    use TenantAware;

    private const ORG_A = 990010; // the reserved tenant's primary org
    private const ORG_B = 990077; // a SECOND org sharing the SAME tenant DB

    private function asOrg(int $orgId, callable $fn): void
    {
        app(OrganizationContext::class)->set($orgId);
        $fn();
    }

    private function valuation(InventoryReportService $reports): array
    {
        return collect($reports->run('inventory-valuation', ReportFilters::fromRequest(Request::create('/x')))['rows'] ?? [])
            ->pluck('sku')->all();
    }

    #[Test]
    public function dashboard_and_reports_only_show_the_active_orgs_data(): void
    {
        $this->useTenantA(); // tenant_990010 + context = ORG_A
        $opening = app(OpeningStockService::class);
        $metrics = app(DashboardMetricsService::class);
        $reports = app(InventoryReportService::class);
        $suffix = (string) now()->format('Hisv');
        $aSkus = ['A-1-'.$suffix, 'A-2-'.$suffix];
        $bSkus = ['B-1-'.$suffix, 'B-2-'.$suffix, 'B-3-'.$suffix];

        $beforeA = null;
        $beforeB = null;
        $this->asOrg(self::ORG_A, function () use ($metrics, &$beforeA) {
            $beforeA = $metrics->metrics();
        });
        $this->asOrg(self::ORG_B, function () use ($metrics, &$beforeB) {
            $beforeB = $metrics->metrics();
        });

        // Org A: 2 items, opening stock worth 100.00 (10×5 + 10×5).
        $this->asOrg(self::ORG_A, function () use ($opening, $aSkus, $suffix) {
            $wh = F::warehouse();
            $a1 = F::item(['sku' => $aSkus[0]]);
            $a2 = F::item(['sku' => $aSkus[1]]);
            $opening->post($opening->createDraft(
                ['entry_number' => 'OSA-'.$suffix, 'warehouse_id' => $wh->id],
                [
                    ['item_id' => $a1->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000'],
                    ['item_id' => $a2->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000'],
                ]
            ));
        });

        // Org B (SAME DB): 3 items, opening stock worth 900.00 (100×3 + ...).
        $this->asOrg(self::ORG_B, function () use ($opening, $bSkus, $suffix) {
            $wh = F::warehouse();
            $b1 = F::item(['sku' => $bSkus[0]]);
            $b2 = F::item(['sku' => $bSkus[1]]);
            $b3 = F::item(['sku' => $bSkus[2]]);
            $opening->post($opening->createDraft(
                ['entry_number' => 'OSB-'.$suffix, 'warehouse_id' => $wh->id],
                [
                    ['item_id' => $b1->id, 'quantity' => '100.0000', 'unit_cost' => '3.0000'],
                    ['item_id' => $b2->id, 'quantity' => '100.0000', 'unit_cost' => '3.0000'],
                    ['item_id' => $b3->id, 'quantity' => '100.0000', 'unit_cost' => '3.0000'],
                ]
            ));
        });

        // ── Org A sees ONLY its data — never the combined 5 items / mixed value ──
        $this->asOrg(self::ORG_A, function () use ($metrics, $reports, $beforeA, $aSkus, $bSkus) {
            $m = $metrics->metrics();
            $this->assertSame((int) $beforeA['total_skus'] + 2, $m['total_skus'], 'Org A dashboard counts only Org A item delta');
            $this->assertSame(number_format((float) $beforeA['inventory_value'] + 100, 2, '.', ''), $m['inventory_value'], 'Org A value excludes Org B stock');
            $skus = $this->valuation($reports);
            foreach ($aSkus as $sku) {
                $this->assertContains($sku, $skus, 'Org A report shows its new SKUs');
            }
            foreach ($bSkus as $sku) {
                $this->assertNotContains($sku, $skus, 'Org A report excludes Org B SKUs');
            }
        });

        // ── Org B sees ONLY its data ──
        $this->asOrg(self::ORG_B, function () use ($metrics, $reports, $beforeB, $aSkus, $bSkus) {
            $m = $metrics->metrics();
            $this->assertSame((int) $beforeB['total_skus'] + 3, $m['total_skus'], 'Org B dashboard counts only Org B item delta');
            $this->assertSame(number_format((float) $beforeB['inventory_value'] + 900, 2, '.', ''), $m['inventory_value'], 'Org B value excludes Org A stock');
            $skus = $this->valuation($reports);
            foreach ($bSkus as $sku) {
                $this->assertContains($sku, $skus, 'Org B report shows its new SKUs');
            }
            foreach ($aSkus as $sku) {
                $this->assertNotContains($sku, $skus, 'Org B report excludes Org A SKUs');
            }
        });
    }

    #[Test]
    public function no_org_context_returns_nothing_fail_closed(): void
    {
        $this->useTenantA();
        $this->asOrg(self::ORG_A, fn () => F::item(['sku' => 'X-1']));

        app(OrganizationContext::class)->forget();
        $m = app(DashboardMetricsService::class)->metrics();
        $this->assertSame(0, $m['total_skus'], 'No org context must expose no data (fail closed)');
    }

    #[Test]
    public function operational_reports_execute_with_current_schema(): void
    {
        $this->useTenantA();
        app(OrganizationContext::class)->set(self::ORG_A);

        $reports = app(InventoryReportService::class);
        foreach (['warehouse-stock', 'pick-list', 'shipment'] as $key) {
            $result = $reports->run($key, new ReportFilters(limit: 5));
            $this->assertNotEmpty($result['columns'], "{$key} should declare visible columns");
            $this->assertIsIterable($result['rows'], "{$key} should return iterable rows");
        }
    }
}
