<?php

namespace Tests\Feature\Entitlements;

use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Entitlements\InventoryLimitService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §6 runtime verification — all FIVE plan tiers for SolaStock, end to end.
 *
 * Feeds each tier's real snapshot (features + limits) through the REAL decision
 * engine and asserts the enabled/disabled feature boundary AND the numeric limit
 * per tier, plus adjacent + Enterprise→Free transitions. Uses a fixed-snapshot
 * service (no tenant DB needed), so it runs anywhere.
 *
 * Ground truth = the live catalog (2026-07-15 reconciliation). Features are gated
 * via permission→feature (checkPermission) or feature key (checkFeature). Here we
 * assert on the feature keys directly through checkFeature + the limit service.
 */
class FiveTierRuntimeTest extends TestCase
{
    /** Cumulative enabled boolean feature sets per tier (from the live catalog). */
    private const TIERS = [
        'free'         => ['stock.items', 'stock.barcodes', 'stock.movements', 'stock.reorder_alerts', 'stock.counts'],
        'standard'     => ['stock.variants', 'stock.import_export', 'stock.locations_bins', 'stock.transfers', 'stock.purchase_orders', 'stock.suppliers', 'stock.goods_receipt', 'stock.reports'],
        'professional' => ['stock.batch_expiry', 'stock.costing', 'stock.po_approvals', 'stock.sales_fulfillment'],
        'premium'      => [], // same booleans as pro (differs by limits + api/finance)
        'enterprise'   => ['stock.api', 'stock.finance_integration'],
    ];

    /** Disabled in every plan (no suggested-PO endpoint) — denied on ALL tiers. */
    private const DISABLED_EVERYWHERE = 'stock.reorder_suggestions';

    /** Per-tier numeric limits (max_items, max_warehouses). null = unlimited. */
    private const LIMITS = [
        'free'         => ['stock.max_items' => 200,   'stock.max_warehouses' => 1],
        'standard'     => ['stock.max_items' => 2000,  'stock.max_warehouses' => 3],
        'professional' => ['stock.max_items' => 10000, 'stock.max_warehouses' => 10],
        'premium'      => ['stock.max_items' => 50000, 'stock.max_warehouses' => 25],
        'enterprise'   => ['stock.max_items' => null,  'stock.max_warehouses' => null],
    ];

    private function allKeys(): array
    {
        $all = [self::DISABLED_EVERYWHERE];
        foreach (self::TIERS as $keys) {
            $all = array_merge($all, $keys);
        }

        return array_values(array_unique($all));
    }

    private function enabledFor(string $tier): array
    {
        $order = ['free', 'standard', 'professional', 'premium', 'enterprise'];
        $set = [];
        foreach ($order as $t) {
            $set = array_merge($set, self::TIERS[$t]);
            if ($t === $tier) {
                break;
            }
        }

        return array_values(array_unique($set));
    }

    /** A service + limit-service pair reading the given synthetic snapshot. */
    private function engines(array $snapshot): array
    {
        $cache = new class($snapshot) extends EntitlementsCache {
            public function __construct(private array $snap) {}
            public function currentClientId(): ?int { return 990010; }
            public function getProjectSnapshot(int $c, ?string $s = null): ?array { return $this->snap; }
        };

        return [new InventoryCommercialEntitlementService($cache), new InventoryLimitService($cache)];
    }

    private function tierSnapshot(string $tier): array
    {
        $enabled = $this->enabledFor($tier);
        $flags = [];
        foreach ($this->allKeys() as $k) {
            $flags[$k] = in_array($k, $enabled, true) && $k !== self::DISABLED_EVERYWHERE;
        }
        $flags[self::DISABLED_EVERYWHERE] = false;
        foreach (self::LIMITS[$tier] as $k => $v) {
            $flags[$k] = $v === null ? 'unlimited' : $v;
        }

        return [
            'effective_tier' => $tier, 'access_mode' => 'full', 'reason_code' => 'paid_active',
            'flags' => $flags,
        ];
    }

    #[Test]
    public function every_tier_enables_exactly_its_boolean_feature_set(): void
    {
        foreach (['free', 'standard', 'professional', 'premium', 'enterprise'] as $tier) {
            $enabled = $this->enabledFor($tier);
            [$svc] = $this->engines($this->tierSnapshot($tier));

            foreach ($this->allKeys() as $key) {
                $shouldBeOn = in_array($key, $enabled, true) && $key !== self::DISABLED_EVERYWHERE;
                $this->assertSame(
                    $shouldBeOn,
                    $svc->checkFeature($key)['allowed'],
                    "[{$tier}] {$key} expected ".($shouldBeOn ? 'ENABLED' : 'DENIED')
                );
            }
        }
    }

    #[Test]
    public function every_tier_reports_its_numeric_limits(): void
    {
        foreach (self::LIMITS as $tier => $limits) {
            [, $lim] = $this->engines($this->tierSnapshot($tier));
            foreach ($limits as $key => $expected) {
                $this->assertSame($expected, $lim->limit($key), "[{$tier}] {$key} limit");
            }
        }
    }

    #[Test]
    public function free_warehouse_cap_grandfathers_over_limit_but_blocks_growth(): void
    {
        // The clients-2-and-18 case: Free cap = 1 warehouse, org already has 5.
        [, $lim] = $this->engines($this->tierSnapshot('free'));
        $this->assertFalse($lim->canCreate('stock.max_warehouses', 5), 'over-limit org is blocked from a 6th');
        $this->assertTrue($lim->usage('stock.max_warehouses', 5)['over_limit']);
        // Enterprise = unlimited: never blocked.
        [, $limE] = $this->engines($this->tierSnapshot('enterprise'));
        $this->assertTrue($limE->canCreate('stock.max_warehouses', 100_000));
    }

    #[Test]
    public function upgrade_downgrade_and_enterprise_to_free_move_the_boundary(): void
    {
        // Free → Professional upgrade: sales_fulfillment turns on + item ceiling rises.
        [$free] = $this->engines($this->tierSnapshot('free'));
        $this->assertFalse($free->checkFeature('stock.sales_fulfillment')['allowed']);
        [$pro, $proLim] = $this->engines($this->tierSnapshot('professional'));
        $this->assertTrue($pro->checkFeature('stock.sales_fulfillment')['allowed']);
        $this->assertSame(10000, $proLim->limit('stock.max_items'));

        // Enterprise → Free downgrade: api/finance drop, base stays, ceiling drops.
        [$ent, $entLim] = $this->engines($this->tierSnapshot('enterprise'));
        $this->assertTrue($ent->checkFeature('stock.finance_integration')['allowed']);
        $this->assertNull($entLim->limit('stock.max_items'));
        [$backToFree, $freeLim] = $this->engines($this->tierSnapshot('free'));
        $this->assertFalse($backToFree->checkFeature('stock.finance_integration')['allowed'], 'finance drops on downgrade');
        $this->assertTrue($backToFree->checkFeature('stock.items')['allowed'], 'base survives downgrade');
        $this->assertSame(200, $freeLim->limit('stock.max_items'), 'ceiling drops to Free');
    }
}
