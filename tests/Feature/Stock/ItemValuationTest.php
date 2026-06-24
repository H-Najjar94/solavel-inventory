<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\InventorySetting;
use App\Services\Documents\OpeningStockService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Phase 1 — item valuation visibility. The valuation endpoint surfaces the FIFO
 * cost-layer stack (previously invisible) plus per-warehouse on-hand value and a
 * reconciliation flag. Read-only; no writes, no ledger mutation.
 */
class ItemValuationTest extends TestCase
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

    private function valuationFor(int $itemId): array
    {
        $item = \App\Models\Tenant\Item::query()->findOrFail($itemId);

        return app(ItemController::class)->valuation($item)->getData(true)['data'];
    }

    #[Test]
    public function valuation_exposes_fifo_layers_in_order_and_reconciles(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $os = app(OpeningStockService::class);
        // Two layers: 10 @ $5 then 10 @ $8 → value $130, on-hand 20.
        $os->post($os->createDraft(['entry_number' => 'V-L1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        $os->post($os->createDraft(['entry_number' => 'V-L2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '8.0000']]));

        $val = $this->valuationFor($item->id);

        $this->assertSame('130.00', $val['on_hand_value']);
        $this->assertCount(1, $val['warehouses']);
        $w = $val['warehouses'][0];
        // Warehouse name/code surfaced for the UI (not just an id).
        $this->assertSame($wh->id, $w['warehouse_id']);
        $this->assertSame($wh->name, $w['warehouse_name']);
        $this->assertSame($wh->code, $w['warehouse_code']);
        $this->assertSame('20.0000', $w['on_hand_qty']);
        $this->assertTrue($w['qty_reconciled'], 'Σ layer qty must equal on-hand qty');
        // Before any consumption FIFO and average agree.
        $this->assertSame('130.00', $w['fifo_value']);
        $this->assertSame('130.00', $w['average_value']);

        // Layers surfaced in FIFO order with their true per-layer costs.
        $this->assertCount(2, $w['layers']);
        $this->assertSame(['5.0000', '8.0000'], array_column($w['layers'], 'unit_cost'));
        $this->assertSame(['10.0000', '10.0000'], array_column($w['layers'], 'remaining_qty'));
        $this->assertSame(['50.00', '80.00'], array_column($w['layers'], 'layer_value'));
    }

    #[Test]
    public function valuation_reflects_partial_layer_consumption(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'V-C1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        $os->post($os->createDraft(['entry_number' => 'V-C2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '8.0000']]));

        // Consume 15: layer1 fully (10@5), layer2 partially (5@8) → remaining 5@8 = $40.
        app(StockLedgerService::class)->post([
            new StockMovement(direction: 'out', itemId: $item->id, warehouseId: $wh->id,
                quantity: '15.0000', sourceType: 'manual_test', sourceId: 1),
        ], 'val-consume:out');

        $val = $this->valuationFor($item->id);
        $w = $val['warehouses'][0];
        $this->assertSame('5.0000', $w['on_hand_qty']);
        // True FIFO value of the remaining 5 @ $8.
        $this->assertSame('40.00', $w['fifo_value']);
        // balance.total_value is now maintained on the FIFO basis for a FIFO item
        // (running ledger in−out cost), so it NO LONGER diverges from the layer
        // value after partial consumption — both are $40.00. (Previously this
        // surfaced a weighted-average $32.50, which drifted from FIFO truth and the
        // integrity checker flagged as a value mismatch — the cost-layer collapse.)
        $this->assertSame('40.00', $w['average_value']);
        // Quantity still reconciles (Σ layer qty == on-hand qty).
        $this->assertTrue($w['qty_reconciled']);
        // Headline on-hand value follows the FIFO basis for a FIFO item.
        $this->assertSame('40.00', $val['on_hand_value']);
        // Only the partially-consumed second layer remains (5 @ $8).
        $this->assertCount(1, $w['layers']);
        $this->assertSame('8.0000', $w['layers'][0]['unit_cost']);
        $this->assertSame('5.0000', $w['layers'][0]['remaining_qty']);
    }

    #[Test]
    public function valuation_is_org_scoped_other_orgs_item_is_not_visible(): void
    {
        // Seed an item for ORG_A.
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'V-ORG', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '3.0000', 'unit_cost' => '2.0000']]
        ));
        $idInA = $item->id;

        // Switch to ORG_B (same tenant DB rows are scoped): the item must not resolve.
        app(\App\Tenancy\OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->assertNull(
            \App\Models\Tenant\Item::query()->find($idInA),
            'item from ORG_A must be invisible under ORG_B scope'
        );
    }
}
