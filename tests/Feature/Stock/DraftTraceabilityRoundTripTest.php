<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockTransferService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Editing a draft resubmits the lot/serial ids that the detail endpoint now
 * exposes (with codes for display); the draft keeps its traceability and posts.
 */
class DraftTraceabilityRoundTripTest extends TestCase
{
    use TenantAware;

    /** What the edit forms send back for a loaded line. */
    private function resubmit(array $line, array $extra): array
    {
        return $extra + ['item_id' => $line['item_id'], 'lot_id' => $line['lot_id'], 'serial_id' => $line['serial_id']];
    }

    #[Test]
    public function adjustment_draft_edit_keeps_captured_lot_and_serial(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'RT-ADJ-WH']);
        $lotItem = F::lotItem(['sku' => 'RT-ADJ-LOT']);
        $serialItem = F::serialItem(['sku' => 'RT-ADJ-SER']);
        $service = app(StockAdjustmentService::class);
        $adjustment = $service->createDraft(['adjustment_number' => 'RT-ADJ', 'warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'], [
            ['item_id' => $lotItem->id, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '2', 'lot_code' => 'LOT-RT-1', 'expiry_date' => '2030-01-31'],
            ['item_id' => $serialItem->id, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '5', 'serials' => ['SER-RT-1']],
        ]);

        $lines = app(StockAdjustmentController::class)->show($adjustment)->getData(true)['data']['adjustment']['lines'];
        $byItem = collect($lines)->keyBy('item_id');
        $this->assertSame('LOT-RT-1', $byItem[$lotItem->id]['lot_code']);
        $this->assertSame('2030-01-31', $byItem[$lotItem->id]['lot_expiry_date']);
        $this->assertSame('SER-RT-1', $byItem[$serialItem->id]['serial']);

        $service->updateDraft($adjustment, ['warehouse_id' => $warehouse->id, 'reason_code' => 'FOUND'], array_map(
            fn ($l) => $this->resubmit($l, ['direction' => 'increase', 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']]), $lines));
        $fresh = $adjustment->fresh('lines')->lines->keyBy('item_id');
        $this->assertSame($byItem[$lotItem->id]['lot_id'], (int) $fresh[$lotItem->id]->lot_id);
        $this->assertSame($byItem[$serialItem->id]['serial_id'], (int) $fresh[$serialItem->id]->serial_id);
        $this->assertSame('posted', $service->post($adjustment->fresh())->status);
    }

    #[Test]
    public function goods_receipt_draft_edit_keeps_lot_expiry_and_serial(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'RT-GRN-WH']);
        $lotItem = F::lotItem(['sku' => 'RT-GRN-LOT']);
        $serialItem = F::serialItem(['sku' => 'RT-GRN-SER']);
        $service = app(GoodsReceiptService::class);
        $grn = $service->createDraft(['grn_number' => 'RT-GRN', 'warehouse_id' => $warehouse->id], [
            ['item_id' => $lotItem->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '4', 'lot_code' => 'LOT-RT-G', 'expiry_date' => '2031-05-01'],
            ['item_id' => $serialItem->id, 'received_qty' => '1', 'accepted_qty' => '1', 'unit_cost' => '9', 'serials' => ['SER-RT-G']],
        ]);

        $lines = app(GoodsReceiptController::class)->show($grn)->getData(true)['data']['grn']['lines'];
        $byItem = collect($lines)->keyBy('item_id');
        $this->assertSame('LOT-RT-G', $byItem[$lotItem->id]['lot_code']);
        $this->assertSame('2031-05-01', $byItem[$lotItem->id]['lot_expiry_date']);
        $this->assertSame('SER-RT-G', $byItem[$serialItem->id]['serial']);

        $service->updateDraft($grn, ['warehouse_id' => $warehouse->id], array_map(fn ($l) => $this->resubmit($l, [
            'received_qty' => $l['received_qty'], 'accepted_qty' => $l['accepted_qty'], 'unit_cost' => $l['unit_cost'], 'expiry_date' => $l['lot_expiry_date'],
        ]), $lines));
        $fresh = $grn->fresh('lines')->lines->keyBy('item_id');
        $this->assertSame($byItem[$lotItem->id]['lot_id'], (int) $fresh[$lotItem->id]->lot_id);
        $this->assertSame($byItem[$serialItem->id]['serial_id'], (int) $fresh[$serialItem->id]->serial_id);
        $service->post($grn->fresh());
        $this->assertSame('posted', $grn->fresh()->status);
    }

    #[Test]
    public function transfer_draft_edit_keeps_lot(): void
    {
        $this->useTenantA();
        $from = F::warehouse(['code' => 'RT-TRF-A']);
        $to = F::warehouse(['code' => 'RT-TRF-B']);
        $item = F::lotItem(['sku' => 'RT-TRF-LOT']);
        $lot = F::lot($item);
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'RT-TRF-OS', 'warehouse_id' => $from->id], [['item_id' => $item->id, 'lot_id' => $lot->id, 'quantity' => '5', 'unit_cost' => '1']]));
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id], [['item_id' => $item->id, 'quantity' => '2', 'lot_id' => $lot->id]]);

        $lines = app(StockTransferController::class)->show($transfer)->getData(true)['data']['transfer']['lines'];
        $this->assertSame($lot->lot_code, $lines[0]['lot_code']);
        $service->updateDraft($transfer, ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id], array_map(fn ($l) => $this->resubmit($l, ['quantity' => $l['quantity']]), $lines));
        $this->assertSame($lot->id, (int) $transfer->fresh('lines')->lines->first()->lot_id);
    }
}
