<?php

namespace Tests\Feature\Settings;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TraceabilityController;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Services\Reports\ReportFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class ExpiryWarningSettingTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function expiry_warning_setting_persists_is_audited_and_drives_default_reports(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false, 'expiry_warning_days' => 30],
        );
        $item = F::lotItem(['sku' => 'EXP-WARN']);
        F::lot($item, ['lot_code' => 'EXP-IN-20', 'expiry_date' => now()->addDays(20)->toDateString(), 'status' => 'active']);
        F::lot($item, ['lot_code' => 'EXP-OUT-60', 'expiry_date' => now()->addDays(60)->toDateString(), 'status' => 'active']);

        $before = app(TraceabilityController::class)->expiryRiskSummary(Request::create('/expiry-risk', 'GET'))->getData(true)['data'];
        $this->assertSame(30, $before['within_days']);
        $this->assertSame(1, $before['at_risk']);

        app(SettingsController::class)->updateSettings(Request::create('/settings', 'PUT', ['expiry_warning_days' => 90]));
        $after = app(TraceabilityController::class)->expiryRiskSummary(Request::create('/expiry-risk', 'GET'))->getData(true)['data'];
        $this->assertSame(90, $after['within_days']);
        $this->assertSame(2, $after['at_risk']);
        $this->assertSame(90, ReportFilters::fromRequest(Request::create('/reports/expiry-risk', 'GET'))->days);
        $this->assertTrue(InventoryAuditLog::query()->where('action', 'inventory.settings.updated')->exists());

        $this->useTenantB();
        $this->assertNull(InventorySetting::query()->first());

        $this->useTenantA();
        app(SettingsController::class)->updateSettings(Request::create('/settings', 'PUT', ['expiry_warning_days' => 30]));
        $this->assertSame(30, (int) InventorySetting::query()->value('expiry_warning_days'));
    }
}
