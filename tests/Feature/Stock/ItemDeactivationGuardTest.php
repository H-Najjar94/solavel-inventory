<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use App\Services\Documents\StockTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Q12.3 / NF-1194-02 (owner decision 2026-10-18): an item cannot be deactivated while any
 * warehouse/bin balance is nonzero (positive or negative), stock is reserved, or an open stock
 * document references it. Quantities are never changed by the refusal.
 */
class ItemDeactivationGuardTest extends TestCase
{
    use TenantAware;

    private static function catalogItem(): Item
    {
        return F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
    }

    private function updateRequest(Item $item, array $data): UpdateItemRequest
    {
        $req = UpdateItemRequest::create('/api/v1/items/'.$item->id, 'PUT', $data);
        $req->setRouteResolver(fn () => new class($item) {
            public function __construct(private Item $item) {}
            public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
        });
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return $req;
    }

    /** @return array{0:int,1:array<string,mixed>} HTTP status + validation errors of a single deactivation */
    private function deactivate(Item $item): array
    {
        try {
            $response = app(ItemController::class)->update($this->updateRequest($item, ['is_active' => false]), $item);

            return [$response->getStatusCode(), []];
        } catch (ValidationException $e) {
            return [$e->status, $e->errors()];
        }
    }

    private function assertRefused(Item $item, string $blocker): void
    {
        [$status, $errors] = $this->deactivate($item);
        $this->assertSame(422, $status, 'deactivation must be refused');
        $this->assertArrayHasKey('is_active', $errors, json_encode($errors));
        $this->assertSame(__('inventory.catalog.deactivation_blocked'), $errors['is_active'][0]);
        $this->assertContains($blocker, $errors['deactivation_blockers']);
        $this->assertTrue((bool) $item->fresh()->is_active, 'item must stay active');
    }

