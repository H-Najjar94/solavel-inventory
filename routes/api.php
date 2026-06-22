<?php

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\OpeningStockController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SolaStock JSON API (v1)  →  served under /inventory/api/v1
|--------------------------------------------------------------------------
| All routes resolve the active tenant (inv.tenant) and enforce inventory
| permissions (perm:...). Controllers are thin; business + all stock writes go
| through application services (StockLedgerService / document services).
*/

// Tenant selection — NOT tenant-gated (this is how a tenant gets selected).
Route::prefix('v1/tenant')->group(function () {
    Route::get('/status', [\App\Http\Controllers\Api\V1\TenantController::class, 'status'])->name('api.v1.tenant.status');
    Route::post('/select-demo', [\App\Http\Controllers\Api\V1\TenantController::class, 'selectDemo'])->name('api.v1.tenant.select-demo');
    Route::post('/clear', [\App\Http\Controllers\Api\V1\TenantController::class, 'clear'])->name('api.v1.tenant.clear');
    // First-run provisioning of SolaStock tables for the live org (admin-only;
    // does its own auth + permission + forbidden-DB checks internally).
    Route::post('/provision', [\App\Http\Controllers\Api\V1\TenantController::class, 'provision'])->name('api.v1.tenant.provision');
    // Org switcher — list the user's orgs + switch the active org/client. NOT
    // tenant-gated (switching happens before the tenant is resolved); each does
    // its own central membership check.
    Route::get('/organizations', [\App\Http\Controllers\Api\V1\TenantController::class, 'organizations'])->name('api.v1.tenant.organizations');
    Route::post('/select-org', [\App\Http\Controllers\Api\V1\TenantController::class, 'selectOrganization'])->name('api.v1.tenant.select-org');
});

