<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\CostLayer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Stock\IntegrityChecker;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Phase 1 canonical stock-engine tests. Real MySQL, reserved tenant_990010.
 * Each test runs inside a transaction (TenantAware) and rolls back.
 *
 * NOT EXECUTED in the current shell (no MySQL OS-user auth). Run with:
 *   sudo -u mysql php artisan test
 */
class StockEngineTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => \Tests\Support\TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]
        );
    }

    private function opening(): OpeningStockService
    {
        return app(OpeningStockService::class);
    }

    private function adjustments(): StockAdjustmentService
    {
        return app(StockAdjustmentService::class);
    }

    private function balanceFor(int $itemId, int $warehouseId): ?StockBalance
    {
        return StockBalance::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->first();
    }

    // 1. Opening stock posting
    #[Test]
    public function opening_stock_posting_creates_ledger_and_balance(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        $entry = $this->opening()->createDraft(
            ['entry_number' => 'OS-1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]
        );
        $this->opening()->post($entry);

        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame(1, StockLedger::query()->where('source_id', $entry->id)->count());
        $bal = $this->balanceFor($item->id, $wh->id);
        $this->assertSame('10.0000', $bal->on_hand_qty);
        $this->assertSame('5.0000', $bal->average_cost);
        $this->assertSame('50.00', $bal->total_value);
    }

    // 2. Duplicate opening-stock retry (idempotent)
    #[Test]
    public function opening_stock_posting_is_idempotent(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $entry = $this->opening()->createDraft(
            ['entry_number' => 'OS-2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        );

        $this->opening()->post($entry);
        $this->opening()->post($entry->fresh()); // retry

        $this->assertSame(1, StockLedger::query()->where('source_id', $entry->id)->count());
        $this->assertSame('10.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 3. Opening-stock reversal
    #[Test]
    public function opening_stock_reversal_unwinds_stock(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $entry = $this->opening()->createDraft(
            ['entry_number' => 'OS-3', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        );
        $this->opening()->post($entry);
        $this->opening()->reverse($entry->fresh());

        $this->assertSame('reversed', $entry->fresh()->status);
        $this->assertSame('0.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
        $this->assertSame(2, StockLedger::query()->where('source_id', $entry->id)->count()); // in + reversing out
    }

    // 4. Adjustment increase
    #[Test]
    public function adjustment_increase_adds_stock(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        $adj = $this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-1', 'warehouse_id' => $wh->id, 'reason_code' => 'found'],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '7', 'unit_cost' => '3']]
        );
        $this->adjustments()->post($adj);

        $this->assertSame('7.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 5. Adjustment decrease
    #[Test]
    public function adjustment_decrease_removes_stock(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-5', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '4']]
        ));

        $adj = $this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-5', 'warehouse_id' => $wh->id, 'reason_code' => 'damage'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]
        );
        $this->adjustments()->post($adj);

        $this->assertSame('7.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 6. Adjustment reversal
    #[Test]
    public function adjustment_reversal_restores_stock(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $adj = $this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-6', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '5', 'unit_cost' => '2']]
        );
        $this->adjustments()->post($adj);
        $this->adjustments()->reverse($adj->fresh());

        $this->assertSame('reversed', $adj->fresh()->status);
        $this->assertSame('0.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 7. Negative stock blocked
    #[Test]
    public function negative_stock_is_blocked_by_default(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();

        $adj = $this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-7', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '5']]
        );

        $this->expectException(\RuntimeException::class);
        $this->adjustments()->post($adj);
    }

    // 8. Negative stock allowed when enabled
    #[Test]
    public function negative_stock_allowed_when_setting_enabled(): void
    {
        $this->boot();
        InventorySetting::query()->where('organization_id', \Tests\Support\TenantTestManager::ORG_A)
            ->update(['allow_negative_stock' => true]);
        $wh = F::warehouse();
        $item = F::averageItem();

        $adj = $this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-8', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '5']]
        );
        $this->adjustments()->post($adj);

        $this->assertSame('-5.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 9. Weighted average
    #[Test]
    public function weighted_average_is_correct(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        // 10 @ 5 then 10 @ 7 → avg 6
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-9a', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        ));
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-9', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '10', 'unit_cost' => '7']]
        ));

        $bal = $this->balanceFor($item->id, $wh->id);
        $this->assertSame('20.0000', $bal->on_hand_qty);
        $this->assertSame('6.0000', $bal->average_cost);
        $this->assertSame('120.00', $bal->total_value);
    }

    // 10. FIFO full layer consumption
    #[Test]
    public function fifo_consumes_full_layer(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-10', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        ));
        // consume all 10 via decrease
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-10', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '10']]
        ));

        $this->assertSame('0.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
        $out = StockLedger::query()->where('item_id', $item->id)->where('direction', 'out')->first();
        $this->assertSame('50.00', $out->total_cost); // 10 * 5
        $this->assertSame('0.0000', CostLayer::query()->where('item_id', $item->id)->sum('remaining_qty').'');
    }

    // 11. FIFO partial layer consumption
    #[Test]
    public function fifo_consumes_partial_layer(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-11', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        ));
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-11', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '4']]
        ));

        $out = StockLedger::query()->where('item_id', $item->id)->where('direction', 'out')->first();
        $this->assertSame('20.00', $out->total_cost); // 4 * 5
        $this->assertSame('6.0000', (string) CostLayer::query()->where('item_id', $item->id)->first()->remaining_qty);
    }

    // 12. FIFO across multiple layers
    #[Test]
    public function fifo_consumes_across_multiple_layers(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        // layer1: 10 @ 5, layer2: 10 @ 8
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-12a', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        ));
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-12in', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '10', 'unit_cost' => '8']]
        ));
        // consume 15 → 10@5 + 5@8 = 50 + 40 = 90
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-12out', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '15']]
        ));

        $out = StockLedger::query()->where('item_id', $item->id)->where('direction', 'out')->latest('id')->first();
        $this->assertSame('90.00', $out->total_cost);
        $this->assertSame('5.0000', (string) $this->balanceFor($item->id, $wh->id)->on_hand_qty);
        $this->assertSame('5.0000', (string) CostLayer::query()->where('item_id', $item->id)->sum('remaining_qty'));
    }

    // 13. Idempotent retry at the ledger level
    #[Test]
    public function ledger_post_is_idempotent_on_namespace(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $ledger = app(StockLedgerService::class);
        $mv = [new StockMovement('in', $item->id, $wh->id, '5', 'Test', 1, unitCost: '2')];

        $ledger->post($mv, 'test:1:post');
        $ledger->post($mv, 'test:1:post'); // retry same namespace

        $this->assertSame(1, StockLedger::query()->where('source_type', 'Test')->count());
        $this->assertSame('5.0000', $this->balanceFor($item->id, $wh->id)->on_hand_qty);
    }

    // 14. Ledger immutability
    #[Test]
    public function ledger_rows_cannot_be_updated_or_deleted(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-14', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '1', 'unit_cost' => '1']]
        ));
        $row = StockLedger::query()->first();

        $threwOnUpdate = false;
        try { $row->quantity = '999'; $row->save(); } catch (\RuntimeException $e) { $threwOnUpdate = true; }
        $this->assertTrue($threwOnUpdate, 'Ledger update should be blocked');

        $threwOnDelete = false;
        try { $row->delete(); } catch (\RuntimeException $e) { $threwOnDelete = true; }
        $this->assertTrue($threwOnDelete, 'Ledger delete should be blocked');
    }

    // 15. Posted-document immutability
    #[Test]
    public function posted_documents_are_immutable(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $entry = $this->opening()->createDraft(
            ['entry_number' => 'OS-15', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '1', 'unit_cost' => '1']]
        );
        $this->opening()->post($entry);

        $this->expectException(\RuntimeException::class);
        $posted = $entry->fresh();
        $posted->notes = 'tampered';
        $posted->save();
    }

    // 20. Lot tracking
    #[Test]
    public function lot_tracked_item_records_lot_on_ledger(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::lotItem();
        $lot = F::lot($item, ['expiry_date' => now()->addYear()->toDateString()]);

        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-20', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'lot_id' => $lot->id, 'quantity' => '6', 'unit_cost' => '2']]
        ));

        $row = StockLedger::query()->where('item_id', $item->id)->first();
        $this->assertSame((int) $lot->id, (int) $row->lot_id);
    }

    // 21. Fractional quantities
    #[Test]
    public function fractional_quantities_are_preserved(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-21', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '2.5000', 'unit_cost' => '1.2500']]
        ));

        $bal = $this->balanceFor($item->id, $wh->id);
        $this->assertSame('2.5000', $bal->on_hand_qty);
        $this->assertSame('3.13', $bal->total_value); // 2.5 * 1.25 = 3.125 → 3.13
    }

    // 23. Ledger/balance reconciliation via IntegrityChecker
    #[Test]
    public function integrity_checker_reports_consistent_state(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        $this->opening()->post($this->opening()->createDraft(
            ['entry_number' => 'OS-23', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]
        ));
        $this->adjustments()->post($this->adjustments()->createDraft(
            ['adjustment_number' => 'ADJ-23', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]
        ));

        $result = app(IntegrityChecker::class)->check(
            config('tenancy.tenant_connection'),
            \Tests\Support\TenantTestManager::ORG_A
        );
        $this->assertTrue($result['ok'], 'Integrity problems: '.implode('; ', $result['problems']));
    }
}
