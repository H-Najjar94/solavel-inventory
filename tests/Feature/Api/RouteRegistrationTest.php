<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks that the SolaStock API surface is registered and that tenant
 * selection routes are NOT tenant-gated (they're how a tenant gets selected).
 */
class RouteRegistrationTest extends TestCase
{
    #[Test]
    public function core_api_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())->filter()->values()->all();

        foreach ([
            'api.v1.meta', 'api.v1.dashboard', 'api.v1.items.index', 'api.v1.items.store',
            'api.v1.warehouses.index', 'api.v1.opening.post', 'api.v1.adjustments.post',
            'api.v1.grn.post', 'api.v1.transfers.post', 'api.v1.counts.post',
            'api.v1.ledger.index', 'api.v1.balances.index', 'api.v1.tenant.status',
            'api.v1.tenant.select-demo', 'api.v1.integration.status',
            // Sales fulfillment
            'api.v1.sales-orders.index', 'api.v1.sales-orders.store', 'api.v1.sales-orders.confirm',
            'api.v1.sales-orders.reserve', 'api.v1.sales-orders.release', 'api.v1.sales-orders.cancel',
            'api.v1.pick-lists.index', 'api.v1.pick-lists.store', 'api.v1.pick-lists.picked',
            'api.v1.packs.index', 'api.v1.packs.store', 'api.v1.packs.packed',
            'api.v1.shipments.index', 'api.v1.shipments.store', 'api.v1.shipments.post', 'api.v1.shipments.from-so',
            'api.v1.sales-returns.index', 'api.v1.sales-returns.store', 'api.v1.sales-returns.post',
        ] as $name) {
            $this->assertContains($name, $names, "Missing route: {$name}");
        }
    }

    #[Test]
    public function sales_fulfillment_write_routes_require_manage_permissions(): void
    {
        $expected = [
            'api.v1.sales-orders.store' => 'perm:inventory.manage_sales_orders',
            'api.v1.sales-orders.reserve' => 'perm:inventory.manage_reservations',
            'api.v1.pick-lists.store' => 'perm:inventory.manage_picking',
            'api.v1.packs.store' => 'perm:inventory.manage_packing',
            'api.v1.shipments.post' => 'perm:inventory.manage_shipments',
            'api.v1.sales-returns.post' => 'perm:inventory.manage_returns',
        ];
        foreach ($expected as $name => $perm) {
            $mw = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains($perm, $mw, "Route {$name} must be gated by {$perm}");
        }
    }

    #[Test]
    public function tenant_selection_routes_are_not_tenant_gated(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.tenant.status');
        $this->assertNotNull($route);
        $this->assertNotContains('inv.tenant', $route->gatherMiddleware());
    }

    #[Test]
    public function stock_write_routes_require_manage_permissions(): void
    {
        foreach (['api.v1.opening.post', 'api.v1.adjustments.post', 'api.v1.grn.post'] as $name) {
            $mw = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertTrue(
                collect($mw)->contains(fn ($m) => str_starts_with($m, 'perm:')),
                "Route {$name} must be permission-gated"
            );
        }
    }
}
