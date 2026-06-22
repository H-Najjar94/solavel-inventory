<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBarcode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Create-item flow. The form sends BLANK strings for optional numeric fields
 * (reorder_point/purchase_price/sales_price) — these map to DECIMAL columns and
 * MySQL rejects '' (SQLSTATE 1366), which broke creation. StoreItemRequest now
 * coerces blanks to null in prepareForValidation. These tests drive the real
 * request → validation → store path and prove the item is created and visible.
 */
class ItemCreateTest extends TestCase
{
    use TenantAware;

    /** Run the form payload through the real Store request + controller. */
    private function createViaRequest(array $payload): array
    {
        $req = StoreItemRequest::create('/api/v1/items', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved(); // prepareForValidation + rules + domain rules

        return app(ItemController::class)->store($req)->getData(true);
    }

    private function formPayload(array $over = []): array
    {
        // Mirrors ItemFormPage's EMPTY defaults: blank optional numerics.
        return array_merge([
            'name' => 'Widget', 'sku' => 'W-1', 'barcode' => '', 'item_type' => 'inventory',
            'category_id' => null, 'brand_id' => null, 'base_unit_id' => null, 'preferred_supplier_id' => null,
            'purchase_price' => '', 'sales_price' => '', 'costing_method' => 'average', 'reorder_point' => '',
            'is_active' => true, 'track_lot' => false, 'track_serial' => false, 'track_expiry' => false, 'notes' => '',
        ], $over);
    }

    #[Test]
    public function creating_an_item_with_blank_optional_numeric_fields_succeeds(): void
    {
        $this->useTenantA();

        $resp = $this->createViaRequest($this->formPayload());

        // Was the regression: blank '' on a DECIMAL column → SQLSTATE 1366.
        $this->assertSame('W-1', $resp['data']['sku']);
        $item = Item::query()->where('sku', 'W-1')->firstOrFail();
        // Nullable fields → null; NOT NULL price columns → 0 (the real bug).
        $this->assertNull($item->reorder_point);
        $this->assertSame('0.0000', (string) $item->purchase_price);
        $this->assertSame('0.0000', (string) $item->sales_price);
    }

    #[Test]
    public function creating_an_item_with_post_middleware_null_prices_succeeds(): void
    {
        // This is the EXACT production case: the global ConvertEmptyStringsToNull
        // middleware has already turned blank prices into null BEFORE the request
        // reaches validation. The NOT NULL purchase_price/sales_price columns
        // rejected that null → "Column 'purchase_price' cannot be null" (the live
        // error in storage/logs). The fix must coerce null → 0 too.
        $this->useTenantA();

        $resp = $this->createViaRequest($this->formPayload([
            'sku' => 'IPHONE-1', 'name' => 'iphone',
            'purchase_price' => null, 'sales_price' => null, 'reorder_point' => null,
        ]));

        $this->assertSame('IPHONE-1', $resp['data']['sku']);
        $item = Item::query()->where('sku', 'IPHONE-1')->firstOrFail();
        $this->assertSame('0.0000', (string) $item->purchase_price);
        $this->assertSame('0.0000', (string) $item->sales_price);
        $this->assertNull($item->reorder_point);
    }

    #[Test]
    public function created_item_appears_in_the_item_list_and_detail(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['sku' => 'W-LIST', 'name' => 'Listed Widget']));

        // List (index) shows it.
        $list = app(ItemController::class)->index(\Illuminate\Http\Request::create('/', 'GET'))->getData(true);
        $skus = array_column($list['data'], 'sku');
        $this->assertContains('W-LIST', $skus);

        // Detail (show) loads it.
        $item = Item::query()->where('sku', 'W-LIST')->firstOrFail();
        $detail = app(ItemController::class)->show($item)->getData(true)['data'];
        $this->assertSame('Listed Widget', $detail['item']['name']);
    }

    #[Test]
    public function a_provided_barcode_creates_a_primary_barcode_row(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['sku' => 'W-BC', 'barcode' => '123456']));

        $item = Item::query()->where('sku', 'W-BC')->firstOrFail();
        $bc = ItemBarcode::query()->where('item_id', $item->id)->first();
        $this->assertNotNull($bc);
        $this->assertSame('123456', $bc->barcode);
        $this->assertSame('primary', $bc->type);
    }

    #[Test]
    public function numbers_entered_as_values_are_still_saved(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload([
            'sku' => 'W-NUM', 'reorder_point' => '5', 'purchase_price' => '2.50', 'sales_price' => '4',
        ]));

        $item = Item::query()->where('sku', 'W-NUM')->firstOrFail();
        $this->assertSame('5.0000', (string) $item->reorder_point);
        $this->assertSame('2.5000', (string) $item->purchase_price);
    }

    #[Test]
    public function full_ui_flow_create_then_opening_stock_reflects_on_hand(): void
    {
        // Mirrors the redesigned form's submit(): create the item (prod payload
        // with null prices), then seed opening stock via the same endpoints the
        // UI calls (createOpeningStock draft → postOpeningStock).
        $this->useTenantA();
        $wh = \Tests\Support\StockTestFactory::warehouse(['name' => 'Confirm WH']);

        $created = $this->createViaRequest($this->formPayload([
            'sku' => 'FLOW-1', 'name' => 'Flow item', 'purchase_price' => null, 'sales_price' => null,
        ]));
        $itemId = $created['data']['id'];

        // Opening stock: 6 @ 3.00 (the UI's maybePostOpeningStock path).
        $os = app(\App\Services\Documents\OpeningStockService::class);
        $draft = $os->createDraft(
            ['entry_number' => 'OPEN-FLOW-1', 'warehouse_id' => $wh->id],
            [['item_id' => $itemId, 'quantity' => '6.0000', 'unit_cost' => '3.0000']]
        );
        $os->post($draft);

        $onHand = \App\Models\Tenant\StockBalance::query()
            ->where('item_id', $itemId)->where('warehouse_id', $wh->id)->value('on_hand_qty');
        $this->assertSame('6.0000', $onHand, 'opening stock entered on create must reflect on hand');
    }

    #[Test]
    public function duplicate_sku_in_the_same_org_is_rejected_with_a_clear_error(): void
    {
        $this->useTenantA();
        $this->createViaRequest($this->formPayload(['sku' => 'DUP']));

        try {
            $this->createViaRequest($this->formPayload(['sku' => 'DUP', 'name' => 'Second']));
            $this->fail('expected a validation error for duplicate SKU');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
    }
}
