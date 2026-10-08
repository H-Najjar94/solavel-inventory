<?php

namespace Tests\Feature\Stock;

use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\InventorySetting;
use Illuminate\Validation\ValidationException;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\OpeningStockService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** SC-UAE-042: service / non-inventory items never carry stock movements. */
class NonStockItemMovementTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false]
        );
    }

    #[Test]
    public function posting_stock_for_a_service_or_non_inventory_item_is_rejected(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $os = app(OpeningStockService::class);

        foreach (['service', 'non_inventory'] as $type) {
            $item = F::item(['item_type' => $type, 'tracking_type' => 'none']);
            $draft = $os->createDraft(['entry_number' => 'SVC-'.$type, 'warehouse_id' => $wh->id],
                [['item_id' => $item->id, 'quantity' => '1.0000', 'unit_cost' => '10.0000']]);
            try {
                $os->post($draft);
                $this->fail("Stock posted for a {$type} item.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString((string) $item->sku, $e->getMessage());
            }
            $this->assertFalse(StockBalance::query()->where('item_id', $item->id)->exists());
        }
    }

    #[Test]
    public function adjustment_and_transfer_drafts_reject_non_inventory_items_on_save(): void
    {
        $this->boot();
        InventorySetting::query()->where('organization_id', TenantTestManager::ORG_A)->update(['adjustment_reason_codes' => null]);
        $wh = F::warehouse();
        $other = F::warehouse();
        $service = F::item(['item_type' => 'service', 'tracking_type' => 'none']);
        $stock = F::item();

        $payloads = [
            StoreStockAdjustmentRequest::class => fn ($itemId) => ['warehouse_id' => $wh->id, 'lines' => [['item_id' => $itemId, 'direction' => 'increase', 'quantity' => '1', 'unit_cost' => '1']]],
            StoreStockTransferRequest::class => fn ($itemId) => ['from_warehouse_id' => $wh->id, 'to_warehouse_id' => $other->id, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]],
        ];
        foreach ($payloads as $class => $payload) {
            $request = $class::create('/', 'POST', $payload($service->id));
            $request->setContainer(app())->setRedirector(app('redirect'));
            try {
                $request->validateResolved();
                $this->fail("{$class} accepted a service item.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0.item_id', $e->errors());
                $this->assertStringContainsString((string) $service->sku, $e->errors()['lines.0.item_id'][0]);
            }

            $ok = $class::create('/', 'POST', $payload($stock->id));
            $ok->setContainer(app())->setRedirector(app('redirect'));
            try {
                $ok->validateResolved();
            } catch (ValidationException $e) {
                $this->assertArrayNotHasKey('lines.0.item_id', $e->errors(), "{$class} rejected an inventory item.");
            }
        }
    }

    #[Test]
    public function inventory_items_still_post_normally(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::item();
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'INV-OK', 'warehouse_id' => $wh->id],
            [['item_id' => $item->id, 'quantity' => '2.0000', 'unit_cost' => '5.0000']]));
        $this->assertTrue(StockBalance::query()->where('item_id', $item->id)->exists());
    }
}
