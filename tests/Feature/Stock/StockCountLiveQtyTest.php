<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * High (correctness) — a stock count must post its variance against the LIVE
 * on-hand at POST time, not the client-supplied system_qty captured at draft
 * time. Proves the TOCTOU fix: if stock moves between counting and posting, the
 * posted result still matches the physical count.
 */
class StockCountLiveQtyTest extends TestCase
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

    #[Test]
    public function count_posts_against_live_on_hand_not_stale_system_qty(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        // Start: on-hand = 10.
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'CNT-OS', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]
        ));

        // Draft a count: counter physically counted 12, and the system showed 10
        // AT THAT TIME (draft variance would be +2).
        $count = app(StockCountService::class)->createDraft(
            ['count_number' => 'CNT-1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'system_qty' => '10.0000', 'counted_qty' => '12.0000']]
        );

        // BETWEEN counting and posting, a shipment/adjustment moves stock:
        // on-hand goes 10 → 15. The stale draft variance (+2) is now wrong.
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'CNT-MOVE', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '5', 'unit_cost' => '5.0000']]
        ));
        $this->assertSame('15.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        // Post the count. With the bug, it would add the stale +2 → 17. Fixed, it
        // recomputes variance against the LIVE 15 (12 - 15 = -3) and lands on 12.
        app(StockCountService::class)->post($count);

        $this->assertSame('12.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'),
            'count must reconcile to the PHYSICAL count (12) using live on-hand, not the stale system_qty');
    }
}
