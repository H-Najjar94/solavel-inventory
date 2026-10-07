<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Purchasing\SupplierReturnRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class SupplierReturnRequestController extends Controller {
 public function __construct(private OrganizationContext$context,private WarehouseAccessService$warehouses,private SupplierReturnRequestService$service){}
 private function actor(Request$r):int{abort_unless(app(InventoryPermissionService::class)->can($r->user(),'inventory.view_stock'),403);return (int)$r->user()->id;}
 public function index(Request$r){$actor=$this->actor($r);$org=$this->context->idOrFail();$allowed=$this->warehouses->allowedIds($actor);$rows=DB::connection('tenant')->table('supplier_return_requests as requests')->join('goods_receipts as receipts',function($join){$join->on('receipts.id','=','requests.goods_receipt_id')->on('receipts.organization_id','=','requests.organization_id');})->where('requests.organization_id',$org)->when($allowed!==null,fn($q)=>$q->whereIn('receipts.warehouse_id',$allowed))->select('requests.id','requests.operation_uuid','requests.source_bill_id','requests.goods_receipt_id','requests.state','requests.supplier_return_id','requests.created_at')->latest('requests.id')->get();return response()->json(['data'=>$rows]);}
 public function post(Request$r,string$uuid){$actor=$this->actor($r);abort_unless(app(InventoryPermissionService::class)->can($r->user(),'inventory.manage_purchase_returns'),403);$r->validate(['arrival_confirmed'=>'accepted']);$org=$this->context->idOrFail();$row=DB::connection('tenant')->table('supplier_return_requests')->where('organization_id',$org)->where('operation_uuid',$uuid)->first();abort_unless($row,404);
  $result=$this->service->dispatch(['action'=>'purchasing.return_request.post','authority_kind'=>'supplier_return_request','actor_id'=>$actor,'data'=>['operation_uuid'=>$uuid,'organization_mapping_uuid'=>$row->organization_mapping_uuid,'source_bill_id'=>$row->source_bill_id,'finance_receipt_id'=>$row->finance_receipt_id,'arrival_confirmed'=>true]],(object)['id'=>$org]);return response()->json(['data'=>$result]);
 }
}
