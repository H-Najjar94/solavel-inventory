<?php
namespace Tests\Unit\Integration;
use App\Services\Integration\OperationalPartyReadiness;
use PHPUnit\Framework\TestCase;
final class OperationalPartyReadinessAdmissionTest extends TestCase
{
    public function test_transport_never_runs_under_native_economic_locks():void
    {
        $this->assertTrue(OperationalPartyReadiness::mayDeliver(0,'active','verified','active'));
        foreach ([1,2,7] as $depth) $this->assertFalse(OperationalPartyReadiness::mayDeliver($depth,'active','verified','active'));
    }
    public function test_paused_and_unhealthy_connections_do_not_fall_back_to_standalone():void
    {
        foreach (['paused','connected_readonly','connected_pending_mapping','disconnected',null] as $mode)
            $this->assertFalse(OperationalPartyReadiness::mayDeliver(0,$mode,'verified','active'));
        $this->assertFalse(OperationalPartyReadiness::mayDeliver(0,'active','verified_hold','active'));
        $this->assertFalse(OperationalPartyReadiness::mayDeliver(0,'active','verified','maintenance_hold'));
    }
    public function test_each_source_operation_uses_its_existing_stock_permission():void
    {
        $this->assertSame(['supplier','inventory.receive_goods'],OperationalPartyReadiness::operation('App\\Models\\Tenant\\GoodsReceipt'));
        $this->assertSame(['customer','inventory.manage_sales_orders'],OperationalPartyReadiness::operation('App\\Models\\Tenant\\SalesOrder'));
        $this->assertSame(['customer','inventory.manage_shipments'],OperationalPartyReadiness::operation('App\\Models\\Tenant\\Shipment'));
    }
    public function test_unapproved_source_document_types_are_not_general_sync_authority():void
    {
        $this->expectException(\LogicException::class);
        OperationalPartyReadiness::operation('App\\Models\\Tenant\\InventoryReversal');
    }
}
