<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\LandedCost;
use App\Models\Tenant\StockBalance;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\LandedCostAvailability;
use App\Services\Documents\LandedCostService;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Integration\LandedCostWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Batch 10 owner decision 2: landed costs are available automatically wherever the
 * plan includes stock.landed_costs; an explicit organization opt-out is stored and
 * respected; turning anything on or off never allocates, revalues or journals; a
 * standalone organization posts without any account mapping.
 */
class LandedCostAvailabilityTest extends TestCase
{
    use TenantAware;

    /** Binds an availability service that reads a fixed entitlement snapshot. */
    private function plan(?array $snapshot): LandedCostAvailability
    {
        config(['inventory_entitlements.feature_enforcement' => true]);
        $cache = new class($snapshot) extends EntitlementsCache {
            public function __construct(private ?array $snapshot) {}
            public function currentClientId(): ?int { return 990010; }
            public function getProjectSnapshot(int $clientId, ?string $projectSlug = null): ?array { return $this->snapshot; }
        };
        $service = new LandedCostAvailability(new InventoryCommercialEntitlementService($cache));
        $this->app->instance(LandedCostAvailability::class, $service);

        return $service;
    }

    /** The snapshot shape Central pushes: tier + flags (release eligibility included). */
    private function tier(string $tier, bool $landedCosts): array
    {
        return ['effective_tier' => $tier, 'access_mode' => $tier === 'free' ? 'free' : 'full', 'reason_code' => $tier === 'free' ? 'free_native' : 'paid_active',
            'subscription_status' => $tier === 'free' ? '' : 'active', 'flags' => ['stock.costing' => $landedCosts, 'stock.landed_costs' => $landedCosts]];
    }

