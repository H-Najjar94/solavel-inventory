<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCount;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use App\Services\Integration\AccountingJournalBuilder;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * A count surplus (and an adjustment gain entered without a cost) is valued at
 * the item's current costing basis — never at 0 — so the gain is journalled and
 * later COGS is not understated. Same policy for both: SurplusCostResolver.
 */
class StockCountSurplusCostTest extends TestCase
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

    private function opening(int $warehouseId, int $itemId, string $qty, string $cost, string $number): void
    {
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => $number, 'warehouse_id' => $warehouseId],
            [['item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => $cost]]
        ));
    }

    private function countAndPost(int $warehouseId, int $itemId, string $system, string $counted, string $number): StockCount
    {
        $count = app(StockCountService::class)->createDraft(
            ['count_number' => $number, 'warehouse_id' => $warehouseId],
            [['item_id' => $itemId, 'system_qty' => $system, 'counted_qty' => $counted]]
        );

        return app(StockCountService::class)->post($count);
    }

    #[Test]
    public function average_cost_surplus_is_valued_at_the_current_average_and_keeps_it_unchanged(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening($wh->id, $item->id, '10.0000', '5.0000', 'SUR-AVG-OS');

        $count = $this->countAndPost($wh->id, $item->id, '10.0000', '12.0000', 'SUR-AVG');

        $adjustment = StockAdjustment::query()->with('lines')->findOrFail($count->adjustment_id);
        $this->assertSame('5.0000', (string) $adjustment->lines->sole()->unit_cost);
        $this->assertSame('10.00', (string) $adjustment->total_increase_value);
        $ledger = StockLedger::query()->where('source_type', StockAdjustment::class)->where('source_id', $adjustment->id)->sole();
        $this->assertSame('10.00', (string) $ledger->total_cost);
        $balance = StockBalance::query()->where('item_id', $item->id)->sole();
        $this->assertSame('12.0000', (string) $balance->on_hand_qty);
        $this->assertSame('5.0000', (string) $balance->average_cost);
        $this->assertSame('60.00', (string) $balance->total_value);

        // The gain journal goes through the adjustment's own event type.
        foreach (['inventory_asset' => 100, 'adjustment_gain' => 600, 'adjustment_loss' => 601] as $type => $id) {
            IntegrationAccountMapping::query()->create([
                'mapping_type' => $type, 'integration' => 'solabooks',
                'solabooks_account_id' => (string) $id, 'status' => 'mapped',
            ]);
        }
        $event = IntegrationOutboxEvent::query()->create([
            'event_uuid' => fake()->uuid(), 'integration' => 'solabooks', 'event_type' => 'adjustment.posted',
            'aggregate_type' => 'StockAdjustment', 'aggregate_id' => $adjustment->id, 'aggregate_number' => $adjustment->adjustment_number,
            'occurred_at' => now(), 'payload' => ['document_date' => now()->toDateString(), 'total_inventory_value_change' => (string) $ledger->total_cost],
            'status' => 'pending', 'mapping_status' => 'complete', 'attempts' => 0,
            'idempotency_key' => 'solabooks:adjustment.posted:StockAdjustment:'.$adjustment->id,
        ]);
        $lines = app(AccountingJournalBuilder::class)->build($event, TenantTestManager::ORG_A);
        $this->assertSame([100, 600], array_column($lines, 'account_id'));
        $this->assertSame(['10.00', '0.00'], array_column($lines, 'debit'));
        $this->assertSame(['0.00', '10.00'], array_column($lines, 'credit'));
    }

    #[Test]
    public function fifo_surplus_is_valued_at_the_latest_layer_cost(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->opening($wh->id, $item->id, '4.0000', '3.0000', 'SUR-FIFO-OS');
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'SUR-FIFO-IN', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '2', 'unit_cost' => '5.5000']]
        ));

        $count = $this->countAndPost($wh->id, $item->id, '6.0000', '7.0000', 'SUR-FIFO');

        $adjustment = StockAdjustment::query()->with('lines')->findOrFail($count->adjustment_id);
        $this->assertSame('5.5000', (string) $adjustment->lines->sole()->unit_cost);
        $this->assertSame('5.50', (string) StockLedger::query()->where('source_type', StockAdjustment::class)
            ->where('source_id', $adjustment->id)->value('total_cost'));
        $this->assertSame('5.5000', (string) CostLayer::query()->where('item_id', $item->id)
            ->orderByDesc('id')->value('unit_cost'));
        $this->assertSame(0, CostLayer::query()->where('item_id', $item->id)->where('unit_cost', 0)->count());
    }

    #[Test]
    public function adjustment_gain_without_entered_cost_uses_the_same_policy_and_an_explicit_cost_wins(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening($wh->id, $item->id, '4.0000', '8.0000', 'SUR-ADJ-OS');
        $service = app(StockAdjustmentService::class);

        $implicit = $service->createDraft(['adjustment_number' => 'SUR-ADJ-1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '1']]);
        $explicit = $service->createDraft(['adjustment_number' => 'SUR-ADJ-2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '0']]);

        $this->assertSame('8.0000', (string) $implicit->lines->sole()->unit_cost);
        $this->assertSame('0.0000', (string) $explicit->lines->sole()->unit_cost);
    }

    #[Test]
    public function surplus_without_any_cost_history_is_refused_instead_of_posted_at_zero(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem(['sku' => 'SUR-NONE']);
        $count = app(StockCountService::class)->createDraft(
            ['count_number' => 'SUR-NONE', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'system_qty' => '0.0000', 'counted_qty' => '2.0000']]
        );

        try {
            app(StockCountService::class)->post($count);
            $this->fail('A surplus with no cost basis must not post at zero.');
        } catch (RuntimeException $e) {
            $this->assertSame(__('inventory.stock.surplus_cost_required', ['sku' => 'SUR-NONE']), $e->getMessage());
        }

        $this->assertNotSame('posted', $count->fresh()->status);
        $this->assertSame(0, StockLedger::query()->where('item_id', $item->id)->count());
    }

    #[Test]
    public function count_with_zero_variance_posts_no_adjustment(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening($wh->id, $item->id, '3.0000', '2.0000', 'SUR-ZERO-OS');

        $count = $this->countAndPost($wh->id, $item->id, '3.0000', '3.0000', 'SUR-ZERO');

        $this->assertSame('posted', $count->status);
        $this->assertNull($count->adjustment_id);
    }
}
