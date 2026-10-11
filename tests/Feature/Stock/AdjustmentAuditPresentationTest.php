<?php
namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Models\Tenant\InventoryAuditLog;
use App\Services\Documents\{OpeningStockService, StockAdjustmentService, InventoryReversalService};
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class AdjustmentAuditPresentationTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function detail_reports_only_its_posting_and_reversal_without_mutating_stock(): void
    {
        $this->useTenantA();
        $wh = F::warehouse(); $item = F::fifoItem();
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $wh->id], [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '10']]));
        $service = app(StockAdjustmentService::class);
        $adjustment = $service->createDraft(['warehouse_id' => $wh->id], [['direction' => 'decrease', 'item_id' => $item->id, 'quantity' => '2']]);
        $service->post($adjustment);
        $other = $service->createDraft(['warehouse_id' => $wh->id], [['direction' => 'decrease', 'item_id' => $item->id, 'quantity' => '1']]);
        $service->post($other);
        $controller = app(StockAdjustmentController::class);
        $data = $controller->show($adjustment->fresh())->getData(true)['data'];
        $this->assertEquals(20, $data['adjustment']['total_decrease_value']);
        $this->assertEquals(10, $data['adjustment']['lines'][0]['unit_cost']);
        $this->assertEquals(0, $adjustment->fresh()->getRawOriginal('total_decrease_value'), 'Read projection never rewrites a historical header');
        $this->assertSame(['stock_adjustment.create', 'stock_adjustment.post'], array_column($data['audit'], 'action'));
        $this->assertArrayNotHasKey('ip', $data['audit'][0]);
        $this->assertSame($adjustment->adjustment_number, $data['audit'][0]['document_ref']);
        $reversal = app(InventoryReversalService::class)->reverseNegativeAdjustment($adjustment, 'Synthetic audit verification');
        $before = \App\Models\Tenant\StockLedger::query()->count();
        $audits = InventoryAuditLog::query()->count();
        $data = $controller->show($adjustment->fresh())->getData(true)['data'];
        $this->assertSame(['stock_adjustment.create', 'stock_adjustment.post', 'stock_adjustment.reverse'], array_column($data['audit'], 'action'));
        $this->assertSame($reversal->reversal_number, $data['audit'][2]['document_ref']);
        $this->assertSame('Synthetic audit verification', $data['audit'][2]['after']['reason']);
        $this->assertSame(['status' => 'posted'], $data['audit'][2]['before']);
        $this->assertSame($before, \App\Models\Tenant\StockLedger::query()->count());
        $this->assertSame($audits, InventoryAuditLog::query()->count());
        // Even a stale model cannot make the query read another organization's audit.
        $this->useTenantB();
        $data = $controller->show($adjustment)->getData(true)['data'];
        $this->assertSame([], $data['audit']);
    }
    #[Test]
    public function fifo_decrease_presentation_and_export_payload_use_actual_cost_at_dated_cutoffs(): void
    {
        $this->useTenantA(); $wh = F::warehouse(); $item = F::fifoItem();
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $wh->id, 'opening_date' => '2026-10-11'], [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '10']]));
        $service = app(StockAdjustmentService::class);
        $in = $service->createDraft(['warehouse_id' => $wh->id, 'adjustment_date' => '2026-10-12'], [['direction' => 'increase', 'item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '20']]);
        $service->post($in);
        $out = $service->createDraft(['warehouse_id' => $wh->id, 'adjustment_date' => '2026-10-13'], [['direction' => 'decrease', 'item_id' => $item->id, 'quantity' => '12']]);
        $service->post($out); $service->post($out->fresh());
        $report = app(\App\Services\Reports\InventoryReportService::class);
        $filters = new \App\Services\Reports\ReportFilters;
        $adjustments = $report->run('adjustment', $filters);
        $row = collect($adjustments['rows'])->firstWhere('id', $out->id);
        $this->assertEquals(140, $row->total_decrease_value);
        $this->assertSame(3, \App\Models\Tenant\StockLedger::query()->count());
        foreach (['2026-10-11' => [10,100], '2026-10-12' => [20,300], '2026-10-13' => [8,160]] as $date => [$qty,$value]) {
            $filters = new \App\Services\Reports\ReportFilters(asAt: $date);
            $result = $report->run('inventory-valuation', $filters);
            $row = collect($result['rows'])->first();
            $this->assertEquals($qty, $row->on_hand_qty);
            $this->assertEquals($value, $row->total_value);
        }
    }

}
