<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\FulfillmentRequest;
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Sales\FulfillmentRequestService;
use Illuminate\Http\Request;
final class FulfillmentRequestController extends ApiController
{
 public function __construct(private FulfillmentRequestService$requests){}
 public function index(Request$request){$status=$request->validate(['status'=>'sometimes|in:active,cancelled,history'])['status']??'active';$q=FulfillmentRequest::query()->whereIn('status',match($status){'cancelled'=>['cancelled'],'history'=>['complete','cancelled'],default=>['pending','partial']});$canApprove=app(InventoryPermissionService::class)->can($request->user(),'inventory.manage_sales_orders');$access=app(WarehouseAccessService::class);if(!$canApprove&&$access->allowedIds()!==null)$q->whereIn('warehouse_id',$access->allowedIds());return$this->success($q->with('lines')->orderByDesc('id')->limit(100)->get()->map(fn($r)=>$this->requests->status($r)));}
 public function show(FulfillmentRequest$fulfillmentRequest){$this->visible($fulfillmentRequest);return$this->success($this->requests->status($fulfillmentRequest));}
 public function approve(Request$request,FulfillmentRequest$fulfillmentRequest){$this->visible($fulfillmentRequest);$d=$request->validate(['warehouse_id'=>'required|integer|min:1']);return$this->success($this->requests->approve($fulfillmentRequest,(int)$d['warehouse_id']));}
 private function visible(FulfillmentRequest$r):void {$access=app(WarehouseAccessService::class);if(!app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders')){abort_unless($r->warehouse_id,404);$access->assertAllowed((int)$r->warehouse_id);}}
}
