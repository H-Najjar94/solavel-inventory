<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\FinancialOriginRequest;
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Purchasing\ReceivingRequestService;

/** Server-owned admission. There is no payload flag that can waive Finance or Stock application authority. */
final readonly class OriginSourceAdmission
{
    private function __construct(private int $actor,private ?array $financeProof){}
    public static function finance(FinancialOriginRequest $request,int $actor):self
    {
        abort_unless($actor>0,403);$dto=OriginRequestPayload::fromArray($request->source_payload);
        $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,$dto->origin,'view',['request_uuid'=>$request->request_uuid]);
        return new self($actor,$proof);
    }
    public static function stock(FinancialOriginRequest $request,int $actor,string $permission):self
    {
        abort_unless(in_array($permission,['inventory.view_sales','inventory.view_stock','inventory.manage_sales_orders','inventory.manage_adjustments','inventory.manage_shipments','inventory.manage_returns','inventory.receive_goods'],true),403);
        $user=request()->user();abort_unless($user && (int)$user->getAuthIdentifier()===$actor && $actor>0
            && (app(CentralAppAccess::class)->decision($actor,(int)$request->organization_id,'inventory')['allowed']??false)
            && app(InventoryPermissionService::class)->can($user,$permission),403);
        return new self($actor,null);
    }
    public function lock(FinancialOriginRequest $request):LockedOriginProof
    {
        if($this->financeProof!==null)return LockedOriginProof::verify(OriginRequestPayload::fromArray($request->source_payload),app(ReceivingRequestService::class)->mapping(),$this->financeProof,$this->actor);
        return LockedOriginProof::lockAccepted($request,$this->actor);
    }
}
