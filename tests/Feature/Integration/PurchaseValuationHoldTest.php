<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\PurchaseValuationHold;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\SupplierReturn;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class PurchaseValuationHoldTest extends TestCase
{
    use TenantAware;

    public function test_durable_quote_hold_blocks_native_shipment_transfer_return_and_reversal_until_release(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $item->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        $hold = DB::connection('tenant')->transaction(function () use ($item, $warehouse, $receipt) {
            $service = app(PurchaseValuationHoldService::class);
            $service->lockItems([$item->id]);

            return $service->acquire(['settlement_uuid' => (string) Str::uuid(), 'purpose' => 'apply', 'plan_revision' => 1,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'receipt_id' => $receipt->id,
                'source_bill_id' => 812], str_repeat('a', 64));
        });
        foreach ([Shipment::class, StockTransfer::class,
            SupplierReturn::class] as $index => $source) {
            try {
                app(StockLedgerService::class)->post([new StockMovement(direction: 'out', itemId: $item->id,
                    warehouseId: $warehouse->id, quantity: '1', sourceType: $source, sourceId: 900 + $index,
                    sourceLineId: 901 + $index, movedAt: '2026-10-06')], 'blocked-hold-'.$index);
                $this->fail('Held pool moved');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('item_id', $exception->errors());
            }
        }
        try {
            app(StockLedgerService::class)->reverse('goods_receipt:'.$receipt->id.':post', 'hold-reverse-blocked');
            $this->fail('Held source reversed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame('10.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('50.00', StockBalance::sole()->total_value);
        $this->assertSame(1, StockLedger::count());
        $this->assertSame('active', PurchaseValuationHold::sole()->state);
        $hold->update(['state' => 'released']);
        app(StockLedgerService::class)->post([new StockMovement(direction: 'out', itemId: $item->id,
            warehouseId: $warehouse->id, quantity: '1', sourceType: Shipment::class,
            sourceId: 920, sourceLineId: 921, movedAt: '2026-10-06')], 'after-released-hold');
        $this->assertSame('9.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame(2, StockLedger::count());
    }

    public function test_same_bill_reverse_previews_can_group_but_physical_movements_still_block(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $service = app(PurchaseValuationHoldService::class);
        DB::connection('tenant')->transaction(function () use ($service, $warehouse, $item) {
            $service->lockItems([$item->id]);
            foreach ([1, 2] as $receipt) {
                $service->acquire(['settlement_uuid' => (string) Str::uuid(), 'purpose' => 'reverse', 'plan_revision' => 1,
                    'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'receipt_id' => $receipt,
                    'source_bill_id' => 812], str_repeat((string) $receipt, 64));
            }
        });
        $this->assertSame(2, PurchaseValuationHold::count());
        $this->expectException(ValidationException::class);
        $service->assertMovable($item->id, $warehouse->id);
    }

    public function test_other_pool_remains_usable_and_competing_hold_is_rejected(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $other = F::averageItem(['sku' => 'HOLD-OTHER']);
        DB::connection('tenant')->transaction(function () use ($warehouse, $item) {
            $service = app(PurchaseValuationHoldService::class);
            $service->lockItems([$item->id]);
            $service->acquire(['settlement_uuid' => (string) Str::uuid(), 'purpose' => 'reverse', 'plan_revision' => 1,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'receipt_id' => 7, 'source_bill_id' => 8], str_repeat('b', 64));
        });
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id],
            [['item_id' => $other->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        $this->assertSame(1, StockLedger::count());
        $this->expectException(ValidationException::class);
        DB::connection('tenant')->transaction(fn () => app(PurchaseValuationHoldService::class)->acquire([
            'settlement_uuid' => (string) Str::uuid(), 'purpose' => 'apply', 'plan_revision' => 1,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'receipt_id' => 7, 'source_bill_id' => 9], str_repeat('c', 64)));
    }
}
