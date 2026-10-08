<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\FinancialOriginRequest;
use App\Tenancy\OrganizationContext;
final readonly class CashDemandScope {
 private function __construct(public int $organizationId,public int $requestId,public int $orderId,public string $operationUuid){}
 public static function admitted(FinancialOriginRequest $r,object $intent,array $proof):self {
  abort_unless($r->source_document_type==='sales_receipt'&&(int)$r->organization_id===app(OrganizationContext::class)->idOrFail()
   &&$intent->request_uuid===$r->request_uuid&&$intent->source_revision===$r->source_revision&&(int)$intent->source_document_id===(int)$r->source_document_id
   &&($proof['cash_demand']['operation_uuid']??null)===$intent->operation_uuid&&(int)($proof['cash_demand']['refund_receipt_id']??0)===(int)$intent->refund_receipt_id,403);
  return new self((int)$r->organization_id,(int)$r->id,(int)$r->sales_order_id,$intent->operation_uuid);
 }
 public function assertOrder(int $org,int $order):void {abort_unless($org===$this->organizationId&&$order===$this->orderId&&FinancialOriginRequest::query()->where('organization_id',$org)->whereKey($this->requestId)->where('sales_order_id',$order)->where('source_document_type','sales_receipt')->exists(),403);}
}
