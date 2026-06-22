<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockTransferService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * High (correctness) — FIFO cost-layer fidelity on transfers and reversals.
 * Two source layers (10 @ $5, 10 @ $8 → value $130). A 15-unit move consumes the
 * first layer fully and the second partially. The destination / the reversal must
 * keep the SEPARATE layers and their true costs, never a single blended layer.
 */
class FifoLayerFidelityTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false]
        );
    }

    /** Seed two FIFO layers (10 @ $5 then 10 @ $8) into $wh for $item. */
    private function seedTwoLayers(int $itemId, int $whId): void
    {
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'L1-'.$whId, 'warehouse_id' => $whId],
            [['item_id' => $itemId, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        $os->post($os->createDraft(['entry_number' => 'L2-'.$whId, 'warehouse_id' => $whId],
            [['item_id' => $itemId, 'quantity' => '10.0000', 'unit_cost' => '8.0000']]));
    }

    private function layerValue(int $whId): string
    {
        $v = '0';
        foreach (CostLayer::query()->where('warehouse_id', $whId)->get() as $l) {
            $v = Decimal::add($v, Decimal::mul((string) $l->remaining_qty, (string) $l->unit_cost));
        }

        return Decimal::money($v);
    }

    #[Test]
    public function fifo_transfer_recreates_per_layer_at_destination_no_blended_collapse(): void
    {
        $this->boot();
        $src = F::warehouse();
        $dst = F::warehouse();
        $item = F::fifoItem();
        $this->seedTwoLayers($item->id, $src->id);

        // Move 15: consumes 10 @ $5 + 5 @ $8 (blended would be $6.00).
        $t = app(StockTransferService::class)->createDraft(
            ['transfer_number' => 'TR-1', 'from_warehouse_id' => $src->id, 'to_warehouse_id' => $dst->id],
            [['item_id' => $item->id, 'quantity' => '15.0000']]
        );
        app(StockTransferService::class)->post($t);

        // Destination keeps TWO distinct layers (10 @ $5, 5 @ $8) — not one @ $6.
        $dstLayers = CostLayer::query()->where('warehouse_id', $dst->id)->where('remaining_qty', '>', 0)
            ->orderBy('id')->get();
        $this->assertCount(2, $dstLayers, 'destination must preserve per-layer FIFO structure');
        $this->assertSame(['5.0000', '8.0000'], $dstLayers->pluck('unit_cost')->map(fn ($c) => (string) $c)->all());
        $this->assertSame(['10.0000', '5.0000'], $dstLayers->pluck('remaining_qty')->map(fn ($c) => (string) $c)->all());
        // No blended-cost collapse.
        $this->assertNotContains('6.0000', $dstLayers->pluck('unit_cost')->map(fn ($c) => (string) $c)->all());
        // Total value preserved across the move: src $50 left + dst $90 = $140? No —
        // src had $130, moved $90 out, so src $40 + dst $90 = $130.
        $this->assertSame('40.00', $this->layerValue($src->id));
        $this->assertSame('90.00', $this->layerValue($dst->id));
    }

    #[Test]
    public function reversing_a_fifo_out_restores_the_exact_consumed_layers(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->seedTwoLayers($item->id, $wh->id);
        $this->assertSame('130.00', $this->layerValue($wh->id));

        // OUT 15 through the ledger (consumes 10 @ $5 + 5 @ $8).
        app(StockLedgerService::class)->post([
            new StockMovement(direction: 'out', itemId: $item->id, warehouseId: $wh->id,
                quantity: '15.0000', sourceType: 'manual_test', sourceId: 1),
        ], 'fifo-rev:out');

        // After the OUT: layer1 = 0, layer2 = 5 → value $40, on-hand 5.
        $this->assertSame('40.00', $this->layerValue($wh->id));
        $this->assertSame('5.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        // Reverse → the EXACT layers are restored (10 + 5 back), NOT a blended layer.
        app(StockLedgerService::class)->reverse('fifo-rev:out', 'fifo-rev:out:reversed');

        $layers = CostLayer::query()->where('warehouse_id', $wh->id)->orderBy('id')->get();
        $this->assertCount(2, $layers, 'no new blended layer created on reversal');
        $this->assertSame(['10.0000', '10.0000'], $layers->pluck('remaining_qty')->map(fn ($c) => (string) $c)->all());
        $this->assertSame(['5.0000', '8.0000'], $layers->pluck('unit_cost')->map(fn ($c) => (string) $c)->all());
    }

    #[Test]
    public function valuation_is_correct_after_reversal(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->seedTwoLayers($item->id, $wh->id);

        app(StockLedgerService::class)->post([
            new StockMovement(direction: 'out', itemId: $item->id, warehouseId: $wh->id,
                quantity: '15.0000', sourceType: 'manual_test', sourceId: 1),
        ], 'fifo-val:out');
        app(StockLedgerService::class)->reverse('fifo-val:out', 'fifo-val:out:reversed');

        // Layer valuation and on-hand return to the pre-move state.
        $this->assertSame('130.00', $this->layerValue($wh->id), 'FIFO layer value restored exactly');
        $this->assertSame('20.0000', StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }

    #[Test]
    public function reversing_a_posted_fifo_transfer_restores_source_and_clears_destination(): void
    {
        $this->boot();
        $src = F::warehouse();
        $dst = F::warehouse();
        $item = F::fifoItem();
        $this->seedTwoLayers($item->id, $src->id);

        $t = app(StockTransferService::class)->createDraft(
            ['transfer_number' => 'TR-REV', 'from_warehouse_id' => $src->id, 'to_warehouse_id' => $dst->id],
            [['item_id' => $item->id, 'quantity' => '15.0000']]
        );
        app(StockTransferService::class)->post($t);
        $lineId = $t->fresh('lines')->lines->first()->id;
        $ns = 'stock_transfer:'.$t->id.':post';
        $ledger = app(StockLedgerService::class);

        // Reverse the two per-layer destination INs, then the source OUT.
        $ledger->reverse($ns.':in:'.$lineId.':1', $ns.':rev-in1');
        $ledger->reverse($ns.':in:'.$lineId.':0', $ns.':rev-in0');
        $ledger->reverse($ns.':out:'.$lineId, $ns.':rev-out');

        // Source fully restored (exact layers + value + on-hand); destination empty.
        $this->assertSame('130.00', $this->layerValue($src->id), 'source value restored');
        $this->assertSame('20.0000', StockBalance::query()->where('warehouse_id', $src->id)->value('on_hand_qty'));
        $this->assertSame('0.00', $this->layerValue($dst->id), 'destination layers cleared');
        $this->assertSame('0.0000', StockBalance::query()->where('warehouse_id', $dst->id)->value('on_hand_qty'));
    }
}
