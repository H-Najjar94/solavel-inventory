<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Requests\Api\StoreGoodsReceiptRequest;
use App\Http\Requests\Api\StoreSalesOrderRequest;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\StoreStockCountRequest;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockCount;
use App\Models\Tenant\StockTransfer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * P1 correctness — the three document creates that still required a MANUAL number
 * (Transfer, Adjustment, GRN). Mirrors the fixed PO/Opening-Stock pattern: a blank
 * number is server-generated (nullable rule + prepareForValidation blanking +
 * DocumentNumber::next inside the create transaction), a typed number is respected,
 * and numbers increment per org. Before this fix a blank number 422'd (required) or
 * — if it slipped past validation — hit the NOT-NULL column (1364).
 */
class DocumentNumberingCreateTest extends TestCase
{
    use TenantAware;

    private int $whA;

    private int $whB;

    private int $item;

    private function seedDocs(): void
    {
        $this->whA = F::warehouse()->id;
        $this->whB = F::warehouse()->id;
        $this->item = F::item()->id;
    }

    private function resolve(string $requestClass, array $payload)
    {
        $req = $requestClass::create('/api/v1', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return $req;
    }

    // ── Stock Transfer ──

    private function transferPayload(array $over = []): array
    {
        return array_merge([
            'transfer_number' => '', 'transfer_date' => '',
            'from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'notes' => '',
            'lines' => [['item_id' => $this->item, 'quantity' => '2.0000']],
        ], $over);
    }

    #[Test]
    public function transfer_create_with_blank_number_auto_generates(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $req = $this->resolve(StoreStockTransferRequest::class, $this->transferPayload());
        $resp = app(StockTransferController::class)->store($req)->getData(true);

        $this->assertSame('TRF-000001', $resp['data']['transfer_number']);
        $t = StockTransfer::query()->where('transfer_number', 'TRF-000001')->firstOrFail();
        $this->assertSame('draft', $t->status);
        $this->assertNotNull($t->transfer_date, 'blank transfer_date defaulted, did not crash');
    }

    #[Test]
    public function transfer_numbers_increment_and_a_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        app(StockTransferController::class)->store($this->resolve(StoreStockTransferRequest::class, $this->transferPayload()));   // TRF-000001
        app(StockTransferController::class)->store($this->resolve(StoreStockTransferRequest::class, $this->transferPayload()));   // TRF-000002
        $typed = app(StockTransferController::class)->store(
            $this->resolve(StoreStockTransferRequest::class, $this->transferPayload(['transfer_number' => 'MY-TRF-9']))
        )->getData(true);

        $this->assertSame('MY-TRF-9', $typed['data']['transfer_number']);
        $this->assertNotNull(StockTransfer::query()->where('transfer_number', 'TRF-000002')->first());
    }

    // ── Stock Adjustment ──

    private function adjustmentPayload(array $over = []): array
    {
        return array_merge([
            'adjustment_number' => '', 'adjustment_date' => '',
            'warehouse_id' => $this->whA, 'reason_code' => '', 'notes' => '',
            'lines' => [['item_id' => $this->item, 'direction' => 'increase', 'quantity' => '4.0000', 'unit_cost' => '1.5000']],
        ], $over);
    }

    #[Test]
    public function adjustment_create_with_blank_number_auto_generates(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $req = $this->resolve(StoreStockAdjustmentRequest::class, $this->adjustmentPayload());
        $resp = app(StockAdjustmentController::class)->store($req)->getData(true);

        $this->assertSame('ADJ-000001', $resp['data']['adjustment_number']);
        $adj = StockAdjustment::query()->where('adjustment_number', 'ADJ-000001')->firstOrFail();
        $this->assertSame('draft', $adj->status);
        $this->assertNotNull($adj->adjustment_date, 'blank adjustment_date defaulted, did not crash');
    }

    #[Test]
    public function adjustment_number_increments_and_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        app(StockAdjustmentController::class)->store($this->resolve(StoreStockAdjustmentRequest::class, $this->adjustmentPayload()));   // ADJ-000001
        $typed = app(StockAdjustmentController::class)->store(
            $this->resolve(StoreStockAdjustmentRequest::class, $this->adjustmentPayload(['adjustment_number' => 'COUNT-42']))
        )->getData(true);

