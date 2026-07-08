<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCount;
use App\Models\Tenant\StockCountLine;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportFilters;
use App\Services\Stock\StockReservationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class CountsAndReservationsCompletionTest extends TestCase
{
    use TenantAware;

    private function bootTenant(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]
        );
    }

    #[Test]
    public function blind_scheduled_abc_count_preserves_snapshot_and_posts_against_live_stock(): void
    {
        $this->bootTenant();
        $warehouse = F::warehouse();
        $item = F::averageItem();

        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'B4-CNT-OS', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]
        ));

        $count = app(StockCountService::class)->createDraft([
            'count_number' => 'B4-CNT-1',
            'count_type' => 'cycle',
            'blind_count' => true,
            'warehouse_id' => $warehouse->id,
            'scheduled_for' => now()->addDay()->toDateString(),
            'recurrence' => 'weekly',
            'abc_class' => 'A',
            'freeze_snapshot' => true,
        ], [[
            'item_id' => $item->id,
            'system_qty' => '10.0000',
            'counted_qty' => '8.0000',
        ]]);

        $this->assertTrue((bool) $count->blind_count);
        $this->assertSame('A', $count->abc_class);
        $this->assertNotNull($count->snapshot_at);
        $this->assertSame('10.0000', StockCountLine::query()->where('stock_count_id', $count->id)->value('snapshot_qty'));

        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'B4-CNT-MOVE', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '3.0000', 'unit_cost' => '5.0000']]
        ));

        app(StockCountService::class)->post($count);
        $line = StockCountLine::query()->where('stock_count_id', $count->id)->firstOrFail();

        $this->assertSame('10.0000', (string) $line->snapshot_qty, 'frozen snapshot is preserved for audit');
        $this->assertSame('13.0000', (string) $line->system_qty, 'posting expected qty is recomputed under lock from live stock');
        $this->assertSame('-5.0000', (string) $line->variance_qty);
        $this->assertSame('8.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertNotNull($count->fresh()->adjustment_id);

        $next = StockCount::query()
            ->where('id', '!=', $count->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('recurrence', 'weekly')
            ->where('abc_class', 'A')
            ->first();
        $this->assertNotNull($next, 'posting a recurring count should create the next planned count');
        $this->assertSame(now()->addDays(8)->toDateString(), $next->scheduled_for->toDateString());
        $this->assertTrue((bool) $next->blind_count);
    }

    #[Test]
    public function reservation_expiry_releases_projection_and_priority_is_reported(): void
    {
        $this->bootTenant();
        $warehouse = F::warehouse();
        $item = F::averageItem();

        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'B4-RES-OS', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '5.0000', 'unit_cost' => '4.0000']]
        ));

        $service = app(SalesOrderService::class);
        $order = $service->createDraft([
            'order_number' => 'B4-SO-1',
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'Reservation QA',
        ], [[
            'item_id' => $item->id,
            'ordered_qty' => '4.0000',
            'unit_price' => '9.0000',
        ]]);
        $order = $service->confirm($order);
        $order = $service->reserve($order, [
            'priority' => 25,
            'expires_at' => now()->addHour()->toDateTimeString(),
        ]);

        $reservation = Reservation::query()->where('source_id', $order->id)->firstOrFail();
        $this->assertSame(25, (int) $reservation->priority);
        $this->assertSame('4.0000', StockBalance::query()->where('item_id', $item->id)->value('reserved_qty'));

        $report = app(InventoryReportService::class)->run('reservation', new ReportFilters);
        $this->assertSame(25, (int) $report['rows']->first()->priority);
        $this->assertNotNull($report['rows']->first()->expires_at);

        $service->reserve($order->fresh(), [
            'priority' => 25,
            'expires_at' => now()->addHour()->toDateTimeString(),
        ]);
        $this->assertSame(1, Reservation::query()->where('source_id', $order->id)->where('status', 'active')->count(), 'duplicate reserve updates the same hold');
        $this->assertSame('4.0000', StockBalance::query()->where('item_id', $item->id)->value('reserved_qty'), 'duplicate reserve does not double-hold stock');

        $reservation->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertSame(1, app(StockReservationService::class)->expireOverdue('sales_order', (int) $order->id));

        $this->assertSame('0.0000', StockBalance::query()->where('item_id', $item->id)->value('reserved_qty'));
        $this->assertNotNull($reservation->fresh()->expired_at);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('0.0000', $order->fresh('lines')->lines->first()->reserved_qty);
    }
}
