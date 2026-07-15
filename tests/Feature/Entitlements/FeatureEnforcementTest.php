<?php

namespace Tests\Feature\Entitlements;

use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Entitlements\InventoryLimitService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rebuilt stock.* enforcement layer.
 *
 * Pins down three things the old (stale inventory.* map) could not:
 *   1. The permission_features + route_features maps point ONLY at real stock.*
 *      catalog keys — the keys central actually pushes.
 *   2. checkFeature() honours the paid-through decision for the route-level gate.
 *   3. The grandfathered limit gate blocks the (N+1)th record but never the
 *      existing N — the safety property for the live over-limit tenants.
 */
class FeatureEnforcementTest extends TestCase
{
    /** A service whose snapshot is fixed, no tenant DB required. */
    private function service(?array $snapshot): InventoryCommercialEntitlementService
    {
        $cache = new class($snapshot) extends EntitlementsCache {
            public function __construct(private ?array $snapshot) {}
            public function currentClientId(): ?int { return 990010; }
            public function getProjectSnapshot(int $clientId, ?string $projectSlug = null): ?array { return $this->snapshot; }
        };

        return new InventoryCommercialEntitlementService($cache);
    }

    private function limits(?array $snapshot): InventoryLimitService
    {
        $cache = new class($snapshot) extends EntitlementsCache {
            public function __construct(private ?array $snapshot) {}
            public function currentClientId(): ?int { return 990010; }
            public function getProjectSnapshot(int $clientId, ?string $projectSlug = null): ?array { return $this->snapshot; }
        };

        return new InventoryLimitService($cache);
    }

    /* =================================================================
     * 1. Map integrity — only real stock.* keys
     * ================================================================= */

    #[Test]
    public function every_mapped_feature_is_a_stock_catalog_key(): void
    {
        $perm = array_values(config('inventory_entitlements.permission_features'));
        $route = array_values(config('inventory_entitlements.route_features'));

        foreach (array_merge($perm, $route) as $feature) {
            $this->assertStringStartsWith('stock.', $feature, "Mapped feature {$feature} is not a stock.* key");
        }
    }

    #[Test]
    public function limit_features_reference_real_org_scoped_models(): void
    {
        foreach (config('inventory_entitlements.limit_features') as $feature => $model) {
            $this->assertStringStartsWith('stock.max_', $feature);
            $this->assertTrue(class_exists($model), "Limit model {$model} does not exist");
        }
    }

    /* =================================================================
     * 2. checkFeature — the route-level gate decision
     * ================================================================= */

    #[Test]
    public function check_feature_allows_an_included_stock_feature(): void
    {
        $decision = $this->service([
            'effective_tier' => 'professional',
            'access_mode' => 'full',
            'reason_code' => 'paid_active',
            'allowed_features' => ['stock.transfers'],
        ])->checkFeature('stock.transfers');

        $this->assertTrue($decision['allowed']);
    }

    #[Test]
    public function check_feature_blocks_a_feature_not_in_plan_with_402(): void
    {
        $decision = $this->service([
            'effective_tier' => 'free',
            'access_mode' => 'free',
            'reason_code' => 'paid_expired_free_fallback',
            'blocked_features' => ['stock.transfers'],
        ])->checkFeature('stock.transfers');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('feature_not_in_plan', $decision['reason_code']);
        $this->assertSame(402, $decision['status']);
    }

    #[Test]
    public function check_feature_fails_closed_with_no_snapshot(): void
    {
        // A feature-gated route is never free — no snapshot must deny, not grant.
        $decision = $this->service(null)->checkFeature('stock.transfers');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('entitlement_service_unavailable', $decision['reason_code']);
    }

    /* =================================================================
     * 3. Grandfathered limit gate
     * ================================================================= */

    #[Test]
    public function limit_reads_the_numeric_ceiling_in_several_shapes(): void
    {
        // Free plan pushes stock.max_warehouses = 1, in whatever shape.
        $this->assertSame(1, $this->limits(['flags' => ['stock.max_warehouses' => 1]])->limit('stock.max_warehouses'));
        $this->assertSame(1, $this->limits(['flags' => ['stock.max_warehouses' => '1']])->limit('stock.max_warehouses'));
        $this->assertSame(1, $this->limits(['features' => ['stock.max_warehouses' => ['value' => 1]]])->limit('stock.max_warehouses'));
    }

    #[Test]
    public function unlimited_and_missing_limits_are_null(): void
    {
        $this->assertNull($this->limits(['flags' => ['stock.max_items' => 'unlimited']])->limit('stock.max_items'));
        $this->assertNull($this->limits(['flags' => []])->limit('stock.max_items'));
        $this->assertNull($this->limits(null)->limit('stock.max_items'));
    }

    #[Test]
    public function can_create_blocks_the_next_record_but_grandfathers_existing(): void
    {
        $limits = $this->limits(['flags' => ['stock.max_warehouses' => 1]]);

        // Zero existing -> the first is always allowed.
        $this->assertTrue($limits->canCreate('stock.max_warehouses', 0));

        // At the limit -> the second is blocked.
        $this->assertFalse($limits->canCreate('stock.max_warehouses', 1));

        // ALREADY OVER (the live clients 2 & 18 case): still blocked for NEW, but
        // this never implies deleting the existing 5 — usage just reports over_limit.
        $this->assertFalse($limits->canCreate('stock.max_warehouses', 5));
        $usage = $limits->usage('stock.max_warehouses', 5);
        $this->assertTrue($usage['over_limit']);
        $this->assertSame(0, $usage['remaining']);
        $this->assertSame(5, $usage['used']);
        $this->assertSame(1, $usage['limit']);
    }

    #[Test]
    public function unlimited_plan_never_blocks(): void
    {
        $limits = $this->limits(['flags' => ['stock.max_items' => 'unlimited']]);

        $this->assertTrue($limits->canCreate('stock.max_items', 100_000));
        $this->assertNull($limits->remaining('stock.max_items', 100_000));
        $this->assertTrue($limits->usage('stock.max_items', 100_000)['unlimited']);
    }
}
