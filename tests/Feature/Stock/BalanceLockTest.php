<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * C3 — proves the negative-stock invariant holds on the FIRST movement at a
 * coordinate (where the stock_balances row is created on the fly), and that
 * sequential movements at the same coordinate never lose an update. The fix
 * re-fetches the freshly-created balance row WITH a FOR UPDATE lock before any
 * negative-stock / availability check reads it.
 */
class BalanceLockTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]
        );
    }

    private function balanceFor(int $itemId, int $whId): ?StockBalance
    {
        return StockBalance::query()->where('item_id', $itemId)->where('warehouse_id', $whId)->first();
    }

    #[Test]
    public function negative_stock_is_blocked_on_a_brand_new_coordinate(): void
    {
        // No opening stock → the OUT is the FIRST movement; the balance row is
        // created inside lockBalance(), then the guard reads it under lock.
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        $adj = app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'C3-NEW', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]
        );

        $this->expectException(\RuntimeException::class); // cannot go negative from nothing
        app(StockAdjustmentService::class)->post($adj);
    }

    #[Test]
    public function out_exceeding_freshly_created_balance_is_blocked(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        // First movement creates the balance (on_hand = 10).
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'C3-OS', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '4.0000']]
        ));
        $this->assertSame('10.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);

        // An OUT of 15 must be blocked against the locked balance (10 < 15).
        $adj = app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'C3-OVER', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '15']]
        );
        $this->expectException(\RuntimeException::class);
        app(StockAdjustmentService::class)->post($adj);
    }

    #[Test]
    public function sequential_inbounds_to_same_new_coordinate_accumulate_no_lost_update(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $os = app(OpeningStockService::class);

        // First inbound creates the row; second must lock & accumulate onto it.
        $os->post($os->createDraft(['entry_number' => 'C3-A', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        $os->post($os->createDraft(['entry_number' => 'C3-B', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '5.0000', 'unit_cost' => '5.0000']]));

        $bal = $this->balanceFor($item->id, $wh->id);
        $this->assertSame('15.0000', $bal->on_hand_qty, 'second inbound must accumulate, not overwrite');
        $this->assertSame('75.00', $bal->total_value);
    }
}
