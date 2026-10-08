<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\FulfillmentRequest;
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Sales\FulfillmentRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class FulfillmentRequestController extends ApiController
{
 public function __construct(private FulfillmentRequestService$requests){}
 public function index(Request $request) {
  $status=$request->validate(['status'=>'sometimes|in:active,cancelled,history'])['status']??'active';
  $q=FulfillmentRequest::query()->whereIn('status',match($status){'cancelled'=>['cancelled'],'history'=>['complete','cancelled'],default=>['pending','partial']});
  $canApprove=app(InventoryPermissionService::class)->can($request->user(),'inventory.manage_sales_orders');
  $access=app(WarehouseAccessService::class);
  if(($allowed=$access->allowedIds())!==null)$q->where(function($query)use($allowed,$canApprove){$query->whereIn('warehouse_id',$allowed);if($canApprove)$query->orWhereNull('warehouse_id');});
  $rows=$q->with('lines')->orderByDesc('id')->limit(100)->get()->map(fn($r)=>$this->requests->status($r));
  if($status!=='active'&&$canApprove){
   $org=app(\App\Tenancy\OrganizationContext::class)->idOrFail();
   $existing=FulfillmentRequest::query()->pluck('request_uuid');
   $cancelled=DB::connection('tenant')->table('sales_fulfillment_cancellations')->where('organization_id',$org)->whereNotIn('request_uuid',$existing)->orderByDesc('id')->limit(100)->get();
   foreach($cancelled as$c)$rows->push(['id'=>null,'number'=>null,'request_uuid'=>$c->request_uuid,'source_invoice_id'=>(int)$c->source_invoice_id,'source_invoice_number'=>data_get(json_decode($c->source_payload,true),'source_invoice_number'),'source_revision'=>$c->source_revision,'status'=>'cancelled','cancelled_before_acceptance'=>true,'warehouse_id'=>null,'sales_order_id'=>null,'approved_at'=>null,'lines'=>[],'shipments'=>[],'shipment_ids'=>[],'fulfilled_quantity'=>'0.0000']);
  }
  return $this->success($rows->values());
 }
 public function show(FulfillmentRequest$fulfillmentRequest){$this->visible($fulfillmentRequest);return$this->success($this->requests->status($fulfillmentRequest));}
 public function approve(Request$request,FulfillmentRequest$fulfillmentRequest){$this->visible($fulfillmentRequest);$d=$request->validate(['warehouse_id'=>'required|integer|min:1']);return$this->success($this->requests->approve($fulfillmentRequest,(int)$d['warehouse_id']));}
 private function visible(FulfillmentRequest$r):void {$access=app(WarehouseAccessService::class);if($r->warehouse_id){$access->assertAllowed((int)$r->warehouse_id);}else{abort_unless(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders'),404);}}
}
