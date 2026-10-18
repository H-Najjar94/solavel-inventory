<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\OpeningStockController;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\InternalConsumptionLine;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\OpeningStockEntryLine;
use App\Models\Tenant\PickListLine;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\ReceivingRequestLine;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\SalesReturnLine;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\ShipmentLine;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockAdjustmentLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockCountLine;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransferLine;
use App\Models\Tenant\SupplierReturnLine;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockCountService;
use App\Services\Documents\StockTransferService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\StockReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Q12.3 / Q12.6 (owner decisions 2026-10-18, requirement 3): inactive items get no new stock documents,
 * reservations or ordinary movements on any server path; only reversals of posted documents and a
 * controlled write-off out of positive stock remain allowed.
 */
class InactiveItemPolicyTest extends TestCase
{
    use TenantAware;

    /** An identified actor whose permission decision is fixed (corrections of inactive items need one). */
    private function actor(bool $allowed = true): void
    {
        $user = new \App\Models\User;
        $user->id = 323;
        \Illuminate\Support\Facades\Auth::setUser($user);
        request()->setUserResolver(fn () => $user);
        $this->mock(\App\Services\Access\InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->andReturn($allowed));
        $scope = $this->createStub(\App\Services\Access\WarehouseAccessService::class);
        $scope->method('scope')->willReturnCallback(fn ($q) => $q);
        $this->app->instance(\App\Services\Access\WarehouseAccessService::class, $scope);
    }

    /** Q12.6 (G6): another permitted person approves the draft write-off; the original actor stays the poster. */
    private function secondPersonApproves(StockAdjustment $adjustment): void
    {
        $poster = \Illuminate\Support\Facades\Auth::user();
        $approver = new \App\Models\User;
        $approver->id = 324;
        \Illuminate\Support\Facades\Auth::setUser($approver);
        request()->setUserResolver(fn () => $approver);
        app(\App\Services\Stock\InactiveItemWriteOffApproval::class)->approve($adjustment);
        \Illuminate\Support\Facades\Auth::setUser($poster);
        request()->setUserResolver(fn () => $poster);
    }

    private function item(): Item
    {
        return F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
    }

    /** Legacy / already-inactive item (deactivated before the guard, or after its stock went to zero). */
    private function forceInactive(Item $item): void
    {
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
    }

