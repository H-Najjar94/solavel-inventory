<?php

namespace Tests\Feature\Stock;

use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\InventorySetting;
use Illuminate\Validation\ValidationException;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** SC-UAE-042: service / non-inventory items never carry stock movements. */
class NonStockItemMovementTest extends TestCase
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
    public function posting_stock_for_a_service_or_non_inventory_item_is_rejected(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $os = app(OpeningStockService::class);

        foreach (['service', 'non_inventory'] as $type) {
            $item = F::item(['item_type' => $type, 'tracking_type' => 'none']);
            $draft = $os->createDraft(['entry_number' => 'SVC-'.$type, 'warehouse_id' => $wh->id],
                [['item_id' => $item->id, 'quantity' => '1.0000', 'unit_cost' => '10.0000']]);
            try {
                $os->post($draft);
                $this->fail("Stock posted for a {$type} item.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString((string) $item->sku, $e->getMessage());
            }
            $this->assertFalse(StockBalance::query()->where('item_id', $item->id)->exists());
        }
    }

    #[Test]
    public function adjustment_and_transfer_drafts_reject_non_inventory_items_on_save(): void
    {
        $this->boot();
        InventorySetting::query()->where('organization_id', TenantTestManager::ORG_A)->update(['adjustment_reason_codes' => null]);
        $wh = F::warehouse();
        $other = F::warehouse();
        $service = F::item(['item_type' => 'service', 'tracking_type' => 'none']);
        $stock = F::item();

        $payloads = [
            \App\Http\Requests\Api\StoreGoodsReceiptRequest::class => fn ($itemId) => ['warehouse_id'=>$wh->id,'lines'=>[['item_id'=>$itemId,'received_qty'=>'1']]],
            \App\Http\Requests\Api\StoreOpeningStockRequest::class => fn ($itemId) => ['warehouse_id'=>$wh->id,'lines'=>[['item_id'=>$itemId,'quantity'=>'1','unit_cost'=>'1']]],
            StoreStockAdjustmentRequest::class => fn ($itemId) => ['warehouse_id' => $wh->id, 'lines' => [['item_id' => $itemId, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '1']]],
            StoreStockTransferRequest::class => fn ($itemId) => ['from_warehouse_id' => $wh->id, 'to_warehouse_id' => $other->id, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]],
        ];
        foreach ($payloads as $class => $payload) {
            $request = $class::create('/', 'POST', $payload($service->id));
            $request->setContainer(app())->setRedirector(app('redirect'));
            try {
                $request->validateResolved();
                $this->fail("{$class} accepted a service item.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0.item_id', $e->errors());
                $this->assertStringContainsString((string) $service->sku, $e->errors()['lines.0.item_id'][0]);
            }

            $ok = $class::create('/', 'POST', $payload($stock->id));
            $ok->setContainer(app())->setRedirector(app('redirect'));
            try {
                $ok->validateResolved();
            } catch (ValidationException $e) {
                $this->assertArrayNotHasKey('lines.0.item_id', $e->errors(), "{$class} rejected an inventory item.");
            }
        }
    }

    #[Test]
    public function inventory_items_still_post_normally(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::item();
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'INV-OK', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '2.0000', 'unit_cost' => '5.0000']]));
        $this->assertTrue(StockBalance::query()->where('item_id', $item->id)->exists());
    }
    #[Test]
    public function type_controls_new_item_tracking_and_sale_purchase_remain_independent(): void
    {
        $this->boot();
        foreach (['inventory', 'non_inventory', 'service'] as $type) {
            foreach ([true, false] as $sale) {
                foreach ([true, false] as $purchase) {
                    $item = F::item(['item_type'=>$type, 'available_for_sale'=>$sale, 'available_for_purchase'=>$purchase]);
                    $this->assertSame($type === 'inventory', $item->fresh()->track_inventory);
                    $this->assertSame($sale, $item->fresh()->available_for_sale);
                    $this->assertSame($purchase, $item->fresh()->available_for_purchase);
                }
            }
        }
    }

    #[Test]
    public function contradictory_tracking_is_rejected_without_reclassifying_the_item(): void
    {
        $this->boot();
        foreach (['inventory', 'non_inventory', 'service'] as $type) {
            $item = F::item(['item_type'=>$type]);
            try {
                $item->update(['track_inventory'=>$type !== 'inventory']);
                $this->fail('Contradictory inventory tracking accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('track_inventory', $e->errors());
            }
            $this->assertSame($type, $item->fresh()->item_type);
        }
    }

    #[Test]
    public function legacy_inconsistency_and_null_are_preserved_during_unrelated_updates(): void
    {
        $this->boot();
        foreach ([false, null] as $legacy) {
            $item = F::item();
            // Simulate an existing tenant row; no migration/backfill changes it.
            \Illuminate\Support\Facades\DB::connection('tenant')->table('items')->where('id',$item->id)->update(['track_inventory'=>$legacy]);
            $item->refresh()->update(['name'=>'Unrelated edit']);
            $this->assertSame($legacy, $item->fresh()->track_inventory);
            $this->assertSame('inventory', $item->fresh()->item_type);
        }
    }

    #[Test]
    public function tracking_type_changes_are_blocked_for_history_and_negative_balance(): void
    {
        $this->boot();
        $warehouse = F::warehouse();
        $item = F::item();
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number'=>'TYPE-HISTORY','warehouse_id'=>$warehouse->id],
            [['item_id'=>$item->id,'quantity'=>'1','unit_cost'=>'2']]));
        $balanceOnly = F::item();
        StockBalance::create(['organization_id'=>TenantTestManager::ORG_A,'item_id'=>$balanceOnly->id,
            'warehouse_id'=>$warehouse->id,'on_hand_qty'=>'-1','total_value'=>'-2']);
        foreach ([$item,$balanceOnly] as $protected) {
            try {
                $protected->update(['item_type'=>'non_inventory']);
                $this->fail('A tracked item with stock/history was reclassified.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('item_type',$e->errors());
            }
            $this->assertSame('inventory',$protected->fresh()->item_type);
        }
        $unused = F::item();
        $unused->update(['item_type'=>'service']);
        $this->assertFalse($unused->fresh()->track_inventory);
        $unused->update(['item_type'=>'inventory']);
        $this->assertTrue($unused->fresh()->track_inventory);
    }

}
