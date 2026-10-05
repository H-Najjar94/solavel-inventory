<?php

namespace Tests\Feature\Reports;

use App\Models\Tenant\InventoryAlert;
use App\Models\Tenant\InventorySetting;
use App\Services\Access\InventoryPermissionService;
use App\Services\Alerts\InventoryAlertService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class AlertAcknowledgementTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function acknowledgement_survives_refresh_until_the_condition_changes(): void
    {
        $this->useTenantA();
        $permissions = \Mockery::mock(InventoryPermissionService::class);
        $permissions->shouldReceive('can')->andReturnTrue();
        $this->app->instance(InventoryPermissionService::class, $permissions);
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $warehouse = F::warehouse(['code' => 'ACK-WH']);
        $item = F::averageItem(['sku' => 'ACK-ITEM', 'reorder_point' => '10']);
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'ACK-OS', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '1']]));
        $alerts = app(InventoryAlertService::class);
        $key = "low-stock:{$item->id}:{$warehouse->id}";

        $alerts->refresh();
        $alert = InventoryAlert::query()->where('alert_key', $key)->firstOrFail();
        $this->assertSame('open', $alert->status);
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => 7])->save();

        $alerts->refresh();
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertSame(7, (int) $alert->fresh()->acknowledged_by);

        // Condition changes: low → out of stock reopens the alert.
        $adjustments = app(StockAdjustmentService::class);
        $adjustments->post($adjustments->createDraft(['adjustment_number' => 'ACK-OUT', 'warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '4']]));
        $alerts->refresh();
        $this->assertSame('out_of_stock', $alert->fresh()->type);
        $this->assertSame('open', $alert->fresh()->status);
        $this->assertNull($alert->fresh()->acknowledged_by);
    }
}
