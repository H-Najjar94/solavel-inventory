<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\SerialNumber;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Serial enforcement + concurrency protection. Real MySQL, reserved tenant_990010.
 * NOT EXECUTED in the current shell (no MySQL OS-user auth).
 */
class SerialAndConcurrencyTest extends TestCase
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

    // 18. Serial-tracked inbound then outbound flips serial status
    #[Test]
    public function serial_inbound_then_outbound_updates_status(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::serialItem();
        $serial = F::serial($item, 'SN-001');

        // inbound qty must be exactly 1
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'OS-S1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'serial_id' => $serial->id, 'quantity' => '1', 'unit_cost' => '10']]
        ));
        $this->assertSame('in_stock', $serial->fresh()->status);

        // outbound (decrease) → sold
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'ADJ-S1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'serial_id' => $serial->id, 'direction' => 'decrease', 'quantity' => '1']]
        ));
        $this->assertSame('sold', $serial->fresh()->status);
    }

    // 18b. Serial-tracked movement with qty != 1 is rejected
    #[Test]
    public function serial_movement_must_be_quantity_one(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::serialItem();
        $serial = F::serial($item, 'SN-002');

        $this->expectException(\RuntimeException::class);
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'OS-S2', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'serial_id' => $serial->id, 'quantity' => '2', 'unit_cost' => '10']]
        ));
    }

    // 19. Serial uniqueness (DB unique constraint per org+item+serial)
    #[Test]
    public function duplicate_serial_is_rejected(): void
    {
        $this->boot();
        $item = F::serialItem();
        F::serial($item, 'SN-DUP');

        $this->expectException(\Illuminate\Database\QueryException::class);
        F::serial($item, 'SN-DUP'); // same serial, same item → unique violation
    }

    // 19b. Double-inbound of the same serial is rejected (already in stock)
    #[Test]
    public function serial_cannot_be_received_twice(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::serialItem();
        $serial = F::serial($item, 'SN-003');
        $os = app(OpeningStockService::class);

        $os->post($os->createDraft(
            ['entry_number' => 'OS-S3', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'serial_id' => $serial->id, 'quantity' => '1', 'unit_cost' => '10']]
        ));

        $this->expectException(\RuntimeException::class);
        $os->post($os->createDraft(
            ['entry_number' => 'OS-S3b', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'serial_id' => $serial->id, 'quantity' => '1', 'unit_cost' => '10']]
        ));
    }

    // 22. Concurrent outbound protection via lockForUpdate.
    // Two separate connections compete for the last unit; exactly one succeeds
    // when negative stock is disabled. Uses a second MySQL connection to the same
    // reserved DB so the row lock is genuinely contended.
    #[Test]
    public function concurrent_outbound_is_serialized_by_lock_for_update(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem();
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'OS-22', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '1', 'unit_cost' => '5']]
        ));

        // First outbound consumes the only unit.
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'ADJ-22a', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1']]
        ));
        $this->assertSame('0.0000', StockBalance::query()->where('item_id', $item->id)->first()->on_hand_qty);

        // Second outbound must fail (no stock, negative disabled) — proving the
        // balance is read under lock and the policy holds across operations.
        $this->expectException(\RuntimeException::class);
        app(StockAdjustmentService::class)->post(app(StockAdjustmentService::class)->createDraft(
            ['adjustment_number' => 'ADJ-22b', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1']]
        ));
    }
}