    private function bin(int $warehouseId): int
    {
        $db = DB::connection('tenant');
        $zone = $db->table('warehouse_zones')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'warehouse_id' => $warehouseId, 'code' => 'Z'.uniqid(), 'name' => 'Zone', 'created_at' => now(), 'updated_at' => now()]);

        return $db->table('warehouse_bins')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'warehouse_id' => $warehouseId, 'zone_id' => $zone, 'code' => 'B'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function balance(Item $item, int $warehouseId, string $onHand, string $reserved = '0', ?int $binId = null): void
    {
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
            'bin_id' => $binId, 'on_hand_qty' => $onHand, 'reserved_qty' => $reserved, 'total_value' => '0']);
    }

    #[Test]
    public function positive_stock_in_one_warehouse_only_blocks_deactivation(): void
    {
        $this->useTenantA();
        $item = self::catalogItem();
        $this->balance($item, F::warehouse()->id, '0');
        $this->balance($item, F::warehouse()->id, '0.0001');
        $this->assertRefused($item, 'stock_on_hand');
        $this->assertSame(['0.000000', '0.000100'], StockBalance::query()->where('item_id', $item->id)->orderBy('id')->pluck('on_hand_qty')->map(fn ($q) => (string) $q)->all());
    }

    #[Test]
    public function negative_stock_in_one_bin_only_blocks_deactivation(): void
    {
        $this->useTenantA();
        $item = self::catalogItem();
        $warehouse = F::warehouse();
        $this->balance($item, $warehouse->id, '0');
        $this->balance($item, $warehouse->id, '-2', '0', $this->bin($warehouse->id));
        $this->assertRefused($item, 'negative_stock_on_hand');
        $this->assertSame('-2.000000', (string) StockBalance::query()->where('item_id', $item->id)->whereNotNull('bin_id')->value('on_hand_qty'));
    }

    #[Test]
    public function reserved_quantity_or_active_reservation_blocks_deactivation(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $reservedOnBalance = self::catalogItem();
        $this->balance($reservedOnBalance, $warehouse->id, '0', '1');
        $this->assertRefused($reservedOnBalance, 'active_reservation');

        $reservationRow = self::catalogItem();
        DB::connection('tenant')->table('reservations')->insert(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $reservationRow->id,
            'warehouse_id' => $warehouse->id, 'qty' => '1', 'source_type' => 'sales_order', 'source_id' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRefused($reservationRow, 'active_reservation');

        $released = self::catalogItem();
        DB::connection('tenant')->table('reservations')->insert(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $released->id,
            'warehouse_id' => $warehouse->id, 'qty' => '1', 'source_type' => 'sales_order', 'source_id' => 2, 'status' => 'released', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(200, $this->deactivate($released)[0], 'a released reservation is not outstanding');
    }

    #[Test]
    public function open_draft_documents_block_deactivation(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $other = F::warehouse();

        $adjusted = self::catalogItem();
        app(StockAdjustmentService::class)->createDraft(['adjustment_number' => 'DG-ADJ', 'warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'],
            [['item_id' => $adjusted->id, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '2']]);
        $this->assertRefused($adjusted, 'open_stock_adjustments');

        $counted = self::catalogItem();
        app(StockCountService::class)->createDraft(['count_number' => 'DG-CNT', 'warehouse_id' => $warehouse->id],
            [['item_id' => $counted->id, 'system_qty' => '0', 'counted_qty' => '1']]);
        $this->assertRefused($counted, 'open_stock_counts');

        $opened = self::catalogItem();
        app(OpeningStockService::class)->createDraft(['entry_number' => 'DG-OS', 'warehouse_id' => $warehouse->id],
            [['item_id' => $opened->id, 'quantity' => '1', 'unit_cost' => '1']]);
        $this->assertRefused($opened, 'open_opening_stock_entries');

        $received = self::catalogItem();
        app(GoodsReceiptService::class)->createDraft(['grn_number' => 'DG-GRN', 'warehouse_id' => $warehouse->id],
            [['item_id' => $received->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '4']]);
        $this->assertRefused($received, 'open_goods_receipts');

        $transferred = self::catalogItem();
        app(StockTransferService::class)->createDraft(['from_warehouse_id' => $warehouse->id, 'to_warehouse_id' => $other->id],
            [['item_id' => $transferred->id, 'quantity' => '2']]);
        $this->assertRefused($transferred, 'open_stock_transfers');
    }

    #[Test]
    public function zero_stock_and_no_open_operations_allows_deactivation_and_reactivation(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = self::catalogItem();
        // Stock came in and went out: balances exist but are exactly zero everywhere, documents are posted.
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'DG-ZERO-OS', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '1']]));
        $adjustments = app(StockAdjustmentService::class);
        $adjustments->post($adjustments->createDraft(['adjustment_number' => 'DG-ZERO-ADJ', 'warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '2']]));
        $this->assertSame('0.000000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));

        [$status] = $this->deactivate($item);
        $this->assertSame(200, $status);
        $this->assertFalse((bool) $item->fresh()->is_active);
        // Reactivation and edits of an already-inactive item are never blocked by the guard.
        $this->assertSame(200, app(ItemController::class)->update($this->updateRequest($item->fresh(), ['is_active' => true]), $item->fresh())->getStatusCode());
        $this->assertTrue((bool) $item->fresh()->is_active);
    }

    #[Test]
    public function bulk_deactivation_is_all_or_nothing(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $clean = self::catalogItem();
        $stocked = self::catalogItem();
        $negative = self::catalogItem();
        $this->balance($stocked, $warehouse->id, '5');
        $this->balance($negative, $warehouse->id, '-1');
        $ids = [$clean->id, $stocked->id, $negative->id];

        $response = app(ItemController::class)->bulkUpdate(Request::create('/x', 'POST', ['item_ids' => $ids, 'is_active' => false]));
        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('bulk_deactivation_blocked', $body['error']['code']);
        $this->assertEqualsCanonicalizing([$stocked->id, $negative->id], array_column($body['error']['items'], 'id'));
        foreach ($ids as $id) {
            $this->assertTrue((bool) Item::query()->find($id)->is_active, 'nothing may be written');
        }
        $this->assertSame('5.000000', (string) StockBalance::query()->where('item_id', $stocked->id)->value('on_hand_qty'));
        $this->assertSame('-1.000000', (string) StockBalance::query()->where('item_id', $negative->id)->value('on_hand_qty'));

        $response = app(ItemController::class)->bulkUpdate(Request::create('/x', 'POST', ['item_ids' => [$clean->id], 'is_active' => false]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse((bool) $clean->fresh()->is_active);
        // Non-deactivation bulk edits are unaffected by stock.
        $this->assertSame(200, app(ItemController::class)->bulkUpdate(Request::create('/x', 'POST', ['item_ids' => [$stocked->id], 'reorder_point' => 1]))->getStatusCode());
    }
}
