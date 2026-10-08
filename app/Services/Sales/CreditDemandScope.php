<?php
namespace App\Services\Sales;
use App\Models\Tenant\FulfillmentRequest;
use App\Tenancy\OrganizationContext;
/** Internal typed scope created only after independently authorized, locked credit intent admission. No browser flag. */
final readonly class CreditDemandScope {
 private function __construct(public int $organizationId,public int $requestId,public int $orderId,public string $operationUuid){}
 public static function fromLockedIntent(FulfillmentRequest $request,object $intent,array $proof):self {
  abort_unless((int)$request->organization_id===app(OrganizationContext::class)->idOrFail()&&(string)$intent->request_uuid===$request->request_uuid&&(string)$intent->request_revision===$request->source_revision&&(int)$intent->invoice_id===(int)$request->source_invoice_id&&(string)($proof['command']??'')==='reduce-demand'&&(string)($proof['operation_uuid']??'')===(string)$intent->operation_uuid&&(int)($proof['credit_note_id']??0)===(int)$intent->credit_note_id&&(string)($proof['credit_revision']??'')===(string)$intent->credit_revision,403);
  return new self((int)$request->organization_id,(int)$request->id,(int)($request->sales_order_id??0),(string)$intent->operation_uuid);
 }
 public function assertOrder(int $organizationId,int $orderId):void {abort_unless($this->organizationId===$organizationId&&$this->orderId===$orderId&&FulfillmentRequest::query()->where('organization_id',$organizationId)->whereKey($this->requestId)->where('sales_order_id',$orderId)->exists(),403);}
}
