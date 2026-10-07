<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Purchasing\SupplierReturnRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Schema};
final class SupplierReturnRequestController extends Controller {
 public function __construct(private OrganizationContext$context,private WarehouseAccessService$warehouses,private SupplierReturnRequestService$service){}
 private function actor(Request$r):int{abort_unless(app(InventoryPermissionService::class)->can($r->user(),'inventory.view_stock'),403);return (int)$r->user()->id;}
 public function index(Request$r){
  $actor=$this->actor($r);$org=$this->context->idOrFail();$allowed=$this->warehouses->allowedIds($actor);$db=DB::connection('tenant');
  if(!Schema::connection('tenant')->hasTable('supplier_return_requests'))return response()->json(['success'=>true,'data'=>[],'available'=>false]);
  $rows=$db->table('supplier_return_requests as requests')->join('goods_receipts as receipts',function($join){$join->on('receipts.id','=','requests.goods_receipt_id')->on('receipts.organization_id','=','requests.organization_id');})
   ->leftJoin('supplier_returns as returns',function($join){$join->on('returns.id','=','requests.supplier_return_id')->on('returns.organization_id','=','requests.organization_id');})
   ->where('requests.organization_id',$org)->whereNull('receipts.deleted_at')->when($allowed!==null,fn($q)=>$q->whereIn('receipts.warehouse_id',$allowed))
   ->select('requests.*','receipts.grn_number','receipts.warehouse_id','receipts.status as receipt_status','receipts.posted_at as receipt_posted_at','receipts.reversed_at as receipt_reversed_at','returns.return_number','returns.status as return_status','returns.reversed_at as return_reversed_at')->latest('requests.id')->get();
  $mayPost=app(InventoryPermissionService::class)->can($r->user(),'inventory.manage_purchase_returns');
  $active=$db->table('warehouses')->where('organization_id',$org)->where('is_active',true)->pluck('id')->map(fn($id)=>(int)$id)->all();
  $result=[];
  foreach($rows as$row){
   if(!hash_equals((string)$row->payload_hash,hash('sha256',(string)$row->payload)))continue;
   $payload=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);$nativeLines=$db->table('goods_receipt_lines')->where('organization_id',$org)->where('goods_receipt_id',$row->goods_receipt_id)->get()->keyBy('id');$lines=[];$warehouses=[(int)$row->warehouse_id];$complete=true;
   foreach((array)($payload['lines']??[])as$line){
    $source=$nativeLines->get((int)($line['source_receipt_line_id']??0));if(!$source){$complete=false;continue;}
    $warehouse=(int)($source->warehouse_id?:$row->warehouse_id);$warehouses[]=$warehouse;
    if($allowed!==null&&!in_array($warehouse,$allowed,true)){$complete=false;continue;}
    $unitId=(int)($source->entered_unit_id??0);$unit=$db->table('units')->where('id',$unitId)->where(fn($q)=>$q->where('organization_id',$org)->orWhereNull('organization_id'))->value('name');
    $itemName=trim((string)($line['item_name']??''));if($itemName==='')$itemName=(string)$db->table('items')->where('organization_id',$org)->where('id',$source->item_id)->value('name');
    $lines[]=['source_receipt_line_id'=>(int)$source->id,'item_name'=>$itemName,'entered_quantity'=>(string)($line['entered_quantity']??''),'unit_id'=>$unitId,'unit_name'=>$unit];
   }
   // A mixed-warehouse source is one resource: never expose a partial unauthorized review.
   if(!$complete)continue;
   $warehouses=array_unique($warehouses);$authorized=$complete&&$lines;
   foreach($warehouses as$id)$authorized=$authorized&&in_array($id,$active,true)&&($allowed===null||in_array($id,$allowed,true));
   $canPost=$mayPost&&$authorized&&in_array($row->state,['requested','physical_pending'],true)&&$row->receipt_status==='posted'&&$row->receipt_posted_at&&!$row->receipt_reversed_at&&(!$row->supplier_return_id||($row->return_status==='draft'&&!$row->return_reversed_at));
   $result[]=['id'=>(int)$row->id,'operation_uuid'=>$row->operation_uuid,'source_bill_id'=>(int)$row->source_bill_id,'source_bill_number'=>$payload['source_bill_number']??null,'goods_receipt_id'=>(int)$row->goods_receipt_id,'grn_number'=>$row->grn_number,'warehouse_id'=>(int)$row->warehouse_id,'state'=>$row->state,'supplier_return_id'=>$row->supplier_return_id?(int)$row->supplier_return_id:null,'return_number'=>$row->return_number,'return_date'=>$payload['return_date']??null,'reason'=>$payload['reason']??null,'lines'=>$lines,'can_post'=>(bool)$canPost,'created_at'=>$row->created_at];
  }
  return response()->json(['success'=>true,'data'=>$result]);
 }
 public function post(Request$r,string$uuid){$actor=$this->actor($r);abort_unless(app(InventoryPermissionService::class)->can($r->user(),'inventory.manage_purchase_returns'),403);$r->validate(['arrival_confirmed'=>'accepted']);$org=$this->context->idOrFail();abort_unless(Schema::connection('tenant')->hasTable('supplier_return_requests'),503,'Supplier return requests are not installed for this tenant.');$row=DB::connection('tenant')->table('supplier_return_requests')->where('organization_id',$org)->where('operation_uuid',$uuid)->first();abort_unless($row,404);
  $result=$this->service->dispatch(['action'=>'purchasing.return_request.post','authority_kind'=>'supplier_return_request','actor_id'=>$actor,'data'=>['operation_uuid'=>$uuid,'organization_mapping_uuid'=>$row->organization_mapping_uuid,'source_bill_id'=>$row->source_bill_id,'finance_receipt_id'=>$row->finance_receipt_id,'arrival_confirmed'=>true]],(object)['id'=>$org]);return response()->json(['success'=>true,'data'=>$result]);
 }
}
