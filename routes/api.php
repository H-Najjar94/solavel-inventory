<?php

use App\Http\Controllers\Api\SpaPageViewController;
use App\Http\Controllers\Api\Tenancy\SyncEventsController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomRoleController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\InventoryAuditController;
use App\Http\Controllers\Api\V1\ItemAttachmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemImageController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\OpeningStockController;
use App\Http\Controllers\Api\V1\PackController;
use App\Http\Controllers\Api\V1\PickListController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RecallController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Http\Controllers\Api\V1\ScannerController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TraceabilityController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseImageController;
use App\Http\Controllers\Api\V1\WarehouseStructureController;
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
    Route::get('/status', [TenantController::class, 'status'])->name('api.v1.tenant.status');
    Route::post('/select-demo', [TenantController::class, 'selectDemo'])->name('api.v1.tenant.select-demo');
    Route::post('/clear', [TenantController::class, 'clear'])->name('api.v1.tenant.clear');
    // First-run provisioning of SolaStock tables for the live org (admin-only;
    // does its own auth + permission + forbidden-DB checks internally).
    Route::post('/provision', [TenantController::class, 'provision'])->name('api.v1.tenant.provision');
    Route::get('/schema-audit', [TenantController::class, 'schemaAudit'])->name('api.v1.tenant.schema-audit');
    // Org switcher — list the user's orgs + switch the active org/client. NOT
    // tenant-gated (switching happens before the tenant is resolved); each does
    // its own central membership check.
    Route::get('/organizations', [TenantController::class, 'organizations'])->name('api.v1.tenant.organizations');
    Route::post('/select-org', [TenantController::class, 'selectOrganization'])->name('api.v1.tenant.select-org');
});

Route::prefix('tenancy')->middleware(['sync.signature'])->group(function () {
    Route::post('sync/events', SyncEventsController::class)
        ->name('api.tenancy.sync.events');
});