        // An explicit ref (e.g. the COUNT- number a stock-count variance passes in) is kept.
        $this->assertSame('COUNT-42', $typed['data']['adjustment_number']);
        $this->assertNotNull(StockAdjustment::query()->where('adjustment_number', 'ADJ-000001')->first());
    }

    // ── Goods Receipt (GRN) ──

    private function grnPayload(array $over = []): array
    {
        return array_merge([
            'grn_number' => '', 'purchase_order_id' => null, 'supplier_id' => null,
            'warehouse_id' => $this->whA, 'receipt_date' => '', 'notes' => '',
            'lines' => [['item_id' => $this->item, 'received_qty' => '6.0000', 'unit_cost' => '3.0000']],
        ], $over);
    }

    #[Test]
    public function grn_create_with_blank_number_auto_generates(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $req = $this->resolve(StoreGoodsReceiptRequest::class, $this->grnPayload());
        $resp = app(GoodsReceiptController::class)->store($req)->getData(true);

        $this->assertSame('GRN-000001', $resp['data']['grn_number']);
        $grn = GoodsReceipt::query()->where('grn_number', 'GRN-000001')->firstOrFail();
        $this->assertSame('draft', $grn->status);
        $this->assertNotNull($grn->receipt_date, 'blank receipt_date defaulted, did not crash');
    }

    #[Test]
    public function grn_number_increments_and_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        app(GoodsReceiptController::class)->store($this->resolve(StoreGoodsReceiptRequest::class, $this->grnPayload()));   // GRN-000001
        $typed = app(GoodsReceiptController::class)->store(
            $this->resolve(StoreGoodsReceiptRequest::class, $this->grnPayload(['grn_number' => 'GRN-CUSTOM']))
        )->getData(true);

        $this->assertSame('GRN-CUSTOM', $typed['data']['grn_number']);
        $this->assertNotNull(GoodsReceipt::query()->where('grn_number', 'GRN-000001')->first());
    }

    // ── Stock Count ──

    private function countPayload(array $over = []): array
    {
        return array_merge([
            'count_number' => '', 'count_type' => 'cycle', 'warehouse_id' => $this->whA, 'notes' => '',
            'lines' => [['item_id' => $this->item, 'system_qty' => '10', 'counted_qty' => '9']],
        ], $over);
    }

    #[Test]
    public function count_create_with_blank_number_auto_generates(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $req = $this->resolve(StoreStockCountRequest::class, $this->countPayload());
        $resp = app(StockCountController::class)->store($req)->getData(true);

        $this->assertSame('CNT-000001', $resp['data']['count_number']);
        $this->assertNotNull(StockCount::query()->where('count_number', 'CNT-000001')->first());
    }

    #[Test]
    public function count_number_increments_and_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        app(StockCountController::class)->store($this->resolve(StoreStockCountRequest::class, $this->countPayload()));   // CNT-000001
        $typed = app(StockCountController::class)->store(
            $this->resolve(StoreStockCountRequest::class, $this->countPayload(['count_number' => 'CYCLE-A']))
        )->getData(true);

        $this->assertSame('CYCLE-A', $typed['data']['count_number']);
        $this->assertNotNull(StockCount::query()->where('count_number', 'CNT-000001')->first());
    }

    // ── Sales Order ──

    private function soPayload(array $over = []): array
    {
        return array_merge([
            'order_number' => '', 'order_date' => '', 'requested_ship_date' => '',
            'warehouse_id' => $this->whA, 'customer_name' => 'Acme', 'notes' => '',
            'lines' => [['item_id' => $this->item, 'ordered_qty' => '3.0000', 'unit_price' => '5.0000']],
        ], $over);
    }

    #[Test]
    public function sales_order_create_with_blank_number_and_blank_dates_auto_generates(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $req = $this->resolve(StoreSalesOrderRequest::class, $this->soPayload());
        $resp = app(SalesOrderController::class)->store($req)->getData(true);

        $this->assertSame('SO-000001', $resp['data']['order_number']);
        $so = SalesOrder::query()->where('order_number', 'SO-000001')->firstOrFail();
        $this->assertSame('draft', $so->status);
        $this->assertNotNull($so->order_date, 'blank order_date defaulted, did not crash');
    }

    #[Test]
    public function sales_order_number_increments_and_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        app(SalesOrderController::class)->store($this->resolve(StoreSalesOrderRequest::class, $this->soPayload()));   // SO-000001
        $typed = app(SalesOrderController::class)->store(
            $this->resolve(StoreSalesOrderRequest::class, $this->soPayload(['order_number' => 'WEB-77']))
        )->getData(true);

        $this->assertSame('WEB-77', $typed['data']['order_number']);
        $this->assertNotNull(SalesOrder::query()->where('order_number', 'SO-000001')->first());
    }

    #[Test]
    public function document_numbers_are_independent_per_org(): void
    {
        $this->useTenantA();
        $this->seedDocs();
        app(StockTransferController::class)->store($this->resolve(StoreStockTransferRequest::class, $this->transferPayload()));

        // Org B starts its own TRF sequence at 000001 (per-org numbering).
        $this->useTenantB();
        $this->seedDocs();
        $b = app(StockTransferController::class)->store(
            $this->resolve(StoreStockTransferRequest::class, $this->transferPayload())
        )->getData(true);

        $this->assertSame('TRF-000001', $b['data']['transfer_number']);
    }
}
