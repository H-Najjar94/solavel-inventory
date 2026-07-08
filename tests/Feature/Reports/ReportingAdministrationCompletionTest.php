<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\V1\CustomRoleController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryAuditController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Models\Tenant\DashboardLayout;
use App\Models\Tenant\InventoryAlert;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventoryCurrencyRate;
use App\Models\Tenant\InventoryScheduledReport;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\WarehouseReorderRule;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\OpeningStockService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;
use App\Tenancy\OrganizationContext;

class ReportingAdministrationCompletionTest extends TestCase
{
    use TenantAware;

    private function user(int $id = 7001): object
    {
        return (object) ['id' => $id];
    }

    private function request(string $uri, string $method = 'GET', array $payload = [], int $userId = 7001): Request
    {
        $request = Request::create($uri, $method, $payload);
        $request->setUserResolver(fn () => $this->user($userId));

        return $request;
    }

    private function postOpening(int $itemId, int $warehouseId, string $qty = '10', string $cost = '5'): void
    {
        $entry = app(OpeningStockService::class)->createDraft(
            ['entry_number' => 'OS-'.uniqid(), 'warehouse_id' => $warehouseId],
            [['item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => $cost]]
        );
        app(OpeningStockService::class)->post($entry);
    }

    #[Test]
    public function dashboard_layout_and_exception_alerts_are_persisted_and_acknowledged(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'ALRT']);
        $item = F::averageItem(['sku' => 'ALERT-ITEM', 'reorder_point' => '5']);
        WarehouseReorderRule::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'reorder_point' => '5',
            'reorder_qty' => '10',
        ]);
        $this->postOpening($item->id, $warehouse->id, '2', '4');

        $controller = app(DashboardController::class);
        $saved = $controller->saveLayout($this->request('/dashboard/layout', 'PUT', [
            'layout' => [
                ['key' => 'alerts', 'label' => 'Exception alerts', 'visible' => true],
                ['key' => 'kpis', 'label' => 'KPIs', 'visible' => false],
            ],
        ]))->getData(true)['data']['layout'];

        $this->assertSame('alerts', $saved[0]['key']);
        $this->assertSame(1, DashboardLayout::query()->where('user_id', 7001)->count());

        $dashboard = $controller->index($this->request('/dashboard'), app(\App\Services\Reports\DashboardMetricsService::class))->getData(true)['data'];
        $this->assertNotEmpty($dashboard['alerts']);
        $this->assertTrue(InventoryAlert::query()->where('type', 'low_stock')->exists());

        $alert = InventoryAlert::query()->firstOrFail();
        $controller->acknowledgeAlert($this->request('/dashboard/alerts/'.$alert->id.'/acknowledge', 'POST'), $alert);
        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertSame(7001, $alert->fresh()->acknowledged_by);
    }

    #[Test]
    public function ledger_viewer_activity_audit_and_bulk_item_actions_are_available(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'LDG']);
        $itemA = F::averageItem(['sku' => 'BULK-A']);
        $itemB = F::averageItem(['sku' => 'BULK-B']);
        $this->postOpening($itemA->id, $warehouse->id, '4', '3');

        $bulk = app(ItemController::class)->bulkUpdate($this->request('/items/bulk-update', 'POST', [
            'item_ids' => [$itemA->id, $itemB->id],
            'is_active' => false,
        ]))->getData(true)['data'];

        $this->assertSame(2, $bulk['updated']);
        $this->assertFalse((bool) $itemA->fresh()->is_active);
        $this->assertSame(2, InventoryAuditLog::query()->where('action', 'item.bulk_updated')->count());

        $ledger = app(\App\Http\Controllers\Api\V1\StockLedgerController::class)
            ->index($this->request('/ledger', 'GET', ['item_id' => $itemA->id]))
            ->getData(true)['data'];
        $this->assertNotEmpty($ledger);
        $this->assertSame('BULK-A', $ledger[0]['item_sku']);
        $this->assertSame('LDG', $ledger[0]['warehouse_code']);

        $audit = app(InventoryAuditController::class)
            ->index($this->request('/audit-logs', 'GET', ['action' => 'bulk_updated']))
            ->getData(true)['data'];
        $this->assertCount(2, $audit);
        $this->assertSame('item.bulk_updated', $audit[0]['action']);
    }

    #[Test]
    public function valuation_supports_as_at_multi_currency_and_scheduled_delivery(): void
    {
        $this->useTenantA();
        Mail::fake();
        $warehouse = F::warehouse(['code' => 'VAL']);
        $item = F::averageItem(['sku' => 'VAL-ITEM']);
        $this->postOpening($item->id, $warehouse->id, '10', '5');

        app(SettingsController::class)->storeCurrencyRate($this->request('/settings/currency-rates', 'POST', [
            'currency_code' => 'USD',
            'rate_to_base' => '3.75000000',
            'effective_date' => now()->toDateString(),
        ]));
        $this->assertTrue(InventoryCurrencyRate::query()->where('currency_code', 'USD')->exists());

        $report = app(InventoryReportService::class)->run('inventory-valuation', \App\Services\Reports\ReportFilters::fromArray([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'as_at' => now()->toDateString(),
            'currency' => 'USD',
        ]));
        $this->assertSame('USD', $report['summary']['value_currency']);
        $this->assertSame('50.00', $report['summary']['total_value']);
        $this->assertSame('13.33', $report['summary']['converted_total_value']);

        $controller = app(ReportController::class);
        $schedule = $controller->storeSchedule($this->request('/reports/schedules', 'POST', [
            'report_key' => 'inventory-valuation',
            'name' => 'Weekly valuation',
            'filters' => ['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'as_at' => now()->toDateString(), 'currency' => 'USD'],
            'recipients' => ['ops@example.com'],
            'frequency' => 'weekly',
            'format' => 'csv',
        ]))->getData(true)['data'];

        $run = $controller->runSchedule(InventoryScheduledReport::query()->findOrFail($schedule['id']))->getData(true)['data'];
        $this->assertSame('delivered', $run['schedule']['last_status']);
        $this->assertSame(1, InventoryScheduledReport::query()->count());
    }

    #[Test]
    public function custom_roles_define_permission_sets_and_override_central_mapping(): void
    {
        $this->useTenantA();
        $controller = app(CustomRoleController::class);
        $key = 'ledger_reviewer_'.strtolower(substr(uniqid(), -6));

        $role = $controller->store($this->request('/settings/custom-roles', 'POST', [
            'name' => 'Ledger reviewer',
            'key' => $key,
            'permissions' => ['inventory.view_dashboard', 'inventory.view_ledger'],
        ]))->getData(true)['data'];

        $controller->assign($this->request('/settings/custom-role-assignments', 'POST', [
            'user_id' => 9009,
            'role_id' => $role['id'],
        ], 7001));

        $permissions = new class(app(OrganizationContext::class)) extends InventoryPermissionService {
            protected function fetchCentralRole(int $userId, int $orgId): ?string
            {
                return null;
            }
        };
        $this->assertTrue($permissions->can($this->user(9009), 'inventory.view_ledger'));
        $this->assertFalse($permissions->can($this->user(9009), 'inventory.manage_items'));

        $payload = $controller->index($permissions)->getData(true)['data'];
        $this->assertCount(1, $payload['roles']);
        $this->assertCount(1, $payload['assignments']);
    }

    #[Test]
    public function report_exports_keep_screen_filters_for_as_at_currency(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'EXP']);
        $item = F::averageItem(['sku' => 'EXP-ITEM']);
        $this->postOpening($item->id, $warehouse->id, '2', '7');
        app(SettingsController::class)->storeCurrencyRate($this->request('/settings/currency-rates', 'POST', [
            'currency_code' => 'USD',
            'rate_to_base' => '3.50000000',
            'effective_date' => now()->toDateString(),
        ]));

        $result = app(InventoryReportService::class)->run('inventory-valuation', \App\Services\Reports\ReportFilters::fromArray([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'as_at' => now()->toDateString(),
            'currency' => 'USD',
        ]));
        $csv = app(ReportExportService::class)->csv($result);
        ob_start();
        $csv->sendContent();
        $body = ob_get_clean();

        $this->assertStringContainsString('value_currency', $body);
        $this->assertStringContainsString('converted_total_value', $body);
        $this->assertStringContainsString('USD', $body);
        $this->assertSame(1, StockLedger::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->count());
    }
}