// 'feature' (bare) derives each route's required stock.* feature from its route
// name via config('inventory_entitlements.route_features') and denies with the
// commercial envelope when the plan excludes it. INERT until
// SOLASTOCK_FEATURE_ENFORCEMENT=true. Runs alongside perm:, never replacing role
// auth; routes with no mapped feature pass straight through.
Route::prefix('v1')->middleware(['inv.tenant', 'feature'])->group(function () {

    // Client-side SPA navigation tracking: forwards one page_view per
    // react-router move to the central event log (source_app=inventory) so
    // every navigation is visible in admin. No permission gate.
    Route::post('/spa-view', SpaPageViewController::class)
        ->middleware('throttle:120,1')->name('api.v1.spa-view');

    // Bootstrap data for the SPA (permissions, settings, lookups).
    Route::get('/meta', [MetaController::class, 'index'])->name('api.v1.meta');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard');
    Route::get('/dashboard/layout', [DashboardController::class, 'getLayout'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.layout.get');
    Route::put('/dashboard/layout', [DashboardController::class, 'saveLayout'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.layout.save');
    Route::get('/dashboard/alerts', [DashboardController::class, 'alerts'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.alerts');
    Route::post('/dashboard/alerts/{alert}/acknowledge', [DashboardController::class, 'acknowledgeAlert'])
        ->middleware('perm:inventory.view_dashboard')->name('api.v1.dashboard.alerts.acknowledge');

    // Items
    Route::get('/items', [ItemController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.index');
    Route::get('/items/barcode/lookup', [ItemController::class, 'barcodeLookup'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.barcode.lookup');
    Route::get('/scanner/lookup', [ScannerController::class, 'lookup'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.scanner.lookup');
    Route::get('/items/{item}', [ItemController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.show');
    Route::post('/items', [ItemController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.store');
    Route::post('/items/bulk-update', [ItemController::class, 'bulkUpdate'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.bulk-update');
    Route::put('/items/{item}', [ItemController::class, 'update'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.update');
    Route::get('/items/{item}/movements', [ItemController::class, 'movements'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.items.movements');
    Route::get('/items/{item}/valuation', [ItemController::class, 'valuation'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.items.valuation');
    Route::post('/items/{item}/barcodes', [ItemController::class, 'storeBarcode'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.barcodes.store');
    Route::post('/items/{item}/barcodes/{barcode}/primary', [ItemController::class, 'primaryBarcode'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.barcodes.primary');
    Route::delete('/items/{item}/barcodes/{barcode}', [ItemController::class, 'destroyBarcode'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.barcodes.destroy');
    Route::get('/items/{item}/labels', [ItemController::class, 'labelSheet'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.labels');
    Route::post('/items/{item}/variants', [ItemController::class, 'storeVariant'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.variants.store');
    Route::put('/items/{item}/variants/{variant}', [ItemController::class, 'updateVariant'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.variants.update');
    Route::delete('/items/{item}/variants/{variant}', [ItemController::class, 'destroyVariant'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.variants.destroy');
    Route::post('/items/{item}/supplier-prices', [ItemController::class, 'storeSupplierPrice'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.supplier-prices.store');
    Route::put('/items/{item}/supplier-prices/{price}', [ItemController::class, 'updateSupplierPrice'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.supplier-prices.update');
    Route::delete('/items/{item}/supplier-prices/{price}', [ItemController::class, 'destroySupplierPrice'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.supplier-prices.destroy');

    // Item images — PRIVATE storage, served only via authenticated org-scoped
    // routes. View/serve = view_items (viewers included); mutate = manage_items.
    Route::get('/items/{item}/images', [ItemImageController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.images.index');
    Route::post('/items/{item}/images', [ItemImageController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.images.store');
    Route::get('/item-images/{image}', [ItemImageController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.item-images.show');
    Route::post('/item-images/{image}/primary', [ItemImageController::class, 'setPrimary'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.item-images.primary');
    Route::delete('/item-images/{image}', [ItemImageController::class, 'destroy'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.item-images.destroy');
    Route::get('/items/{item}/attachments', [ItemAttachmentController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.items.attachments.index');
    Route::post('/items/{item}/attachments', [ItemAttachmentController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.items.attachments.store');
    Route::get('/item-attachments/{attachment}', [ItemAttachmentController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.item-attachments.show');
    Route::delete('/item-attachments/{attachment}', [ItemAttachmentController::class, 'destroy'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.item-attachments.destroy');

    // Warehouses (+ nested zones/bins)
    Route::get('/warehouses', [WarehouseController::class, 'index'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.index');
    Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.show');
    Route::post('/warehouses', [WarehouseController::class, 'store'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.store');
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.update');
    Route::get('/warehouses/{warehouse}/labels', [WarehouseStructureController::class, 'labelSheet'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.labels');

    // Warehouse images — PRIVATE storage, authenticated org-scoped serve route.
    // View/serve = view_warehouses (viewers incl.); mutate = manage_warehouses.
    Route::get('/warehouses/{warehouse}/images', [WarehouseImageController::class, 'index'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouses.images.index');
    Route::post('/warehouses/{warehouse}/images', [WarehouseImageController::class, 'store'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouses.images.store');
    Route::get('/warehouse-images/{image}', [WarehouseImageController::class, 'show'])
        ->middleware('perm:inventory.view_warehouses')->name('api.v1.warehouse-images.show');
    Route::post('/warehouse-images/{image}/primary', [WarehouseImageController::class, 'setPrimary'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouse-images.primary');
    Route::delete('/warehouse-images/{image}', [WarehouseImageController::class, 'destroy'])
        ->middleware('perm:inventory.manage_warehouses')->name('api.v1.warehouse-images.destroy');

    // Opening Stock documents
    Route::get('/opening-stock', [OpeningStockController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.opening.index');
    Route::get('/opening-stock/{entry}', [OpeningStockController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.opening.show');
    Route::post('/opening-stock', [OpeningStockController::class, 'store'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.store');
    Route::post('/opening-stock/import', [OpeningStockController::class, 'importCsv'])
        ->middleware('perm:inventory.manage_opening_stock')->name('api.v1.opening.import');
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
    Route::get('/audit-logs', [InventoryAuditController::class, 'index'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.audit-logs.index');
    // Which FIFO cost layers a single outbound movement consumed (read-only).
    Route::get('/movements/{ledger}/consumed-layers', [StockLedgerController::class, 'consumedLayers'])
        ->middleware('perm:inventory.view_ledger')->name('api.v1.movements.consumed-layers');

    // Stock Balances (read-only, from stock_balances projection)
    Route::get('/balances', [StockBalanceController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.balances.index');

    // ── Suppliers ──
    Route::get('/suppliers', [SupplierController::class, 'index'])
        ->middleware('perm:inventory.view_items')->name('api.v1.suppliers.index');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])
        ->middleware('perm:inventory.view_items')->name('api.v1.suppliers.show');
    Route::post('/suppliers', [SupplierController::class, 'store'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.suppliers.store');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])
        ->middleware('perm:inventory.manage_items')->name('api.v1.suppliers.update');

    // ── Customers / partners ──
    Route::get('/customers', [CustomerController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.customers.show');
    Route::post('/customers', [CustomerController::class, 'store'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.customers.update');

    // ── Warehouse structure: zones & bins ──
    Route::middleware('perm:inventory.manage_warehouses')->group(function () {
        Route::post('/warehouses/{warehouse}/zones', [WarehouseStructureController::class, 'storeZone'])->name('api.v1.zones.store');
        Route::put('/zones/{zone}', [WarehouseStructureController::class, 'updateZone'])->name('api.v1.zones.update');
        Route::post('/warehouses/{warehouse}/bins', [WarehouseStructureController::class, 'storeBin'])->name('api.v1.bins.store');
        Route::put('/bins/{bin}', [WarehouseStructureController::class, 'updateBin'])->name('api.v1.bins.update');
    });

    // ── Purchase Orders (no stock movement) ──
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.po.index');
    Route::get('/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.po.show');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.store');
    Route::put('/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.update');
    Route::post('/purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.approve');
    Route::post('/purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.po.cancel');

    // ── Goods Receipts (GRN → stock IN via service) ──
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.index');
    Route::get('/goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.show');
    Route::get('/purchase-orders/{purchase_order}/grn-draft', [GoodsReceiptController::class, 'fromPo'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.grn.from-po');
    Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.store');
    Route::put('/goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.update');
    Route::post('/goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.post');
    Route::post('/goods-receipts/{goods_receipt}/reverse', [GoodsReceiptController::class, 'reverse'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.grn.reverse');

    // ── Stock Transfers (OUT+IN via service) ──
    Route::get('/transfers', [StockTransferController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.index');
    Route::get('/transfers/{stock_transfer}', [StockTransferController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.show');
    Route::get('/transfers-available', [StockTransferController::class, 'available'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.transfers.available');
    Route::post('/transfers', [StockTransferController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.store');
    Route::put('/transfers/{stock_transfer}', [StockTransferController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.update');
    Route::post('/transfers/{stock_transfer}/post', [StockTransferController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.post');
    Route::post('/transfers/{stock_transfer}/ship', [StockTransferController::class, 'ship'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.ship');
    Route::post('/transfers/{stock_transfer}/receive', [StockTransferController::class, 'receive'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.transfers.receive');

    // ── Stock Counts (variance → adjustment via service) ──
    Route::get('/counts', [StockCountController::class, 'index'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.index');
    Route::get('/counts/{stock_count}', [StockCountController::class, 'show'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.show');
    Route::get('/counts-prefill', [StockCountController::class, 'prefill'])
        ->middleware('perm:inventory.view_stock')->name('api.v1.counts.prefill');
    Route::post('/counts', [StockCountController::class, 'store'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.store');
    Route::put('/counts/{stock_count}', [StockCountController::class, 'update'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.update');
    Route::post('/counts/{stock_count}/post', [StockCountController::class, 'post'])
        ->middleware('perm:inventory.manage_adjustments')->name('api.v1.counts.post');

    // ── Sales Fulfillment: Sales Orders ──
    Route::get('/sales-orders', [SalesOrderController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-orders.index');
    Route::get('/sales-orders/{sales_order}', [SalesOrderController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-orders.show');
    Route::post('/sales-orders', [SalesOrderController::class, 'store'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.store');
    Route::put('/sales-orders/{sales_order}', [SalesOrderController::class, 'update'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.update');
    Route::post('/sales-orders/{sales_order}/confirm', [SalesOrderController::class, 'confirm'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.confirm');
    Route::post('/sales-orders/{sales_order}/reserve', [SalesOrderController::class, 'reserve'])
        ->middleware('perm:inventory.manage_reservations')->name('api.v1.sales-orders.reserve');
    Route::post('/sales-orders/{sales_order}/release-reservation', [SalesOrderController::class, 'releaseReservation'])
        ->middleware('perm:inventory.manage_reservations')->name('api.v1.sales-orders.release');
    Route::post('/sales-orders/{sales_order}/cancel', [SalesOrderController::class, 'cancel'])
        ->middleware('perm:inventory.manage_sales_orders')->name('api.v1.sales-orders.cancel');

    // ── Sales Fulfillment: Picking ──
    Route::get('/pick-lists', [PickListController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.pick-lists.index');
    Route::get('/pick-lists/{pick_list}', [PickListController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.pick-lists.show');
    Route::post('/pick-lists', [PickListController::class, 'store'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.store');
    Route::put('/pick-lists/{pick_list}', [PickListController::class, 'update'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.update');
    Route::post('/pick-lists/{pick_list}/picked', [PickListController::class, 'markPicked'])
        ->middleware('perm:inventory.manage_picking')->name('api.v1.pick-lists.picked');

    // ── Sales Fulfillment: Packing ──
    Route::get('/packs', [PackController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.packs.index');
    Route::get('/packs/{pack}', [PackController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.packs.show');
    Route::post('/packs', [PackController::class, 'store'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.store');
    Route::put('/packs/{pack}', [PackController::class, 'update'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.update');
    Route::post('/packs/{pack}/packed', [PackController::class, 'markPacked'])
        ->middleware('perm:inventory.manage_packing')->name('api.v1.packs.packed');

    // ── Sales Fulfillment: Shipments (post = stock OUT via StockLedgerService) ──
    Route::get('/shipments', [ShipmentController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.index');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.show');
    Route::get('/shipments/{shipment}/rates', [ShipmentController::class, 'rates'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.rates');
    Route::get('/shipments/{shipment}/tracking', [ShipmentController::class, 'tracking'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.tracking');
    Route::get('/sales-orders/{sales_order}/shipment-draft', [ShipmentController::class, 'fromSalesOrder'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.shipments.from-so');
    Route::post('/shipments', [ShipmentController::class, 'store'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.store');
    Route::put('/shipments/{shipment}', [ShipmentController::class, 'update'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.update');
    Route::post('/shipments/{shipment}/post', [ShipmentController::class, 'post'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.post');
    Route::post('/shipments/{shipment}/label', [ShipmentController::class, 'label'])
        ->middleware('perm:inventory.manage_shipments')->name('api.v1.shipments.label');

    // ── Sales Fulfillment: Returns (post = stock IN via StockLedgerService) ──
    Route::get('/sales-returns', [SalesReturnController::class, 'index'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-returns.index');
    Route::get('/sales-returns/{sales_return}', [SalesReturnController::class, 'show'])
        ->middleware('perm:inventory.view_sales')->name('api.v1.sales-returns.show');
    Route::post('/sales-returns', [SalesReturnController::class, 'store'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.store');
    Route::put('/sales-returns/{sales_return}', [SalesReturnController::class, 'update'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.update');
    Route::post('/sales-returns/{sales_return}/post', [SalesReturnController::class, 'post'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.post');
    Route::post('/sales-returns/{sales_return}/authorize', [SalesReturnController::class, 'authorizeReturn'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.authorize');
    Route::post('/sales-returns/{sales_return}/inspect', [SalesReturnController::class, 'inspect'])
        ->middleware('perm:inventory.manage_returns')->name('api.v1.sales-returns.inspect');

    // ── Traceability: Lots ──
    Route::get('/lots', [TraceabilityController::class, 'lots'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.index');
    Route::get('/lots-availability', [TraceabilityController::class, 'lotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.availability');
    Route::get('/lots/{lot}', [TraceabilityController::class, 'lot'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.show');
    Route::get('/lots/{lot}/movements', [TraceabilityController::class, 'lotMovements'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.lots.movements');
    Route::put('/lots/{lot}/status', [TraceabilityController::class, 'updateLotStatus'])
        ->middleware('perm:inventory.manage_lots')->name('api.v1.lots.status');

    // ── Traceability: Serials ──
    Route::get('/serials', [TraceabilityController::class, 'serials'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.index');
    Route::get('/serials-availability', [TraceabilityController::class, 'serialAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.availability');
    Route::get('/serials/{serial}', [TraceabilityController::class, 'serial'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.show');
    Route::get('/serials/{serial}/lifecycle', [TraceabilityController::class, 'serialLifecycle'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.serials.lifecycle');
    Route::put('/serials/{serial}/status', [TraceabilityController::class, 'updateSerialStatus'])
        ->middleware('perm:inventory.manage_serials')->name('api.v1.serials.status');

    // ── Traceability: helpers ──
    Route::post('/traceability/validate-serials', [TraceabilityController::class, 'validateSerials'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-serials');
    Route::post('/traceability/validate-lot', [TraceabilityController::class, 'validateLotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-lot');
    Route::post('/traceability/validate-capture', [TraceabilityController::class, 'validateCapture'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.validate-capture');
    Route::get('/traceability/suggest-outbound-lots', [TraceabilityController::class, 'suggestOutboundLots'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.suggest-outbound-lots');
    Route::get('/traceability/lots/availability', [TraceabilityController::class, 'lotAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.lots.availability');
    Route::get('/traceability/serials/availability', [TraceabilityController::class, 'serialAvailability'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.serials.availability');
    Route::get('/traceability/expiry-risk', [TraceabilityController::class, 'expiryRiskSummary'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.trace.expiry-risk');
    Route::get('/expiry-report', [TraceabilityController::class, 'expiryReport'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.expiry');

    // ── Recalls ──
    Route::get('/recalls', [RecallController::class, 'index'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.index');
    Route::get('/recalls/{recall}', [RecallController::class, 'show'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.show');
    Route::get('/recalls/{recall}/impact', [RecallController::class, 'impactPreview'])
        ->middleware('perm:inventory.view_traceability')->name('api.v1.recalls.impact');
    Route::post('/recalls', [RecallController::class, 'store'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.store');
    Route::put('/recalls/{recall}', [RecallController::class, 'update'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.update');
    Route::post('/recalls/{recall}/activate', [RecallController::class, 'activate'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.activate');
    Route::post('/recalls/{recall}/close', [RecallController::class, 'close'])
        ->middleware('perm:inventory.manage_recalls')->name('api.v1.recalls.close');

    // ── Reports (read-only, registry-driven) ──
    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.reports.index');
    Route::get('/reports/schedules', [ReportController::class, 'schedules'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.reports.schedules.index');
    Route::post('/reports/schedules', [ReportController::class, 'storeSchedule'])
        ->middleware('perm:inventory.export_reports')->name('api.v1.reports.schedules.store');
    Route::put('/reports/schedules/{schedule}', [ReportController::class, 'updateSchedule'])
        ->middleware('perm:inventory.export_reports')->name('api.v1.reports.schedules.update');
    Route::post('/reports/schedules/{schedule}/run', [ReportController::class, 'runSchedule'])
        ->middleware('perm:inventory.export_reports')->name('api.v1.reports.schedules.run');
    Route::get('/reports/{report}/export', [ReportController::class, 'exportReport'])
        ->middleware('perm:inventory.export_reports')->name('api.v1.reports.export');
    Route::get('/reports/{report}', [ReportController::class, 'show'])
        ->middleware('perm:inventory.view_reports')->name('api.v1.reports.show');

    // ── Settings ──
    Route::get('/settings', [SettingsController::class, 'show'])
        ->middleware('perm:inventory.view_settings')->name('api.v1.settings.show');
    Route::get('/settings/custom-roles', [CustomRoleController::class, 'index'])
        ->middleware('perm:inventory.view_settings')->name('api.v1.settings.custom-roles.index');
    Route::middleware('perm:inventory.manage_settings')->group(function () {
        Route::put('/settings', [SettingsController::class, 'updateSettings']);
        Route::put('/settings/taxes', [SettingsController::class, 'updateTaxes']);
        Route::get('/settings/warehouse-assignments', [SettingsController::class, 'allWarehouseAssignments']);
        Route::get('/settings/warehouse-assignments/{userId}', [SettingsController::class, 'warehouseAssignments']);
        Route::put('/settings/warehouse-assignments/{userId}', [SettingsController::class, 'syncWarehouseAssignments']);
        Route::post('/settings/units', [SettingsController::class, 'storeUnit']);
        Route::post('/settings/unit-conversions', [SettingsController::class, 'storeUnitConversion']);
        Route::post('/settings/warehouse-reorder-rules/calculate', [SettingsController::class, 'calculateWarehouseReorderRule'])
            ->name('api.v1.settings.reorder.calculate');
        Route::post('/settings/warehouse-reorder-rules', [SettingsController::class, 'storeWarehouseReorderRule']);
        Route::post('/settings/adjustment-reason-codes', [SettingsController::class, 'storeAdjustmentReasonCode']);
        Route::post('/settings/currency-rates', [SettingsController::class, 'storeCurrencyRate']);
        Route::post('/settings/categories', [SettingsController::class, 'storeCategory']);
        Route::put('/settings/categories/{category}', [SettingsController::class, 'updateCategory']);
        Route::post('/settings/brands', [SettingsController::class, 'storeBrand']);
        Route::put('/settings/brands/{brand}', [SettingsController::class, 'updateBrand']);
        Route::post('/settings/custom-roles', [CustomRoleController::class, 'store']);
        Route::put('/settings/custom-roles/{role}', [CustomRoleController::class, 'update']);
        Route::post('/settings/custom-role-assignments', [CustomRoleController::class, 'assign']);
        Route::delete('/settings/custom-role-assignments/{userId}', [CustomRoleController::class, 'unassign']);
    });

    // ── SolaBooks integration (mappings + local outbox + authenticated delivery) ──
    Route::prefix('integration/solabooks')->group(function () {
        Route::get('/status', [IntegrationController::class, 'status'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.status');
        Route::get('/wizard/discovery', [IntegrationController::class, 'wizardDiscovery'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.wizard.discovery');
        Route::post('/wizard/runs', [IntegrationController::class, 'startWizard'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.start');
        Route::get('/wizard/runs/{run}', [IntegrationController::class, 'wizardRun'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.wizard.show');
        Route::get('/wizard/runs/{run}/preview', [IntegrationController::class, 'wizardPreview'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.wizard.preview');
        Route::put('/wizard/runs/{run}/decisions', [IntegrationController::class, 'decideWizard'])
            ->middleware(['perm:inventory.integration.view', 'integration.setup'])->name('api.v1.integration.wizard.decide');
        Route::post('/wizard/runs/{run}/bulk-decisions', [IntegrationController::class, 'bulkDecideWizard'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.bulk-decide');
        Route::delete('/wizard/runs/{run}/decisions/{decision}', [IntegrationController::class, 'reverseWizardDecision'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.decisions.reverse');
        Route::post('/wizard/runs/{run}/snapshot/request', [IntegrationController::class, 'requestWizardSnapshot'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.snapshot.request');
        Route::post('/wizard/runs/{run}/snapshot/freeze', [IntegrationController::class, 'freezeWizardSnapshot'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.snapshot.freeze');
        Route::post('/wizard/runs/{run}/cutoff-review', [IntegrationController::class, 'reviewWizardCutoff'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.cutoff-review');
        Route::post('/wizard/runs/{run}/reset', [IntegrationController::class, 'resetWizardDraft'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.reset');
        Route::delete('/wizard/runs/{run}', [IntegrationController::class, 'discardWizardDraft'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.discard');
        Route::post('/wizard/runs/{run}/approve', [IntegrationController::class, 'approveWizard'])
            ->middleware(['perm:inventory.integration.setup', 'integration.setup'])->name('api.v1.integration.wizard.approve');
        Route::post('/wizard/runs/{run}/accountant-approve', [IntegrationController::class, 'accountantApproveWizard'])
            ->middleware(['perm:inventory.integration.accounting_review', 'integration.setup'])->name('api.v1.integration.wizard.accountant-approve');
        Route::post('/wizard/runs/{run}/activate', [IntegrationController::class, 'activateWizard'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.wizard.activate');
        Route::post('/wizard/runs/{run}/pause', [IntegrationController::class, 'pauseWizard'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.wizard.pause');
        Route::put('/connection', [IntegrationController::class, 'configure'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.connection.update');
        Route::post('/signing-keys/rotate', [IntegrationController::class, 'rotateSigningKey'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.signing.rotate');
        Route::post('/signing-keys/revoke', [IntegrationController::class, 'revokeSigningKey'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.signing.revoke');

        Route::get('/mappings/accounts', [IntegrationController::class, 'accountMappings'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.accounts.index');
        Route::put('/mappings/accounts', [IntegrationController::class, 'updateAccountMappings'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.accounts.update');
        Route::get('/mappings/taxes', [IntegrationController::class, 'taxMappings'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.taxes.index');
        Route::put('/mappings/taxes', [IntegrationController::class, 'updateTaxMappings'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.taxes.update');

        Route::get('/mappings/items', [IntegrationController::class, 'itemMappings'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.items.index');
        Route::put('/mappings/items/{item}', [IntegrationController::class, 'updateItemMapping'])
            ->middleware('perm:inventory.integration.manage')->name('api.v1.integration.items.update');

        Route::get('/events', [IntegrationController::class, 'events'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.events.index');
        Route::get('/events/{event}', [IntegrationController::class, 'event'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.events.show');
        Route::get('/dead-letters', [IntegrationController::class, 'deadLetters'])
            ->middleware('perm:inventory.integration.view')->name('api.v1.integration.dead-letters.index');
        Route::post('/dead-letters/{event}/review', [IntegrationController::class, 'reviewDeadLetter'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.dead-letters.review');
        Route::post('/events/{event}/retry', [IntegrationController::class, 'retry'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.retry');
        Route::post('/events/{event}/retry-placeholder', [IntegrationController::class, 'retryPlaceholder'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.retry.legacy');
        Route::post('/events/{event}/ignore', [IntegrationController::class, 'ignore'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.ignore');
        Route::post('/events/{event}/ignore-placeholder', [IntegrationController::class, 'ignorePlaceholder'])
            ->middleware('perm:inventory.integration.retry')->name('api.v1.integration.events.ignore.legacy');
    });
});
