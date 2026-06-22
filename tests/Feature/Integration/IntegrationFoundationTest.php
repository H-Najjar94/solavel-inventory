<?php

namespace Tests\Feature\Integration;

use App\Services\Integration\IntegrationEvents;
use App\Services\Integration\IntegrationStatusService;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the SolaBooks integration foundation: event-type registry,
 * idempotency key shape, status-mode enum, suggested-account hints, and route
 * registration with correct permission middleware. None touch the database.
 */
class IntegrationFoundationTest extends TestCase
{
    #[Test]
    public function event_registry_contains_the_supported_events(): void
    {
        foreach ([
            'opening_stock.posted', 'opening_stock.reversed', 'adjustment.posted',
            'adjustment.reversed', 'grn.posted', 'transfer.posted', 'stock_count.posted',
        ] as $type) {
            $this->assertTrue(IntegrationEvents::exists($type), "missing event {$type}");
            $this->assertNotNull(IntegrationEvents::aggregateType($type));
        }
        $this->assertFalse(IntegrationEvents::exists('not.an.event'));
    }

    #[Test]
    public function idempotency_key_is_deterministic_and_scoped(): void
    {
        $a = IntegrationEvents::idempotencyKey('grn.posted', 'App\\Models\\Tenant\\GoodsReceipt', 7);
        $b = IntegrationEvents::idempotencyKey('grn.posted', 'App\\Models\\Tenant\\GoodsReceipt', 7);
        $c = IntegrationEvents::idempotencyKey('grn.posted', 'App\\Models\\Tenant\\GoodsReceipt', 8);

        $this->assertSame($a, $b, 'same inputs must yield the same key');
        $this->assertNotSame($a, $c, 'different document id must differ');
        $this->assertStringContainsString('solabooks:', $a);
        $this->assertStringContainsString('GoodsReceipt:7', $a);
    }

    #[Test]
    public function suggested_account_hints_are_present(): void
    {
        $hints = IntegrationEvents::suggestedAccounts('opening_stock.posted');
        $this->assertSame('inventory_asset', $hints['suggested_debit_account_mapping']);
        $this->assertSame('opening_offset', $hints['suggested_credit_account_mapping']);
    }

    #[Test]
    public function status_modes_are_well_defined(): void
    {
        foreach (['disconnected', 'connected_readonly', 'connected_pending_mapping', 'active', 'paused', 'error'] as $mode) {
            $this->assertContains($mode, IntegrationStatusService::MODES);
        }
        $this->assertCount(10, IntegrationStatusService::REQUIRED_ACCOUNT_MAPPINGS);
    }

    #[Test]
    public function integration_routes_are_registered_with_permissions(): void
    {
        $routes = Route::getRoutes();
        $names = collect($routes->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();
        foreach ([
            'api.v1.integration.status', 'api.v1.integration.accounts.index', 'api.v1.integration.accounts.update',
            'api.v1.integration.items.index', 'api.v1.integration.events.index', 'api.v1.integration.events.retry',
        ] as $n) {
            $this->assertContains($n, $names, "missing route {$n}");
        }

        $manage = $routes->getByName('api.v1.integration.accounts.update')->gatherMiddleware();
        $this->assertContains('perm:inventory.integration.manage', $manage);
        $retry = $routes->getByName('api.v1.integration.events.retry')->gatherMiddleware();
        $this->assertContains('perm:inventory.integration.retry', $retry);
    }
}
