<?php

namespace Tests\Feature\Documents;

use App\Http\Controllers\Api\V1\OpeningStockController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Requests\Api\StoreOpeningStockRequest;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Slice 1 — Purchase Order + Opening Stock create from the real UI payload.
 * Production failures fixed:
 *  - PO: blank po_number ("required") + blank order_date → 1292 on the DATE col.
 *  - Opening Stock: blank entry_number forced manual entry (older code: 1364).
 * Now both numbers are server-generated and blank dates are coerced.
 */
class DocumentNumberingTest extends TestCase
{
    use TenantAware;

    private function createPo(array $payload): array
    {
        $req = StorePurchaseOrderRequest::create('/api/v1/purchase-orders', 'POST', $payload);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();

        return app(PurchaseOrderController::class)->store($req)->getData(true);
    }

    private function poPayload(array $over = []): array
    {
        // Exactly what the form sends, with blanks (the production failure shape).
        return array_merge([
            'po_number' => '', 'supplier_id' => null, 'warehouse_id' => $this->wh,
            'order_date' => '', 'expected_date' => '', 'currency_code' => '', 'notes' => '',
            'lines' => [['item_id' => $this->item, 'ordered_qty' => '3.0000', 'unit_price' => '']],
        ], $over);
    }

    private int $wh;

    private int $item;

    private function seedDocs(): void
    {
        $this->wh = F::warehouse()->id;
        $this->item = F::item()->id;
    }

    // ── Purchase Order ──

    #[Test]
    public function po_create_with_blank_number_and_blank_dates_succeeds_and_auto_numbers(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        $resp = $this->createPo($this->poPayload());

        $this->assertSame('PO-000001', $resp['data']['po_number'], 'po_number is server-generated');
        $po = PurchaseOrder::query()->where('po_number', 'PO-000001')->firstOrFail();
        $this->assertSame('draft', $po->status);
        $this->assertNotNull($po->order_date, 'blank order_date defaulted, did not crash');
        $this->assertCount(1, $po->fresh('lines')->lines);
    }

    #[Test]
    public function po_numbers_increment_per_org_and_a_typed_number_is_respected(): void
    {
        $this->useTenantA();
        $this->seedDocs();
        $this->createPo($this->poPayload());                 // PO-000001
        $this->createPo($this->poPayload());                 // PO-000002
        $typed = $this->createPo($this->poPayload(['po_number' => 'CUSTOM-1']));

        $this->assertSame('CUSTOM-1', $typed['data']['po_number']);
        $this->assertNotNull(PurchaseOrder::query()->where('po_number', 'PO-000002')->first());
    }

    #[Test]
    public function po_appears_in_list_and_opens_in_detail(): void
    {
        $this->useTenantA();
        $this->seedDocs();
        $created = $this->createPo($this->poPayload());
        $id = $created['data']['id'];

        $list = app(PurchaseOrderController::class)->index(Request::create('/', 'GET'))->getData(true);
        $this->assertContains('PO-000001', array_column($list['data'], 'po_number'));

        $detail = app(PurchaseOrderController::class)->show(PurchaseOrder::query()->findOrFail($id))->getData(true)['data'];
        $this->assertSame('PO-000001', ($detail['purchase_order'] ?? $detail)['po_number'] ?? $detail['po_number'] ?? null);
    }

    #[Test]
    public function po_missing_warehouse_gives_a_field_level_error_not_a_500(): void
    {
        $this->useTenantA();
        $this->seedDocs();

        try {
            $this->createPo($this->poPayload(['warehouse_id' => null]));
            $this->fail('expected a validation error for missing warehouse');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('warehouse_id', $e->errors());
        }
    }

    // ── Opening Stock ──

    #[Test]
    public function opening_stock_create_with_blank_number_auto_generates_and_posts_stock(): void
    {
        $this->useTenantA();
        $wh = F::warehouse();
        $item = F::item();

        // Create via the request path (blank entry_number, like the UI).
        $req = StoreOpeningStockRequest::create('/api/v1/opening-stock', 'POST', [
            'entry_number' => '', 'opening_date' => '', 'warehouse_id' => $wh->id, 'notes' => '',
            'lines' => [['item_id' => $item->id, 'quantity' => '5.0000', 'unit_cost' => '2.0000']],
        ]);
        $req->setContainer(app())->setRedirector(app('redirect'));
        $req->validateResolved();
        $resp = app(OpeningStockController::class)->store($req)->getData(true);

        $this->assertSame('OS-000001', $resp['data']['entry_number'], 'entry_number is server-generated');
        $entry = OpeningStockEntry::query()->where('entry_number', 'OS-000001')->firstOrFail();

        // Posting the draft moves stock on hand.
        app(OpeningStockService::class)->post($entry);
        $onHand = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $wh->id)->value('on_hand_qty');
        $this->assertSame('5.0000', $onHand);
    }

    #[Test]
    public function document_numbers_are_independent_per_org(): void
    {
        // ORG_A issues OS-000001…
        $this->useTenantA();
        $whA = F::warehouse(); $itemA = F::item();
        app(OpeningStockService::class)->createDraft(
            ['warehouse_id' => $whA->id], [['item_id' => $itemA->id, 'quantity' => '1', 'unit_cost' => '1']]
        );

        // …ORG_B starts its own sequence at OS-000001 too (per-org).
        $this->useTenantB();
        $whB = F::warehouse(); $itemB = F::item();
        $bEntry = app(OpeningStockService::class)->createDraft(
            ['warehouse_id' => $whB->id], [['item_id' => $itemB->id, 'quantity' => '1', 'unit_cost' => '1']]
        );
        $this->assertSame('OS-000001', $bEntry->entry_number);
    }
}
