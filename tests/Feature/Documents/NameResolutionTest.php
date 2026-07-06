<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Requests\Api\StoreGoodsReceiptRequest;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Requests\Api\StoreSalesOrderRequest;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\StoreStockCountRequest;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\StockTransfer;
use App\Services\Documents\OpeningStockService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * "Names not IDs" pass — assert list/detail payloads carry RESOLVED item /
 * warehouse / supplier names (org-scoped), not just raw #ids. Covers the two
 * flagship grids (Balances, Ledger) and every document detail. Guards against
 * a regression back to raw-id-only payloads.
 */
class NameResolutionTest extends TestCase
{
    use TenantAware;

    private function resolve(string $requestClass, array $payload)
    {
        $req = $requestClass::create('/api/v1', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return $req;
    }

    #[Test]
    public function balances_grid_resolves_item_and_warehouse_names(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        // Post opening stock so a balance row exists.
        $entry = app(OpeningStockService::class)->createDraft(
            ['warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '5', 'unit_cost' => '2']]
        );
        app(OpeningStockService::class)->post($entry);

        $resp = app(StockBalanceController::class)->index(Request::create('/', 'GET'))->getData(true);
        $row = collect($resp['data'])->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame($item->name, $row['item_name']);
        $this->assertSame($item->sku, $row['item_sku']);
        $this->assertSame($wh->name, $row['warehouse_name']);
        $this->assertSame($wh->code, $row['warehouse_code']);
    }

    #[Test]
    public function ledger_resolves_item_and_warehouse_names(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $entry = app(OpeningStockService::class)->createDraft(
            ['warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '5', 'unit_cost' => '2']]
        );
        app(OpeningStockService::class)->post($entry);

        $resp = app(StockLedgerController::class)->index(Request::create('/', 'GET'))->getData(true);
        $row = collect($resp['data'])->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame($item->name, $row['item_name']);
        $this->assertSame($item->sku, $row['item_sku']);
        $this->assertSame($wh->name, $row['warehouse_name']);
        $this->assertSame('Opening stock', $row['source_label']);
        $this->assertSame($entry->entry_number, $row['source_number']);
        $this->assertSame('Opening stock '.$entry->entry_number, $row['source_display']);
    }

    #[Test]
    public function document_list_pages_resolve_header_names_and_document_links(): void
    {
        $this->useTenantA();
        $wh = F::warehouse(['name' => 'Readable Main']);
        $to = F::warehouse(['name' => 'Readable Reserve']);
        $item = F::item();
        $supplier = Supplier::query()->create(['code' => 'SUP-READ', 'name' => 'Readable Supplier']);

        app(PurchaseOrderController::class)->store($this->resolve(StorePurchaseOrderRequest::class, [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'ordered_qty' => '3', 'unit_price' => '4']],
        ]));
        app(GoodsReceiptController::class)->store($this->resolve(StoreGoodsReceiptRequest::class, [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'received_qty' => '3', 'unit_cost' => '4']],
        ]));
        app(StockTransferController::class)->store($this->resolve(StoreStockTransferRequest::class, [
            'from_warehouse_id' => $wh->id,
            'to_warehouse_id' => $to->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '1']],
        ]));
        app(StockAdjustmentController::class)->store($this->resolve(StoreStockAdjustmentRequest::class, [
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '2', 'unit_cost' => '1']],
        ]));
        app(StockCountController::class)->store($this->resolve(StoreStockCountRequest::class, [
            'count_type' => 'cycle',
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'system_qty' => '3', 'counted_qty' => '3']],
        ]));
        app(SalesOrderController::class)->store($this->resolve(StoreSalesOrderRequest::class, [
            'warehouse_id' => $wh->id,
            'customer_name' => 'Readable Customer',
            'lines' => [['item_id' => $item->id, 'ordered_qty' => '1', 'unit_price' => '5']],
        ]));

        $po = app(PurchaseOrderController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];
        $grn = app(GoodsReceiptController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];
        $transfer = app(StockTransferController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];
        $adjustment = app(StockAdjustmentController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];
        $count = app(StockCountController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];
        $sales = app(SalesOrderController::class)->index(Request::create('/', 'GET'))->getData(true)['data'][0];

        $this->assertSame('Readable Supplier', $po['supplier_name']);
        $this->assertSame('Readable Main', $po['warehouse_name']);
        $this->assertSame('Readable Supplier', $grn['supplier_name']);
        $this->assertSame('Readable Main', $grn['warehouse_name']);
        $this->assertSame('Readable Main', $transfer['from_warehouse_name']);
        $this->assertSame('Readable Reserve', $transfer['to_warehouse_name']);
        $this->assertSame('Readable Main', $adjustment['warehouse_name']);
        $this->assertSame('Readable Main', $count['warehouse_name']);
        $this->assertSame('Readable Main', $sales['warehouse_name']);
    }

    #[Test]
    public function purchase_order_detail_resolves_names(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $req = $this->resolve(StorePurchaseOrderRequest::class, [
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'ordered_qty' => '3', 'unit_price' => '4']],
        ]);
        $id = app(PurchaseOrderController::class)->store($req)->getData(true)['data']['id'];

        $detail = app(PurchaseOrderController::class)->show(PurchaseOrder::query()->findOrFail($id))->getData(true)['data'];

        $this->assertSame($wh->name, $detail['purchase_order']['warehouse_name']);
        $this->assertSame($item->name, $detail['lines'][0]['item_name']);
        $this->assertSame($item->sku, $detail['lines'][0]['item_sku']);
    }

    #[Test]
    public function goods_receipt_detail_resolves_names(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $req = $this->resolve(StoreGoodsReceiptRequest::class, [
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'received_qty' => '3', 'unit_cost' => '4']],
        ]);
        $id = app(GoodsReceiptController::class)->store($req)->getData(true)['data']['id'];

        $detail = app(GoodsReceiptController::class)->show(GoodsReceipt::query()->findOrFail($id))->getData(true)['data'];

        $this->assertSame($wh->name, $detail['grn']['warehouse_name']);
        $this->assertSame($item->name, $detail['grn']['lines'][0]['item']['name']);
    }

    #[Test]
    public function transfer_detail_resolves_from_and_to_warehouse_names(): void
    {
        $this->useTenantA();
        $from = F::warehouse();
        $to = F::warehouse();
        $item = F::item();
        $req = $this->resolve(StoreStockTransferRequest::class, [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '1']],
        ]);
        $id = app(StockTransferController::class)->store($req)->getData(true)['data']['id'];

        $detail = app(StockTransferController::class)->show(StockTransfer::query()->findOrFail($id))->getData(true)['data'];

        $this->assertSame($from->name, $detail['transfer']['from_warehouse_name']);
        $this->assertSame($to->name, $detail['transfer']['to_warehouse_name']);
        $this->assertSame($item->name, $detail['transfer']['lines'][0]['item']['name']);
    }

    #[Test]
    public function adjustment_detail_resolves_names(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $req = $this->resolve(StoreStockAdjustmentRequest::class, [
            'warehouse_id' => $wh->id,
            'lines' => [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '2', 'unit_cost' => '1']],
        ]);
        $id = app(StockAdjustmentController::class)->store($req)->getData(true)['data']['id'];

        $detail = app(StockAdjustmentController::class)->show(StockAdjustment::query()->findOrFail($id))->getData(true)['data'];

        $this->assertSame($wh->name, $detail['adjustment']['warehouse_name']);
        $this->assertSame($item->name, $detail['adjustment']['lines'][0]['item']['name']);
    }

    #[Test]
    public function sales_order_detail_resolves_warehouse_and_keeps_customer_name(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();
        $req = $this->resolve(StoreSalesOrderRequest::class, [
            'warehouse_id' => $wh->id, 'customer_name' => 'Acme Corp',
            'lines' => [['item_id' => $item->id, 'ordered_qty' => '3', 'unit_price' => '5']],
        ]);
        $id = app(SalesOrderController::class)->store($req)->getData(true)['data']['id'];

        $detail = app(SalesOrderController::class)->show(SalesOrder::query()->findOrFail($id))->getData(true)['data'];

        $this->assertSame($wh->name, $detail['sales_order']['warehouse_name']);
        $this->assertSame('Acme Corp', $detail['sales_order']['customer_name']);
        $this->assertSame($item->name, $detail['sales_order']['lines'][0]['item']['name']);
    }

    #[Test]
    public function name_resolution_is_org_scoped(): void
    {
        // Org A item name must NOT leak into org B's balance grid.
        $this->useTenantA();
        $whA = F::warehouse();
        $itemA = F::item(['name' => 'ORG-A-SECRET-ITEM']);
        $entry = app(OpeningStockService::class)->createDraft(
            ['warehouse_id' => $whA->id],
            [['item_id' => $itemA->id, 'quantity' => '1', 'unit_cost' => '1']]
        );
        app(OpeningStockService::class)->post($entry);

        $this->useTenantB();
        $resp = app(StockBalanceController::class)->index(Request::create('/', 'GET'))->getData(true);
        $names = collect($resp['data'])->pluck('item_name')->filter()->all();
        $this->assertNotContains('ORG-A-SECRET-ITEM', $names);
    }
}
