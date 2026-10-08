<?php
namespace Tests\Unit\Integration;
use App\Services\Sales\ReturnHandoffService;
use PHPUnit\Framework\TestCase;
final class ReturnHandoffSourceTest extends TestCase
{
    private function source(): array
    {
        return ['source_app'=>'solastock','schema_version'=>'sales.v1','event_type'=>'sales.shipment.confirmed',
            'identity'=>['inventory_organization_id'=>16001,'organization_mapping_uuid'=>'1cfa906b-97c6-4f47-af52-1fbcba9a5fba'],
            'shipment'=>['id'=>12,'mapping_uuid'=>'04e58fc8-5e8b-4bae-8e27-c59e43f84481']];
    }
    public function test_only_actual_ordinary_shipment_contract_admits_commercial_credit():void
    {
        $source=$this->source();
        $this->assertTrue(ReturnHandoffService::ordinaryShipmentSource($source,16001,12));
        $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource($source,16002,12));
        $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource($source,16001,13));
        $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource([],16001,12));
        $source['shipment']['mapping_uuid']=null;
        $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource($source,16001,12));
    }
    public function test_cash_and_other_typed_origins_do_not_admit_invoice_credit_handoff():void
    {
        foreach (['financial-origin.v1','cash-sales.v1'] as $schema) {
            $source=$this->source();$source['schema_version']=$schema;
            $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource($source,16001,12));
        }
        $source=$this->source();$source['event_type']='financial-origin.physical.confirmed';
        $this->assertFalse(ReturnHandoffService::ordinaryShipmentSource($source,16001,12));
    }
}
