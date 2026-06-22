<?php

namespace Tests\Feature\Sales;

use App\Services\Integration\IntegrationEvents;
use App\Services\Reports\InventoryReportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No-DB checks for the Sales Fulfillment foundation: the new outbox event types
 * and their COGS account hints, the new report-registry entries, and the
 * sales-fulfillment permission registry. None touch the database.
 */
class SalesFulfillmentFoundationTest extends TestCase
{
    #[Test]
    public function sales_event_types_are_registered(): void
    {
        foreach ([
            'sales_order.confirmed', 'stock_reserved', 'stock_reservation_released',
            'pick_list.picked', 'pack.packed', 'shipment.posted', 'sales_return.posted',
        ] as $type) {
            $this->assertTrue(IntegrationEvents::exists($type), "missing event {$type}");
            $this->assertNotNull(IntegrationEvents::aggregateType($type));
        }
    }

    #[Test]
    public function shipment_and_return_carry_cogs_hints(): void
    {
        $ship = IntegrationEvents::suggestedAccounts('shipment.posted');
        $this->assertSame('cogs', $ship['suggested_debit_account_mapping']);
        $this->assertSame('inventory_asset', $ship['suggested_credit_account_mapping']);

        // A return is the reverse: debit inventory asset, credit COGS.
        $ret = IntegrationEvents::suggestedAccounts('sales_return.posted');
        $this->assertSame('inventory_asset', $ret['suggested_debit_account_mapping']);
        $this->assertSame('cogs', $ret['suggested_credit_account_mapping']);
    }

    #[Test]
    public function non_stock_sales_events_have_no_account_hints(): void
    {
        foreach (['sales_order.confirmed', 'stock_reserved', 'pick_list.picked', 'pack.packed'] as $type) {
            $hints = IntegrationEvents::suggestedAccounts($type);
            $this->assertNull($hints['suggested_debit_account_mapping'], "{$type} should not debit");
            $this->assertNull($hints['suggested_credit_account_mapping'], "{$type} should not credit");
        }
    }

    #[Test]
    public function fulfillment_reports_are_in_the_registry(): void
    {
        foreach (['fulfillment-status', 'pick-list', 'shipment', 'reservation'] as $key) {
            $this->assertTrue(InventoryReportService::exists($key), "missing report {$key}");
        }
    }

    #[Test]
    public function sales_permissions_are_registered_with_role_bundles(): void
    {
        $perms = config('inventory_permissions.permissions');
        foreach ([
            'inventory.view_sales', 'inventory.manage_sales_orders', 'inventory.manage_reservations',
            'inventory.manage_picking', 'inventory.manage_packing', 'inventory.manage_shipments',
            'inventory.manage_returns',
        ] as $p) {
            $this->assertArrayHasKey($p, $perms, "missing permission {$p}");
        }

        $manager = config('inventory_permissions.roles.inventory_manager');
        $this->assertContains('inventory.manage_shipments', $manager);
        $this->assertContains('inventory.manage_returns', $manager);

        // Viewer can see sales but cannot manage them.
        $viewer = config('inventory_permissions.roles.inventory_viewer');
        $this->assertContains('inventory.view_sales', $viewer);
        $this->assertNotContains('inventory.manage_shipments', $viewer);
    }
}
