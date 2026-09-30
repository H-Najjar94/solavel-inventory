<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventorySetting;
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
