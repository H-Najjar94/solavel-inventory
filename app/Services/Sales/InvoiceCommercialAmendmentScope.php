<?php
namespace App\Services\Sales;

use App\Models\Tenant\FulfillmentRequest;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;

/** Read-only evidence for one positively authorized commercial amendment; no warehouse operation accepts this scope. */
final readonly class InvoiceCommercialAmendmentScope
{
    private function __construct(public int $organizationId,public int $requestId,public int $orderId) {}

    public static function fromLockedApproval(FulfillmentRequest $request,object $intent,array $proof): self
    {
        $workflow=json_decode($intent->result,true,512,JSON_THROW_ON_ERROR);
        abort_unless(DB::connection('tenant')->transactionLevel()>0 && (int)$request->organization_id===app(OrganizationContext::class)->idOrFail()
            && $intent->event_type==='sales.invoice_amendment.v1' && $intent->source_key==='invoice-amendment:'.($proof['operation_uuid']??'')
            && (int)($proof['invoice_id']??0)===(int)$request->source_invoice_id && (int)($workflow['invoice_id']??0)===(int)$request->source_invoice_id
            && ($workflow['operation_uuid']??null)===($proof['operation_uuid']??null)
            && hash_equals($intent->source_hash,(string)($proof['approved_hash']??''))
            && hash_equals($intent->source_hash,hash('sha256',json_encode($workflow['approved']??[],JSON_THROW_ON_ERROR))),403);
        return new self((int)$request->organization_id,(int)$request->id,(int)($request->sales_order_id??0));
    }

    public function assertOrder(int $organizationId,int $orderId): void
    {
        abort_unless($this->organizationId===$organizationId && $this->orderId===$orderId
            && FulfillmentRequest::query()->where('organization_id',$organizationId)->whereKey($this->requestId)->where('sales_order_id',$orderId)->exists(),403);
    }
}