    private function message(\Closure $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }
        $this->fail('A ValidationException was expected.');
    }

    #[Test]
    public function availability_follows_the_plan_without_any_switch_on_step(): void
    {
        $this->useTenantA();
        $org = TenantTestManager::ORG_A;
        foreach (['professional', 'premium', 'enterprise'] as $tier) {
            $status = $this->plan($this->tier($tier, true))->status($org);
            $this->assertTrue($status['available'], $tier);
            $this->assertSame('available', $status['reason'], $tier);
            $this->assertFalse($status['opted_out'], $tier);
        }
        foreach (['free', 'standard'] as $tier) {
            $status = $this->plan($this->tier($tier, false))->status($org);
            $this->assertFalse($status['available'], $tier);
            $this->assertFalse($status['entitled'], $tier);
            $this->assertSame('not_in_plan', $status['reason'], $tier);
            $this->assertSame(__('inventory.landed_cost.not_in_plan'),
                $this->message(fn () => app(LandedCostAvailability::class)->assertWritable($org)), $tier);
        }
        // No snapshot at all fails closed, like every other paid stock.* feature.
        $this->assertFalse($this->plan(null)->status($org)['available']);
        // Deciding availability writes nothing.
        $this->assertSame(0, InventorySetting::withoutGlobalScopes()->where('organization_id', $org)->whereNotNull('landed_costs_opted_out_at')->count());
        $this->assertSame(0, InventoryAuditLog::query()->where('action', 'like', 'inventory.landed_costs.%')->count());
    }

    #[Test]
    public function a_paid_snapshot_that_does_not_name_landed_costs_is_refused_until_central_publishes_the_flag(): void
    {
        $this->useTenantA();
        $org = TenantTestManager::ORG_A;
        foreach (['standard', 'professional'] as $tier) {
            $snapshot = $this->tier($tier, true);
            unset($snapshot['flags']['stock.landed_costs']);
            $status = $this->plan($snapshot)->status($org);
            $this->assertFalse($status['available'], $tier);
            $this->assertSame('not_in_plan', $status['reason'], $tier);
        }
    }

    #[Test]
    public function every_landed_cost_route_is_plan_gated_so_there_is_no_entitlement_bypass(): void
    {
        $map = config('inventory_entitlements.route_features');
        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter(fn ($n) => is_string($n) && str_starts_with($n, 'api.v1.landed-costs.'))->values();
        $this->assertGreaterThanOrEqual(10, $names->count());
        foreach ($names as $name) {
            $this->assertSame(LandedCostAvailability::FEATURE, $map[$name] ?? null, $name);
            // The bare `feature` middleware on the v1 group applies the map.
            $this->assertContains('feature', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
        // The opt-out is a settings decision; the clearing account stays an accountant decision.
        $this->assertContains('perm:inventory.manage_settings', Route::getRoutes()->getByName('api.v1.landed-costs.preference')->gatherMiddleware());
        $setup = Route::getRoutes()->getByName('api.v1.landed-costs.connection.enable')->gatherMiddleware();
        $this->assertContains('perm:inventory.integration.accounting_review', $setup);
        $this->assertContains('perm:inventory.integration.connection_manage', $setup);
    }

    #[Test]
    public function an_explicit_opt_out_is_stored_preserved_and_respected_and_changes_no_stock(): void
    {
        $this->useTenantA();
        $org = TenantTestManager::ORG_A;
        $warehouse = F::warehouse(['code' => 'LC-OPT-WH']);
        $item = F::fifoItem(['sku' => 'LC-OPT']);
        $receipt = app(GoodsReceiptService::class)->createDraft(['grn_number' => 'LC-OPT-GRN', 'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString()],
            [['item_id' => $item->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '4']]);
        $receipt = app(GoodsReceiptService::class)->post($receipt)->fresh('lines');
        $doc = app(LandedCostService::class)->createDraft(['allocation_method' => 'quantity', 'currency_code' => 'JOD', 'exchange_rate' => '1'],
            [['charge_type' => 'freight', 'amount' => '10']], [$receipt->lines->sole()->id]);
        $valueBefore = (string) StockBalance::query()->where('item_id', $item->id)->value('total_value');

        $availability = $this->plan($this->tier('professional', true));
        $status = $availability->setOptOut($org, true, 77);
        $this->assertFalse($status['available']);
        $this->assertTrue($status['entitled']);
        $this->assertSame('opted_out', $status['reason']);
        $first = $status['opted_out_at'];
        $this->assertNotNull($first);
        // Idempotent: an existing opt-out keeps its original time and is audited once.
        $this->travel(5)->minutes();
        $this->assertSame($first, $availability->setOptOut($org, true, 77)['opted_out_at']);
        $this->assertSame(1, InventoryAuditLog::query()->where('action', 'inventory.landed_costs.opted_out')->count());

        // Respected: document writes are refused with the localized reason.
        $this->assertSame(__('inventory.landed_cost.opted_out'), $this->message(fn () => $availability->assertWritable($org)));

        // Preserved: a plan change (re-push), a later higher tier and a status read never reset it.
        foreach (['premium', 'enterprise'] as $tier) {
            $this->assertTrue($this->plan($this->tier($tier, true))->status($org)['opted_out'], $tier);
        }
        $this->assertTrue(app(LandedCostWorkflow::class)->status($org)['posting_ready']);
        $this->assertSame($first, app(LandedCostAvailability::class)->status($org)['opted_out_at']);

        // Opting out and back in moved no value and recorded no event; the draft is untouched.
        $this->assertSame($valueBefore, (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
        $this->assertSame('draft', $doc->fresh()->status);
        $status = app(LandedCostAvailability::class)->setOptOut($org, false, 77);
        $this->assertTrue($status['available']);
        $this->assertNull($status['opted_out_at']);
        $this->assertSame(1, InventoryAuditLog::query()->where('action', 'inventory.landed_costs.opt_out_withdrawn')->count());
        $this->assertSame(0, IntegrationOutboxEvent::query()->count());
        $this->assertSame('draft', LandedCost::query()->findOrFail($doc->id)->status);
    }

    #[Test]
    public function a_standalone_organization_posts_by_plan_with_no_account_mapping(): void
    {
        $this->useTenantA();
        $org = TenantTestManager::ORG_A;
        $this->plan($this->tier('professional', true));
        $this->assertTrue(app(LandedCostAvailability::class)->status($org)['available']);
        $status = app(LandedCostWorkflow::class)->status($org);
        $this->assertSame('standalone', $status['mode']);
        $this->assertTrue($status['posting_ready']);
        $this->assertFalse($status['setup_required']);
        $this->assertNull($status['setup_action']);

        $warehouse = F::warehouse(['code' => 'LC-SA-WH']);
        $item = F::averageItem(['sku' => 'LC-SA']);
        $receipt = app(GoodsReceiptService::class)->createDraft(['grn_number' => 'LC-SA-GRN', 'warehouse_id' => $warehouse->id, 'receipt_date' => now()->toDateString()],
            [['item_id' => $item->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        $receipt = app(GoodsReceiptService::class)->post($receipt)->fresh('lines');
        app(LandedCostAvailability::class)->assertWritable($org);
        $doc = app(LandedCostService::class)->createDraft(['allocation_method' => 'quantity', 'currency_code' => 'JOD', 'exchange_rate' => '1'],
            [['charge_type' => 'freight', 'amount' => '20']], [$receipt->lines->sole()->id]);
        $posted = app(LandedCostService::class)->post($doc);

        $this->assertSame('posted', $posted->status);
        $this->assertSame('70.00', (string) StockBalance::query()->where('item_id', $item->id)->value('total_value'));
        $this->assertSame(0, \App\Models\Tenant\IntegrationAccountMapping::query()->count());
        $this->assertSame(0, IntegrationOutboxEvent::query()->where('event_type', 'like', 'landed_cost.%')->count());
    }

    #[Test]
    public function the_opt_out_column_is_additive_idempotent_and_null_for_existing_rows(): void
    {
        $this->useTenantA();
        // MariaDB DDL commits implicitly; end the fixture transaction before running the migration.
        while (DB::connection('tenant')->transactionLevel() > 0) {
            DB::connection('tenant')->rollBack();
        }
        $migration = require database_path('migrations/tenant/2026_10_09_101000_add_landed_costs_opt_out_to_inventory_settings.php');
        $migration->up();
        $migration->up();
        $column = collect(DB::connection('tenant')->select("select is_nullable, data_type from information_schema.columns where table_schema = database() and table_name = 'inventory_settings' and column_name = 'landed_costs_opted_out_at'"))->sole();
        $this->assertSame(['YES', 'timestamp'], [strtoupper((string) $column->is_nullable), strtolower((string) $column->data_type)]);
        // NULL = follow the plan: no existing organization is opted out by the deploy.
        $this->assertSame(0, InventorySetting::withoutGlobalScopes()->whereNotNull('landed_costs_opted_out_at')->count());
    }
}