Route::prefix('v1')->middleware(['inv.tenant'])->group(function () {

    // Bootstrap data for the SPA (permissions, settings, lookups).
    Route::get('/meta', [MetaController::class, 'index'])->name('api.v1.meta');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard');
    Route::get('/dashboard/layout', [DashboardController::class, 'getLayout'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.layout.get');
    Route::put('/dashboard/layout', [DashboardController::class, 'saveLayout'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.layout.save');

    // Items
    Route::get('/items', [ItemController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.index');
    Route::get('/items/{item}', [ItemController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.show');
    Route::post('/items', [ItemController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.store');
    Route::put('/items/{item}', [ItemController::class, 'update'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.update');
    Route::get('/items/{item}/movements', [ItemController::class, 'movements'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.items.movements');
    Route::get('/items/{item}/valuation', [ItemController::class, 'valuation'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.items.valuation');

    // Item images — PRIVATE storage, served only via authenticated org-scoped
    // routes. View/serve = view_items (viewers included); mutate = manage_items.
    Route::get('/items/{item}/images', [\App\Http\Controllers\Api\V1\ItemImageController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.images.index');
    Route::post('/items/{item}/images', [\App\Http\Controllers\Api\V1\ItemImageController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.images.store');
    Route::get('/item-images/{image}', [\App\Http\Controllers\Api\V1\ItemImageController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.item-images.show');
    Route::post('/item-images/{image}/primary', [\App\Http\Controllers\Api\V1\ItemImageController::class, 'setPrimary'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.item-images.primary');
    Route::delete('/item-images/{image}', [\App\Http\Controllers\Api\V1\ItemImageController::class, 'destroy'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.item-images.destroy');

    // Warehouses (+ nested zones/bins)
    Route::get('/warehouses', [WarehouseController::class, 'index'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.index');
    Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.show');
    Route::post('/warehouses', [WarehouseController::class, 'store'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.store');
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.update');

    // Warehouse images — PRIVATE storage, authenticated org-scoped serve route.
    // View/serve = view_warehouses (viewers incl.); mutate = manage_warehouses.
    Route::get('/warehouses/{warehouse}/images', [\App\Http\Controllers\Api\V1\WarehouseImageController::class, 'index'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.images.index');
    Route::post('/warehouses/{warehouse}/images', [\App\Http\Controllers\Api\V1\WarehouseImageController::class, 'store'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.images.store');
    Route::get('/warehouse-images/{image}', [\App\Http\Controllers\Api\V1\WarehouseImageController::class, 'show'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouse-images.show');
    Route::post('/warehouse-images/{image}/primary', [\App\Http\Controllers\Api\V1\WarehouseImageController::class, 'setPrimary'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouse-images.primary');
    Route::delete('/warehouse-images/{image}', [\App\Http\Controllers\Api\V1\WarehouseImageController::class, 'destroy'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouse-images.destroy');

    // Opening Stock documents
    Route::get('/opening-stock', [OpeningStockController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.opening.index');
    Route::get('/opening-stock/{entry}', [OpeningStockController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.opening.show');
    Route::post('/opening-stock', [OpeningStockController::class, 'store'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.store');
    Route::put('/opening-stock/{entry}', [OpeningStockController::class, 'update'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.update');
    Route::post('/opening-stock/{entry}/post', [OpeningStockController::class, 'post'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.post');
    Route::post('/opening-stock/{entry}/reverse', [OpeningStockController::class, 'reverse'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.reverse');

    // Stock Adjustments
    Route::get('/adjustments', [StockAdjustmentController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.adjustments.index');
    Route::get('/adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.adjustments.show');
    Route::post('/adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.adjustments.store');
    Route::put('/adjustments/{adjustment}', [StockAdjustmentController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.adjustments.update');
    Route::post('/adjustments/{adjustment}/post', [StockAdjustmentController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.adjustments.post');
    Route::post('/adjustments/{adjustment}/reverse', [StockAdjustmentController::class, 'reverse'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.adjustments.reverse');

    // Stock Ledger (read-only)
    Route::get('/ledger', [StockLedgerController::class, 'index'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.ledger.index');
    // Which FIFO cost layers a single outbound movement consumed (read-only).
    Route::get('/movements/{ledger}/consumed-layers', [StockLedgerController::class, 'consumedLayers'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.movements.consumed-layers');

    // Stock Balances (read-only, from stock_balances projection)
    Route::get('/balances', [StockBalanceController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.balances.index');

    // ── Suppliers ──
    Route::get('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.suppliers.index');
    Route::get('/suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.suppliers.show');
    Route::post('/suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.suppliers.store');
    Route::put('/suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'update'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.suppliers.update');

    // ── Warehouse structure: zones & bins ──
    Route::middleware('perm:inventory.manage_warehouses')->group(function () {
        Route::post('/warehouses/{warehouse}/zones', [\App\Http\Controllers\Api\V1\WarehouseStructureController::class, 'storeZone'])->name('api.v1.zones.store');
        Route::put('/zones/{zone}', [\App\Http\Controllers\Api\V1\WarehouseStructureController::class, 'updateZone'])->name('api.v1.zones.update');
        Route::post('/warehouses/{warehouse}/bins', [\App\Http\Controllers\Api\V1\WarehouseStructureController::class, 'storeBin'])->name('api.v1.bins.store');
        Route::put('/bins/{bin}', [\App\Http\Controllers\Api\V1\WarehouseStructureController::class, 'updateBin'])->name('api.v1.bins.update');
    });

    // ── Purchase Orders (no stock movement) ──
    Route::get('/purchase-orders', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.po.index');
    Route::get('/purchase-orders/{purchase_order}', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.po.show');
    Route::post('/purchase-orders', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.store');
    Route::put('/purchase-orders/{purchase_order}', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.update');
    Route::post('/purchase-orders/{purchase_order}/approve', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'approve'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.approve');
    Route::post('/purchase-orders/{purchase_order}/cancel', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'cancel'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.cancel');

    // ── Goods Receipts (GRN → stock IN via service) ──
    Route::get('/goods-receipts', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.index');
    Route::get('/goods-receipts/{goods_receipt}', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.show');
    Route::get('/purchase-orders/{purchase_order}/grn-draft', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'fromPo'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.from-po');
    Route::post('/goods-receipts', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.store');
    Route::put('/goods-receipts/{goods_receipt}', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.update');
    Route::post('/goods-receipts/{goods_receipt}/post', [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.post');

    // ── Stock Transfers (OUT+IN via service) ──
    Route::get('/transfers', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.index');
    Route::get('/transfers/{stock_transfer}', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.show');
    Route::get('/transfers-available', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'available'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.available');
    Route::post('/transfers', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.store');
    Route::put('/transfers/{stock_transfer}', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.update');
    Route::post('/transfers/{stock_transfer}/post', [\App\Http\Controllers\Api\V1\StockTransferController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.post');

    // ── Stock Counts (variance → adjustment via service) ──
    Route::get('/counts', [\App\Http\Controllers\Api\V1\StockCountController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.index');
    Route::get('/counts/{stock_count}', [\App\Http\Controllers\Api\V1\StockCountController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.show');
    Route::get('/counts-prefill', [\App\Http\Controllers\Api\V1\StockCountController::class, 'prefill'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.prefill');
    Route::post('/counts', [\App\Http\Controllers\Api\V1\StockCountController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.store');
    Route::put('/counts/{stock_count}', [\App\Http\Controllers\Api\V1\StockCountController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.update');
    Route::post('/counts/{stock_count}/post', [\App\Http\Controllers\Api\V1\StockCountController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.post');

    // ── Sales Fulfillment: Sales Orders ──
    Route::get('/sales-orders', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-orders.index');
    Route::get('/sales-orders/{sales_order}', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-orders.show');
    Route::post('/sales-orders', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'store'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.store');
    Route::put('/sales-orders/{sales_order}', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'update'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.update');
    Route::post('/sales-orders/{sales_order}/confirm', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'confirm'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.confirm');
    Route::post('/sales-orders/{sales_order}/reserve', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'reserve'])
        ->middleware('perm:inventory.manage_reservations')->name('api.v1.sales-orders.reserve');
    Route::post('/sales-orders/{sales_order}/release-reservation', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'releaseReservation'])
        ->middleware('perm:inventory.manage_reservations')->name('api.v1.sales-orders.release');
    Route::post('/sales-orders/{sales_order}/cancel', [\App\Http\Controllers\Api\V1\SalesOrderController::class, 'cancel'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.cancel');

    // ── Sales Fulfillment: Picking ──
    Route::get('/pick-lists', [\App\Http\Controllers\Api\V1\PickListController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.pick-lists.index');
    Route::get('/pick-lists/{pick_list}', [\App\Http\Controllers\Api\V1\PickListController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.pick-lists.show');
    Route::post('/pick-lists', [\App\Http\Controllers\Api\V1\PickListController::class, 'store'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.store');
    Route::put('/pick-lists/{pick_list}', [\App\Http\Controllers\Api\V1\PickListController::class, 'update'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.update');
    Route::post('/pick-lists/{pick_list}/picked', [\App\Http\Controllers\Api\V1\PickListController::class, 'markPicked'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.picked');

    // ── Sales Fulfillment: Packing ──
    Route::get('/packs', [\App\Http\Controllers\Api\V1\PackController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.packs.index');
    Route::get('/packs/{pack}', [\App\Http\Controllers\Api\V1\PackController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.packs.show');
    Route::post('/packs', [\App\Http\Controllers\Api\V1\PackController::class, 'store'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.store');
    Route::put('/packs/{pack}', [\App\Http\Controllers\Api\V1\PackController::class, 'update'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.update');
    Route::post('/packs/{pack}/packed', [\App\Http\Controllers\Api\V1\PackController::class, 'markPacked'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.packed');

    // ── Sales Fulfillment: Shipments (post = stock OUT via StockLedgerService) ──
    Route::get('/shipments', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.index');
    Route::get('/shipments/{shipment}', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.show');
    Route::get('/sales-orders/{sales_order}/shipment-draft', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'fromSalesOrder'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.from-so');
    Route::post('/shipments', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'store'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.store');
    Route::put('/shipments/{shipment}', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'update'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.update');
    Route::post('/shipments/{shipment}/post', [\App\Http\Controllers\Api\V1\ShipmentController::class, 'post'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.post');

    // ── Sales Fulfillment: Returns (post = stock IN via StockLedgerService) ──
    Route::get('/sales-returns', [\App\Http\Controllers\Api\V1\SalesReturnController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-returns.index');
    Route::get('/sales-returns/{sales_return}', [\App\Http\Controllers\Api\V1\SalesReturnController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-returns.show');
    Route::post('/sales-returns', [\App\Http\Controllers\Api\V1\SalesReturnController::class, 'store'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.store');
    Route::put('/sales-returns/{sales_return}', [\App\Http\Controllers\Api\V1\SalesReturnController::class, 'update'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.update');
    Route::post('/sales-returns/{sales_return}/post', [\App\Http\Controllers\Api\V1\SalesReturnController::class, 'post'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.post');

    // ── Traceability: Lots ──
    Route::get('/lots', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'lots'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.index');
    Route::get('/lots-availability', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'lotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.availability');
    Route::get('/lots/{lot}', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'lot'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.show');
    Route::get('/lots/{lot}/movements', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'lotMovements'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.movements');
    Route::put('/lots/{lot}/status', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'updateLotStatus'])
        ->middleware('perm:inventory.manage_lots')->name('api.v1.lots.status');

    // ── Traceability: Serials ──
    Route::get('/serials', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'serials'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.index');
    Route::get('/serials-availability', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'serialAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.availability');
    Route::get('/serials/{serial}', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'serial'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.show');
    Route::get('/serials/{serial}/lifecycle', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'serialLifecycle'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.lifecycle');
    Route::put('/serials/{serial}/status', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'updateSerialStatus'])
        ->middleware('perm:inventory.manage_serials')->name('api.v1.serials.status');

    // ── Traceability: helpers ──
    Route::post('/traceability/validate-serials', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'validateSerials'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-serials');
    Route::post('/traceability/validate-lot', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'validateLotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-lot');
    Route::post('/traceability/validate-capture', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'validateCapture'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-capture');
    Route::get('/traceability/suggest-outbound-lots', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'suggestOutboundLots'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.suggest-outbound-lots');
    Route::get('/traceability/lots/availability', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'lotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.lots.availability');
    Route::get('/traceability/serials/availability', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'serialAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.serials.availability');
    Route::get('/traceability/expiry-risk', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'expiryRiskSummary'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.expiry-risk');
    Route::get('/expiry-report', [\App\Http\Controllers\Api\V1\TraceabilityController::class, 'expiryReport'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.expiry');

    // ── Recalls ──
    Route::get('/recalls', [\App\Http\Controllers\Api\V1\RecallController::class, 'index'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.index');
    Route::get('/recalls/{recall}', [\App\Http\Controllers\Api\V1\RecallController::class, 'show'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.show');
    Route::get('/recalls/{recall}/impact', [\App\Http\Controllers\Api\V1\RecallController::class, 'impactPreview'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.impact');
    Route::post('/recalls', [\App\Http\Controllers\Api\V1\RecallController::class, 'store'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.store');
    Route::put('/recalls/{recall}', [\App\Http\Controllers\Api\V1\RecallController::class, 'update'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.update');
    Route::post('/recalls/{recall}/activate', [\App\Http\Controllers\Api\V1\RecallController::class, 'activate'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.activate');
    Route::post('/recalls/{recall}/close', [\App\Http\Controllers\Api\V1\RecallController::class, 'close'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.close');

    // ── Reports (read-only, registry-driven) ──
    Route::get('/reports', [\App\Http\Controllers\Api\V1\ReportController::class, 'index'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.reports.index');
    Route::get('/reports/{report}/export', [\App\Http\Controllers\Api\V1\ReportController::class, 'exportReport'])
        ->middleware('perm:inventory.export_reports')->name('api.v1.reports.export');
    Route::get('/reports/{report}', [\App\Http\Controllers\Api\V1\ReportController::class, 'show'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.reports.show');

    // ── Settings ──
    Route::get('/settings', [\App\Http\Controllers\Api\V1\SettingsController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.settings.show');
    Route::middleware('perm:inventory.manage_settings')->group(function () {
        Route::put('/settings', [\App\Http\Controllers\Api\V1\SettingsController::class, 'updateSettings']);
        Route::post('/settings/units', [\App\Http\Controllers\Api\V1\SettingsController::class, 'storeUnit']);
        Route::post('/settings/categories', [\App\Http\Controllers\Api\V1\SettingsController::class, 'storeCategory']);
        Route::post('/settings/brands', [\App\Http\Controllers\Api\V1\SettingsController::class, 'storeBrand']);
    });

    // ── SolaBooks integration (foundation: mappings + outbox; no real posting) ──
    Route::prefix('integration/solabooks')->group(function () {
        Route::get('/status', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'status'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.status');

        Route::get('/mappings/accounts', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'accountMappings'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.accounts.index');
        Route::put('/mappings/accounts', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'updateAccountMappings'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.accounts.update');

        Route::get('/mappings/items', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'itemMappings'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.items.index');
        Route::put('/mappings/items/{item}', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'updateItemMapping'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.items.update');

        Route::get('/events', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'events'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.events.index');
        Route::get('/events/{event}', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'event'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.events.show');
        Route::post('/events/{event}/retry-placeholder', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'retryPlaceholder'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.retry');
        Route::post('/events/{event}/ignore-placeholder', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'ignorePlaceholder'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.ignore');
    });
});