    private function assertRefused(callable $write, int $itemId, string $message = 'inventory.catalog.inactive_item_rejected'): void
    {
        try {
            $write();
            $this->fail('An inactive item was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(__($message), $e->errors()['item_id'][0] ?? null, json_encode($e->errors()));
            $this->assertSame([$itemId], $e->errors()['inactive_item_ids'] ?? null);
        }
    }

    private function stock(Item $item, int $warehouseId, string $qty): void
    {
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $warehouseId], [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => '2']]));
    }

    #[Test]
    public function every_guarded_document_line_and_reservation_model_refuses_a_new_row_for_an_inactive_item(): void
    {
        $this->useTenantA();
        $item = $this->item();
        $this->forceInactive($item);
        // InternalConsumptionLine's own saving hook needs a draft parent before the guard runs.
        $consumption = DB::connection('tenant')->table('internal_consumptions')->insertGetId(['organization_id' => TenantTestManager::ORG_A,
            'document_number' => 'Q12-IC', 'document_date' => '2026-10-18', 'warehouse_id' => F::warehouse()->id, 'status' => 'draft',
            'reason' => 'q12', 'submission_key' => (string) \Illuminate\Support\Str::uuid(), 'submission_hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now()]);
        // The guard runs before the INSERT, whatever service, controller, import or handoff builds the row.
        foreach ([StockAdjustmentLine::class => ['direction' => 'increase'], StockTransferLine::class => [],
            OpeningStockEntryLine::class => [], GoodsReceiptLine::class => [], ShipmentLine::class => [], InternalConsumptionLine::class => ['internal_consumption_id' => $consumption],
            SupplierReturnLine::class => [], SalesReturnLine::class => [], PurchaseOrderLine::class => [], ReceivingRequestLine::class => [],
            PickListLine::class => [], Reservation::class => []] as $class => $extra) {
            $this->assertRefused(fn () => (new $class(['item_id' => $item->id] + $extra))->save(), $item->id);
        }
    }

    #[Test]
    public function re_pointing_an_existing_draft_line_to_an_inactive_item_is_refused(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $active = $this->item();
        $inactive = $this->item();
        $this->forceInactive($inactive);
        $draft = app(StockAdjustmentService::class)->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'],
            [['item_id' => $active->id, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '1']]);
        $line = $draft->lines()->sole();
        $this->assertRefused(fn () => $line->forceFill(['item_id' => $inactive->id])->save(), $inactive->id);
        $this->assertSame($active->id, (int) $line->fresh()->item_id);
        // Editing other fields of a line is unaffected.
        $line->refresh()->forceFill(['notes' => 'edited'])->save();
        $this->assertSame('edited', $line->fresh()->notes);
    }

    #[Test]
    public function document_services_refuse_new_drafts_for_inactive_items_and_write_nothing(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $other = F::warehouse();
        $item = $this->item();
        $this->forceInactive($item);
        $this->assertRefused(fn () => app(StockAdjustmentService::class)->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'],
            [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '2']]), $item->id);
        $this->assertRefused(fn () => app(StockTransferService::class)->createDraft(['from_warehouse_id' => $warehouse->id, 'to_warehouse_id' => $other->id],
            [['item_id' => $item->id, 'quantity' => '1']]), $item->id);
        $this->assertRefused(fn () => app(OpeningStockService::class)->createDraft(['warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '1', 'unit_cost' => '1']]), $item->id);
        $this->assertRefused(fn () => app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '4']]), $item->id);
        // Reservation against existing (legacy) stock of an inactive item.
        $stocked = $this->item();
        $this->stock($stocked, $warehouse->id, '2');
        $this->forceInactive($stocked);
        $this->assertRefused(fn () => app(StockReservationService::class)->reserve($stocked->id, $warehouse->id, '1', 'sales_order', 1), $stocked->id);
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $stocked->id)->value('reserved_qty'));
        // Nothing was written for the refused items (scoped by item: committed fixtures of other classes may exist).
        foreach (['stock_adjustment_lines', 'stock_transfer_lines', 'opening_stock_entry_lines', 'goods_receipt_lines', 'reservations'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->where('item_id', $item->id)->count(), $table.' must be rolled back');
        }
        $this->assertSame(0, DB::connection('tenant')->table('reservations')->where('item_id', $stocked->id)->count());
    }

    #[Test]
    public function the_ledger_refuses_ordinary_movements_for_an_inactive_item_even_from_an_older_draft(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = $this->item();
        $opening = app(OpeningStockService::class);
        $legacyDraft = $opening->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '2']]);
        $this->forceInactive($item);
        $this->assertRefused(fn () => $opening->post($legacyDraft), $item->id);
        $this->assertSame('draft', $legacyDraft->fresh()->status);
        $this->assertSame(0, StockLedger::query()->where('item_id', $item->id)->count());

        // Inbound and non-adjustment outbound movements are refused at the ledger (shipment/transfer/consumption sources).
        $this->assertRefused(fn () => app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $warehouse->id, '1', OpeningStockEntry::class, 1, unitCost: '1')], 'q12-inactive-in'), $item->id);
        $this->assertRefused(fn () => app(StockLedgerService::class)->post([new StockMovement('out', $item->id, $warehouse->id, '1', Shipment::class, 1)], 'q12-inactive-out'), $item->id);
        $this->assertSame(0, StockLedger::query()->where('item_id', $item->id)->count());
    }

    #[Test]
    public function opening_stock_csv_import_refuses_an_existing_inactive_sku(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = $this->item();
        $this->forceInactive($item);
        $path = tempnam(sys_get_temp_dir(), 'q12csv');
        file_put_contents($path, "sku,name,quantity,unit_cost\n{$item->sku},{$item->name},5,2\n");
        $request = Request::create('/api/v1/opening-stock/import', 'POST', ['warehouse_id' => $warehouse->id], [], [
            'file' => new UploadedFile($path, 'opening.csv', 'text/csv', null, true),
        ]);
        $this->assertRefused(fn () => app(OpeningStockController::class)->importCsv($request), $item->id);
        @unlink($path);
        $this->assertSame(0, DB::connection('tenant')->table('opening_stock_entry_lines')->where('item_id', $item->id)->count());
        $this->assertSame(0, StockLedger::query()->where('item_id', $item->id)->count());
    }

    #[Test]
    public function a_stock_count_of_an_inactive_item_posts_as_the_controlled_correction_up_or_down_and_is_audited(): void
    {
        $this->useTenantA();
        $this->actor();
        $warehouse = F::warehouse();
        $up = $this->item();
        $down = $this->item();
        $this->stock($up, $warehouse->id, '2');
        $this->stock($down, $warehouse->id, '5');
        $this->forceInactive($up);
        $this->forceInactive($down);
        $counts = app(StockCountService::class);
        // Count drafts of inactive items are allowed (owner adjustment (c)).
        $raise = $counts->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $up->id, 'system_qty' => '2', 'counted_qty' => '3']]);
        $lower = $counts->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $down->id, 'system_qty' => '5', 'counted_qty' => '0']]);
        $counts->post($raise);
        $counts->post($lower);
        $this->assertSame('3.0000', (string) StockBalance::query()->where('item_id', $up->id)->value('on_hand_qty'));
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $down->id)->value('on_hand_qty'));
        $audits = InventoryAuditLog::query()->where('action', 'inactive_item.corrective_movement')->get()
            ->filter(fn ($a) => ($a->before['kind'] ?? null) === 'stock_count' && in_array((int) $a->before['item_id'], [$up->id, $down->id], true));
        $this->assertEqualsCanonicalizing([$up->id, $down->id], $audits->map(fn ($a) => $a->before['item_id'])->values()->all());
        $this->assertTrue($audits->every(fn ($a) => $a->before['item_inactive'] === true));
        // Outside a count posting, the same increase is an ordinary movement and stays refused.
        $this->assertRefused(fn () => app(StockAdjustmentService::class)->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'],
            [['item_id' => $up->id, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '1']]), $up->id);
    }

    #[Test]
    public function an_authorized_write_off_reduces_existing_positive_stock_of_an_inactive_item_and_is_audited(): void
    {
        $this->useTenantA();
        $this->actor();
        InventorySetting::updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['allow_negative_stock' => true]);
        $warehouse = F::warehouse();
        $item = $this->item();
        $this->stock($item, $warehouse->id, '5');
        $this->forceInactive($item);
        $adjustments = app(StockAdjustmentService::class);

        $writeOff = $adjustments->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '3']]);
        $this->secondPersonApproves($writeOff); // Q12.6 (G6): inactive write-offs need a second-person approval
        $adjustments->post($writeOff);
        $this->assertSame('2.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $audit = InventoryAuditLog::query()->where('action', 'stock_adjustment.inactive_item_write_off')->sole();
        $this->assertSame($writeOff->id, (int) $audit->entity_id);
        $this->assertSame('5.0000', $audit->before['on_hand_qty']);
        $this->assertSame('2.0000', $audit->after['on_hand_qty']);

        // Never more than the positive stock on hand: refused at draft ...
        $this->assertRefused(fn () => $adjustments->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '2.0001']]), $item->id, 'inventory.catalog.inactive_write_off_exceeds_stock');
        // ... and re-checked under the balance lock at posting, even with negative stock enabled.
        $split = $adjustments->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1.5'], ['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1.5']]);
        $this->secondPersonApproves($split);
        $this->assertRefused(fn () => $adjustments->post($split), $item->id, 'inventory.catalog.inactive_write_off_exceeds_stock');
        $this->assertSame('2.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame('draft', $split->fresh()->status);

        // A negative balance is not disguised by a write-off.
        $negative = $this->item();
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $negative->id, 'warehouse_id' => $warehouse->id, 'on_hand_qty' => '-2', 'reserved_qty' => '0', 'total_value' => '0']);
        $this->forceInactive($negative);
        $this->assertRefused(fn () => $adjustments->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $negative->id, 'direction' => 'decrease', 'quantity' => '1']]), $negative->id, 'inventory.catalog.inactive_write_off_exceeds_stock');
    }

    #[Test]
    public function reversals_of_posted_documents_still_work_for_an_inactive_item(): void
    {
        $this->useTenantA();
        $this->actor();
        $warehouse = F::warehouse();
        $item = $this->item();
        $opening = app(OpeningStockService::class);
        $entry = $opening->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '2']]);
        $opening->post($entry);
        $adjustments = app(StockAdjustmentService::class);
        $writeOff = $adjustments->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1']]);
        $adjustments->post($writeOff);
        $this->forceInactive($item);

        // Reversal of the posted write-off restores the quantity (exact inverse rows, not a new receipt).
        app(InventoryReversalService::class)->reverseNegativeAdjustment($writeOff->fresh(), 'Wrong write-off');
        $this->assertSame('4.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertFalse((bool) $item->fresh()->is_active);

        // A posted opening entry of an inactive item can be reversed back to zero.
        $other = $this->item();
        $second = $opening->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $other->id, 'quantity' => '3', 'unit_cost' => '2']]);
        $opening->post($second);
        $this->forceInactive($other);
        $opening->reverse($second->fresh(), 'Opening entered twice');
        $this->assertSame('0.0000', (string) StockBalance::query()->where('item_id', $other->id)->value('on_hand_qty'));
        $this->assertFalse((bool) $other->fresh()->is_active);
    }

    #[Test]
    public function a_reactivated_item_moves_normally_again(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = $this->item();
        $this->forceInactive($item);
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => true]);
        $this->stock($item, $warehouse->id, '3');
        $this->assertSame('3.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }
}
