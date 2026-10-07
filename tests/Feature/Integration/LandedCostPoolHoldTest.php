<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{PurchaseValuationHold, Shipment, StockBalance, StockLedger};
use App\Services\Documents\GoodsReceiptService;
use App\Services\Purchasing\PurchaseValuationHoldService;
use App\Services\Stock\{StockLedgerService, StockMovement};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class LandedCostPoolHoldTest extends TestCase
{
    use TenantAware;

    public function test_fully_consumed_landed_source_still_holds_native_pool_until_explicit_release(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->createDraft(['warehouse_id' => $warehouse->id, 'receipt_date' => '2026-10-07'],
            [['item_id' => $item->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '5']]);
        $receipts->post($receipt);
        app(StockLedgerService::class)->post([new StockMovement(direction: 'out', itemId: $item->id,
            warehouseId: $warehouse->id, quantity: '2', sourceType: Shipment::class,
            sourceId: 810, sourceLineId: 811, movedAt: '2026-10-07')], 'landed-consumed-source');
        $holds = app(PurchaseValuationHoldService::class);
        $hold = DB::connection('tenant')->transaction(function () use ($holds, $item, $warehouse, $receipt) {
            $holds->lockItems([$item->id]);
            return $holds->acquire(['settlement_uuid' => (string) Str::uuid(), 'purpose' => 'landed_apply',
                'plan_revision' => 1, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
                'receipt_id' => $receipt->id, 'source_bill_id' => 800], str_repeat('a', 64));
        });
        $incoming = $receipts->createDraft(['warehouse_id' => $warehouse->id, 'receipt_date' => '2026-10-07'],
            [['item_id' => $item->id, 'received_qty' => '1', 'accepted_qty' => '1', 'unit_cost' => '9']]);
        try {
            $receipts->post($incoming);
            $this->fail('A fully consumed source must still protect the valuation pool.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame(2, StockLedger::count());
        $this->assertEquals(0, StockBalance::sole()->on_hand_qty);
        $this->assertSame('draft', $incoming->fresh()->status);
        $this->assertSame('active', $hold->fresh()->state);
        $hold->update(['state' => 'released']);
        $receipts->post($incoming->fresh());
        $this->assertSame(3, StockLedger::count());
        $this->assertSame('1.0000', StockBalance::sole()->on_hand_qty);
    }

    public function test_unrelated_landed_reverse_operations_on_same_bill_cannot_share_hold_waiver(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $holds = app(PurchaseValuationHoldService::class);
        $scope = ['settlement_uuid' => (string) Str::uuid(), 'purpose' => 'landed_reverse',
            'plan_revision' => 1, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'receipt_id' => 1, 'source_bill_id' => 800];
        DB::connection('tenant')->transaction(function () use ($holds, $scope, $item) {
            $holds->lockItems([$item->id]);
            $holds->acquire($scope, str_repeat('a', 64));
        });
        try {
            DB::connection('tenant')->transaction(fn () => $holds->acquire(array_replace($scope,
                ['settlement_uuid' => (string) Str::uuid()]), str_repeat('b', 64)));
            $this->fail('Different landed operations must serialize even on the same Bill.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_id', $exception->errors());
        }
        $this->assertSame(1, PurchaseValuationHold::count());
        $holds->assertMovable($item->id, $warehouse->id, $scope + ['plan_fingerprint' => str_repeat('a', 64)]);
        $this->assertSame(0, StockLedger::count());
    }
}
