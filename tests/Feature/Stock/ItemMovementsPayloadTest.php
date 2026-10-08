<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\InventorySetting;
use App\Services\Documents\OpeningStockService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Phase 1 — the item movements payload must be user-legible: a resolved warehouse
 * NAME (not a bare id) and clear running-balance + cost fields. Read-only.
 */
class ItemMovementsPayloadTest extends TestCase
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

    #[Test]
    public function movements_payload_includes_warehouse_name_and_running_balance(): void
    {
        $this->boot();
        $wh = F::warehouse(['name' => 'Main Depot']);
        $item = F::fifoItem();
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'PM-1', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '10.0000', 'unit_cost' => '5.0000']]));
        // An OUT so we exercise a costed movement with running balance after.
        app(StockLedgerService::class)->post([
            new StockMovement(direction: 'out', itemId: $item->id, warehouseId: $wh->id,
                quantity: '4.0000', sourceType: 'manual_test', sourceId: 1),
        ], 'payload:out');

        $resp = app(ItemController::class)->movements(Request::create('/', 'GET'), $item)->getData(true);
        $rows = $resp['data'];
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            // Warehouse NAME resolved, not just an id.
            $this->assertSame($wh->id, $row['warehouse_id']);
            $this->assertSame('Main Depot', $row['warehouse_name']);
            $this->assertArrayHasKey('warehouse_code', $row);
            // Clear running-balance fields.
            $this->assertArrayHasKey('balance_qty_after', $row);
            $this->assertArrayHasKey('balance_value_after', $row);
            // Movement cost fields.
            $this->assertArrayHasKey('unit_cost', $row);
            $this->assertArrayHasKey('total_cost', $row);
            // A human source label, not a raw FQCN.
            $this->assertArrayHasKey('source_label', $row);
        }

        // The OUT row's running on-hand is 6 (10 in − 4 out).
        $out = collect($rows)->firstWhere('direction', 'out');
        $this->assertNotNull($out);
        $this->assertSame('6.0000', $out['balance_qty_after']);
    }

    #[Test]
    public function movements_payload_is_read_only(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::fifoItem();
        app(OpeningStockService::class)->post(app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'PM-RO', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '3.0000', 'unit_cost' => '2.0000']]
        ));

        $before = \App\Models\Tenant\StockLedger::query()->count();
        app(ItemController::class)->movements(Request::create('/', 'GET'), $item);
        $this->assertSame($before, \App\Models\Tenant\StockLedger::query()->count(),
            'reading movements must not write to the ledger');
    }
}
