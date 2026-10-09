<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{SalesReturn,Shipment,FinancialOriginRequest};
/** Internal native admission: no browser flag converts a shipment reversal into a Cash refund. */
final readonly class CashPartialReturnAdmission {
 private function __construct(private OriginCashPhysicalReversalContext $context,private int $org,private int $shipmentId){}
 public static function fromNativeSource(Shipment $shipment,OriginCashPhysicalReversalContext $context):self {
  $context->assertShipment($shipment);
  abort_unless($shipment->status==='posted'&&!$shipment->reversed_at,409);
  return new self($context,(int)$shipment->organization_id,(int)$shipment->id);
 }
 public function assertSource(Shipment $shipment):FinancialOriginRequest {
  abort_unless((int)$shipment->organization_id===$this->org&&(int)$shipment->id===$this->shipmentId,403);
  $r=$this->context->lockAndValidate();abort_unless($r->source_document_type==='sales_receipt'&&(int)$r->organization_id===$this->org&&(int)$r->sales_order_id===(int)$shipment->sales_order_id,409);return$r;
 }
 public function lockAndValidate(SalesReturn $return):FinancialOriginRequest {
  abort_unless((int)$return->organization_id===$this->org&&(int)$return->shipment_id===$this->shipmentId&&!$return->is_source_reversal,403);
  return$this->assertSource(Shipment::query()->where('organization_id',$this->org)->whereKey($this->shipmentId)->lockForUpdate()->firstOrFail());
 }
}
