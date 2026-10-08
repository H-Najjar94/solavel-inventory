<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\FinancialOriginRequest;
use App\Services\Access\{WarehouseAccessService,InventoryPermissionService};
use App\Services\FinancialOrigins\{OriginDispatchService,OriginRequestService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
final class CashFulfillmentRequestController extends ApiController {
 public function index(Request $http){
  if(!Schema::connection('tenant')->hasTable('stock_financial_origin_requests'))return$this->success([]);
  $input=$http->validate(['status'=>'sometimes|in:active,history,cancelled','request'=>'sometimes|integer|min:1']);$status=$input['status']??'active';
  $query=FinancialOriginRequest::query()->where('organization_id',app(\App\Tenancy\OrganizationContext::class)->idOrFail())->where('source_document_type','sales_receipt');
  if(isset($input['request']))$query->whereKey((int)$input['request']);else $query->whereIn('status',match($status){'history'=>['complete','cancelled'],'cancelled'=>['cancelled'],default=>['pending','partial']});
  if(($allowed=app(WarehouseAccessService::class)->allowedIds())!==null){$approve=app(InventoryPermissionService::class)->can($http->user(),'inventory.manage_sales_orders');$query->where(function($q)use($allowed,$approve){$q->whereIn('warehouse_id',$allowed);if($approve)$q->orWhereNull('warehouse_id');});}
  return$this->success($query->with('lines')->orderByDesc('id')->limit(100)->get()->map(fn($r)=>$this->display($r))->values());
 }
 public function options(Request $http,string $id){$r=$this->visible($id);return$this->success(app(OriginDispatchService::class)->optionsNative($this->identity($r),(int)$http->user()->getAuthIdentifier()));}
 public function approve(Request $http,string $id){$r=$this->visible($id);$data=$http->validate(['warehouse_id'=>'required|integer|min:1']);return$this->success(app(OriginRequestService::class)->approveNative($this->identity($r)+$data,(int)$http->user()->getAuthIdentifier()));}
 public function dispatch(Request $http,string $id){
  $r=$this->visible($id);$data=$http->validate(['confirm_dispatch'=>'accepted','operation_uuid'=>'required|uuid','warehouse_id'=>'required|integer|min:1','physical_date'=>'required|date_format:Y-m-d','reserve_stock'=>'nullable|boolean','lines'=>'required|array|min:1','lines.*.request_line_id'=>'required|integer|min:1|distinct','lines.*.source_document_line_id'=>'required|integer|min:1|distinct','lines.*.quantity'=>'required|numeric|gt:0','lines.*.unit_id'=>'required|integer|min:1','lines.*.bin_id'=>'nullable|integer|min:1','lines.*.lot_id'=>'nullable|integer|min:1','lines.*.variant_id'=>'nullable|integer|min:1','lines.*.serial_ids'=>'nullable|array','lines.*.serial_ids.*'=>'integer|min:1|distinct']);
  unset($data['confirm_dispatch']);return$this->success(app(OriginDispatchService::class)->executeNative($this->identity($r)+$data,(int)$http->user()->getAuthIdentifier()));
 }
 public function status(Request $http,string $id,string $operation){$r=$this->visible($id);return$this->success(app(OriginDispatchService::class)->statusNative($this->identity($r)+['operation_uuid'=>$operation],(int)$http->user()->getAuthIdentifier()));}
 private function visible(string $id):FinancialOriginRequest {
  abort_unless(Schema::connection('tenant')->hasTable('stock_financial_origin_requests'),404);
  $r=FinancialOriginRequest::query()->where('organization_id',app(\App\Tenancy\OrganizationContext::class)->idOrFail())->where('source_document_type','sales_receipt')->findOrFail($id);
  if($r->warehouse_id)app(WarehouseAccessService::class)->assertAllowed((int)$r->warehouse_id);else abort_unless(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders'),404);return$r;
 }
 private function display(FinancialOriginRequest $r):array {
  $data=app(OriginRequestService::class)->summary($r);$items=\App\Models\Tenant\Item::query()->where('organization_id',$r->organization_id)->whereIn('id',$r->lines->pluck('item_id'))->pluck('name','id');
  foreach($data['lines'] as &$line){$source=$r->lines->firstWhere('id',$line['id']);$line['item_name']=$items[$line['item_id']]??null;$line['cancelled_quantity']=$source->cancelled_quantity;}
  return$data;
 }
 private function identity(FinancialOriginRequest $r):array{return['source_document_type'=>'sales_receipt','source_document_id'=>(int)$r->source_document_id,'source_document_number'=>$r->source_document_number,'source_journal_id'=>(int)$r->source_journal_id,'request_uuid'=>$r->request_uuid,'request_revision'=>$r->source_revision];}
}
