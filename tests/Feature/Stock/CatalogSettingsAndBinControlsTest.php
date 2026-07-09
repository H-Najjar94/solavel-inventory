<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemAttachmentController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\OpeningStockController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Requests\Api\StoreGoodsReceiptRequest;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\StoreOpeningStockRequest;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemAttachment;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\ItemBrand;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\ItemVariant;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPriceList;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Models\Tenant\WarehouseReorderRule;
use App\Models\Tenant\WarehouseBin;
use App\Models\Tenant\WarehouseZone;
use App\Services\Documents\OpeningStockService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class CatalogSettingsAndBinControlsTest extends TestCase
{
    use TenantAware;

    private function createItem(array $payload): Item
    {
        $req = StoreItemRequest::create('/api/v1/items', 'POST', array_merge([
            'sku' => 'CAT-'.uniqid(),
            'name' => 'Catalog item',
            'item_type' => 'inventory',
            'tracking_type' => 'none',
            'costing_method' => 'average',
            'purchase_price' => '',
            'sales_price' => '',
            'is_active' => true,
        ], $payload));
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        $response = app(ItemController::class)->store($req)->getData(true);

        return Item::query()->findOrFail($response['data']['id']);
    }

    private function formRequest(string $class, string $method, string $uri, array $payload): mixed
    {
        $request = $class::create($uri, $method, $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    #[Test]
    public function item_physical_and_planning_fields_are_validated_and_saved(): void
    {
        $this->useTenantA();

        $item = $this->createItem([
            'sku' => 'PHYS-1',
            'weight' => '2.5000',
            'length' => '10',
            'width' => '4',
            'height' => '3',
            'min_stock' => '5',
            'max_stock' => '20',
            'safety_stock' => '2',
        ])->fresh();

        $this->assertSame('2.5000', (string) $item->weight);
        $this->assertSame('10.0000', (string) $item->length);
        $this->assertSame('5.0000', (string) $item->min_stock);
        $this->assertSame('20.0000', (string) $item->max_stock);
        $this->assertSame('2.0000', (string) $item->safety_stock);

        $shown = app(ItemController::class)->show($item)->getData(true)['data'];
        $this->assertSame('Small carton', $shown['package_recommendation']['label']);
    }

    #[Test]
    public function category_hierarchy_and_unit_conversion_are_editable_from_settings(): void
    {
        $this->useTenantA();

        $settings = app(SettingsController::class);
        $parent = $settings->storeCategory(Request::create('/settings/categories', 'POST', ['name' => 'Electronics']))->getData(true)['data'];
        $child = $settings->storeCategory(Request::create('/settings/categories', 'POST', [
            'name' => 'Phones',
            'parent_id' => $parent['id'],
        ]))->getData(true)['data'];

        $this->assertSame($parent['id'], $child['parent_id']);
        $this->assertSame(1, $child['level']);

        $each = Unit::query()->create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
        $case = Unit::query()->create(['code' => 'CASE', 'name' => 'Case', 'kind' => 'count']);

        $conversion = $settings->storeUnitConversion(Request::create('/settings/unit-conversions', 'POST', [
            'from_unit_id' => $case->id,
            'to_unit_id' => $each->id,
            'factor' => '12',
        ]))->getData(true)['data'];

        $this->assertSame($case->id, $conversion['from_unit_id']);
        $this->assertSame($each->id, $conversion['to_unit_id']);
        $this->assertSame('12.00000000', (string) UnitConversion::query()->findOrFail($conversion['id'])->factor);

        $payload = $settings->show()->getData(true)['data'];
        $this->assertNotEmpty($payload['unit_conversions']);
        $this->assertTrue(ItemCategory::query()->where('parent_id', $parent['id'])->where('name', 'Phones')->exists());
    }

    #[Test]
    public function category_and_brand_can_be_edited_and_assigned_to_items(): void
    {
        $this->useTenantA();

        $settings = app(SettingsController::class);
        $parent = $settings->storeCategory(Request::create('/settings/categories', 'POST', ['name' => 'Hardware']))->getData(true)['data'];
        $child = $settings->storeCategory(Request::create('/settings/categories', 'POST', [
            'name' => 'Fasteners',
            'parent_id' => $parent['id'],
        ]))->getData(true)['data'];
        $renamed = $settings->updateCategory(Request::create("/settings/categories/{$child['id']}", 'PUT', [
            'name' => 'Stainless Fasteners',
            'parent_id' => null,
            'is_active' => true,
        ]), $child['id'])->getData(true)['data'];

        $brand = $settings->storeBrand(Request::create('/settings/brands', 'POST', ['name' => 'Acme']))->getData(true)['data'];
        $brand = $settings->updateBrand(Request::create("/settings/brands/{$brand['id']}", 'PUT', [
            'name' => 'Acme Industrial',
            'is_active' => true,
        ]), $brand['id'])->getData(true)['data'];

        $item = $this->createItem([
            'sku' => 'CAT-BRAND',
            'category_id' => $renamed['id'],
            'brand_id' => $brand['id'],
        ])->fresh();

        $this->assertSame('Stainless Fasteners', ItemCategory::query()->findOrFail($item->category_id)->name);
        $this->assertNull(ItemCategory::query()->findOrFail($item->category_id)->parent_id);
        $this->assertSame('Acme Industrial', ItemBrand::query()->findOrFail($item->brand_id)->name);
    }

    #[Test]
    public function inventory_policy_settings_are_saved_for_negative_stock_and_costing(): void
    {
        $this->useTenantA();

        app(SettingsController::class)->updateSettings(Request::create('/settings', 'PUT', [
            'default_costing_method' => 'fifo',
            'allow_negative_stock' => true,
            'picking_policy' => 'fefo',
        ]));

        $settings = InventorySetting::query()->firstOrFail();
        $this->assertSame('fifo', $settings->default_costing_method);
        $this->assertTrue((bool) $settings->allow_negative_stock);
        $this->assertSame('fefo', $settings->picking_policy);
    }

    #[Test]
    public function adjustment_reason_codes_are_configured_and_validated(): void
    {
        $this->useTenantA();

        app(SettingsController::class)->storeAdjustmentReasonCode(Request::create('/settings/adjustment-reason-codes', 'POST', [
            'code' => 'Cycle Count',
            'label' => 'Cycle count variance',
        ]));

        $settings = InventorySetting::query()->firstOrFail();
        $reason = collect($settings->adjustment_reason_codes)->firstWhere('code', 'cycle_count');
        $this->assertNotNull($reason);
        $this->assertSame('Cycle count variance', $reason['label']);

        $warehouse = F::warehouse(['code' => 'RSN']);
        $item = F::averageItem(['sku' => 'RSN-ITEM']);
        $request = StoreStockAdjustmentRequest::create('/adjustments', 'POST', [
            'adjustment_number' => '',
            'warehouse_id' => $warehouse->id,
            'reason_code' => 'unapproved',
            'lines' => [[
                'item_id' => $item->id,
                'direction' => 'increase',
                'quantity' => '1',
                'unit_cost' => '1',
            ]],
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));

        $this->expectException(ValidationException::class);
        $request->validateResolved();
    }

    #[Test]
    public function per_warehouse_reorder_rules_are_saved_from_settings(): void
    {
        $this->useTenantA();

        $item = F::averageItem(['sku' => 'REORDER-WH']);
        $warehouse = F::warehouse(['code' => 'REORD']);

        $response = app(SettingsController::class)->storeWarehouseReorderRule(Request::create('/settings/warehouse-reorder-rules', 'POST', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'reorder_point' => '6',
            'reorder_qty' => '12',
            'min_stock' => '5',
            'max_stock' => '20',
            'safety_stock' => '3',
        ]))->getData(true)['data'];

        $rule = WarehouseReorderRule::query()->findOrFail($response['id']);
        $this->assertSame($item->id, $rule->item_id);
        $this->assertSame($warehouse->id, $rule->warehouse_id);
        $this->assertSame('6.0000', (string) $rule->reorder_point);
        $this->assertSame('12.0000', (string) $rule->reorder_qty);
        $this->assertSame('5.0000', (string) $rule->min_stock);
        $this->assertSame('20.0000', (string) $rule->max_stock);
        $this->assertSame('3.0000', (string) $rule->safety_stock);
    }

    #[Test]
    public function low_stock_report_uses_warehouse_reorder_rules_before_item_defaults(): void
    {
        $this->useTenantA();

        $item = F::averageItem(['sku' => 'REORDER-RPT', 'reorder_point' => '2', 'reorder_qty' => '4']);
        $warehouse = F::warehouse(['code' => 'RPT']);
        WarehouseReorderRule::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'reorder_point' => '10',
            'reorder_qty' => '25',
            'min_stock' => '8',
            'max_stock' => '30',
            'safety_stock' => '4',
        ]);

        $entry = app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'REORDER-RPT', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '6', 'unit_cost' => '1']]
        );
        app(OpeningStockService::class)->post($entry);

        $report = app(InventoryReportService::class)->run('low-stock', new ReportFilters(warehouseId: $warehouse->id));
        $row = collect($report['rows'])->firstWhere('sku', 'REORDER-RPT');

        $this->assertNotNull($row);
        $this->assertSame('10.0000', (string) $row->reorder_point);
        $this->assertSame('25.0000', (string) $row->reorder_qty);
        $this->assertSame('4.0000', (string) $row->shortage_qty);
    }

    #[Test]
    public function multiple_barcodes_can_be_added_and_scanned_to_lookup_the_item(): void
    {
        $this->useTenantA();
        $item = $this->createItem(['sku' => 'SCAN-1', 'name' => 'Scanner item', 'barcode' => 'PRIMARY-1']);

        $controller = app(ItemController::class);
        $added = $controller->storeBarcode(Request::create("/items/{$item->id}/barcodes", 'POST', [
            'barcode' => 'UPC-0001',
            'type' => 'UPC',
        ]), $item)->getData(true)['data'];

        $lookup = $controller->barcodeLookup(Request::create('/items/barcode/lookup', 'GET', [
            'barcode' => 'UPC-0001',
        ]))->getData(true)['data'];

        $this->assertSame($added['id'], $lookup['id']);
        $this->assertSame('Scanner item', $lookup['item']['name']);
        $this->assertSame(2, ItemBarcode::query()->where('item_id', $item->id)->count());
    }

    #[Test]
    public function item_variants_supplier_prices_attachments_and_labels_are_managed(): void
    {
        $this->useTenantA();
        Storage::fake('local');

        $supplier = Supplier::query()->create(['code' => 'SUP-CAT', 'name' => 'Catalog Supplier']);
        $item = $this->createItem(['sku' => 'VAR-PARENT', 'name' => 'Variant Parent']);
        $controller = app(ItemController::class);

        $variant = $controller->storeVariant(Request::create("/items/{$item->id}/variants", 'POST', [
            'sku' => 'VAR-PARENT-RED',
            'variant_attributes' => ['Color' => 'Red'],
            'barcode_primary' => 'VAR-RED-001',
            'purchase_price' => '8',
            'sales_price' => '12',
        ]), $item)->getData(true)['data'];

        $price = $controller->storeSupplierPrice(Request::create("/items/{$item->id}/supplier-prices", 'POST', [
            'supplier_id' => $supplier->id,
            'supplier_sku' => 'SUP-RED',
            'unit_cost' => '7.5000',
            'minimum_qty' => '6',
            'currency_code' => 'SAR',
        ]), $item)->getData(true)['data'];

        $upload = Request::create("/items/{$item->id}/attachments", 'POST', [], [], [
            'attachment' => UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf'),
        ]);
        $attachment = app(ItemAttachmentController::class)->store($upload, $item)->getData(true)['data'];
        $labels = $controller->labelSheet($item)->getData(true)['data']['labels'];

        $this->assertSame('VAR-PARENT-RED', $variant['sku']);
        $this->assertTrue(ItemVariant::query()->where('sku', 'VAR-PARENT-RED')->exists());
        $this->assertSame($supplier->id, $price['supplier_id']);
        $this->assertTrue(SupplierPriceList::query()->where('item_id', $item->id)->where('supplier_id', $supplier->id)->exists());
        $this->assertSame('spec.pdf', $attachment['name']);
        $this->assertTrue(ItemAttachment::query()->where('item_id', $item->id)->where('name', 'spec.pdf')->exists());
        $this->assertSame('VAR-RED-001', $labels[0]['barcode']);
    }

    #[Test]
    public function unit_conversions_are_used_by_purchase_receipt_and_opening_stock_documents(): void
    {
        $this->useTenantA();

        $each = Unit::query()->create(['code' => 'EA-UC', 'name' => 'Each UC', 'kind' => 'count']);
        $case = Unit::query()->create(['code' => 'CASE-UC', 'name' => 'Case UC', 'kind' => 'count']);
        UnitConversion::query()->create([
            'from_unit_id' => $case->id,
            'to_unit_id' => $each->id,
            'factor' => '12',
        ]);
        $supplier = Supplier::query()->create(['code' => 'SUP-UC', 'name' => 'Unit Supplier']);
        $warehouse = F::warehouse(['code' => 'UC']);
        $item = $this->createItem(['sku' => 'UNIT-CONV', 'base_unit_id' => $each->id, 'costing_method' => 'average']);

        $poRequest = $this->formRequest(StorePurchaseOrderRequest::class, 'POST', '/purchase-orders', [
            'po_number' => '',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'ordered_qty' => '2',
                'entered_qty' => '2',
                'entered_unit_id' => $case->id,
                'unit_price' => '120',
            ]],
        ]);
        $po = app(PurchaseOrderController::class)->store($poRequest)->getData(true)['data'];
        $poLine = \App\Models\Tenant\PurchaseOrderLine::query()->where('purchase_order_id', $po['id'])->firstOrFail();
        $this->assertSame('24.0000', (string) $poLine->ordered_qty);
        $this->assertSame('2.0000', (string) $poLine->entered_qty);
        $this->assertSame('10.0000', (string) $poLine->unit_price);

        app(PurchaseOrderController::class)->approve(\App\Models\Tenant\PurchaseOrder::query()->findOrFail($po['id']));
        $grnRequest = $this->formRequest(StoreGoodsReceiptRequest::class, 'POST', '/goods-receipts', [
            'grn_number' => '',
            'purchase_order_id' => $po['id'],
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_id' => $poLine->id,
                'item_id' => $item->id,
                'received_qty' => '2',
                'accepted_qty' => '2',
                'entered_qty' => '2',
                'entered_unit_id' => $case->id,
                'unit_cost' => '120',
            ]],
        ]);
        $grn = app(GoodsReceiptController::class)->store($grnRequest)->getData(true)['data'];
        app(GoodsReceiptController::class)->post(\App\Models\Tenant\GoodsReceipt::query()->findOrFail($grn['id']));

        $balance = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $ledger = StockLedger::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->latest('id')->firstOrFail();
        $this->assertSame('24.0000', (string) $balance->on_hand_qty);
        $this->assertSame('10.0000', (string) $ledger->unit_cost);

        $openingRequest = $this->formRequest(StoreOpeningStockRequest::class, 'POST', '/opening-stock', [
            'entry_number' => '',
            'warehouse_id' => $warehouse->id,
            'opening_date' => now()->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => '1',
                'entered_qty' => '1',
                'entered_unit_id' => $case->id,
                'unit_cost' => '60',
            ]],
        ]);
        $entry = app(OpeningStockController::class)->store($openingRequest)->getData(true)['data'];
        app(OpeningStockController::class)->post(\App\Models\Tenant\OpeningStockEntry::query()->findOrFail($entry['id']));

        $balance->refresh();
        $this->assertSame('36.0000', (string) $balance->on_hand_qty);
        $this->assertTrue(StockLedger::query()->where('source_type', \App\Models\Tenant\OpeningStockEntry::class)->where('source_id', $entry['id'])->where('quantity', '12.0000')->exists());
    }

    #[Test]
    public function bin_capacity_is_enforced_by_the_canonical_stock_writer(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'CAP']);
        $zone = WarehouseZone::query()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A',
            'name' => 'Aisle A',
        ]);
        $bin = WarehouseBin::query()->create([
            'warehouse_id' => $warehouse->id,
            'zone_id' => $zone->id,
            'code' => 'A-01',
            'capacity' => '5',
            'is_active' => true,
        ]);
        $item = F::averageItem(['sku' => 'BIN-CAP']);
        $service = app(OpeningStockService::class);

        $ok = $service->createDraft(
            ['entry_number' => 'BIN-OK', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'bin_id' => $bin->id, 'quantity' => '5', 'unit_cost' => '1']]
        );
        $service->post($ok);

        $tooMuch = $service->createDraft(
            ['entry_number' => 'BIN-BLOCK', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'bin_id' => $bin->id, 'quantity' => '1', 'unit_cost' => '1']]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity exceeded');
        $service->post($tooMuch);
    }
}
