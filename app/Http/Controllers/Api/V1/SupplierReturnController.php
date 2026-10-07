<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Api\ApiController;
use App\Models\Tenant\{GoodsReceipt,StockLedger,SupplierReturn};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Documents\SupplierReturnService;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;

/** Physical purchasing returns; no permission borrowed from customer restocking or Finance credit approval. */
final class SupplierReturnController extends ApiController
{
 public function __construct(private SupplierReturnService$service,private WarehouseAccessService$warehouses,private InventoryPermissionService$permissions){}
 private function authorize(Request$request,bool$write=false):void{
  abort_unless($request->user()&&$this->permissions->can($request->user(),'inventory.view_stock'),403);
  if($write)abort_unless($this->permissions->can($request->user(),'inventory.manage_purchase_returns'),403);
 }
 private function scoped(SupplierReturn$return):void{
  $this->warehouses->assertAllowed((int)$return->warehouse_id);foreach($return->lines()->distinct()->pluck('warehouse_id')as$id)$this->warehouses->assertAllowed((int)$id);
 }
 public function index(Request$request):JsonResponse{
  $this->authorize($request);$query=SupplierReturn::query()->with('goodsReceipt:id,grn_number')->orderByDesc('id');$this->warehouses->scope($query);
  $allowed=$this->warehouses->allowedIds();if($allowed!==null)$query->whereDoesntHave('lines',fn($q)=>$q->whereNotIn('warehouse_id',$allowed));
  if($request->filled('status'))$query->where('status',$request->string('status')->toString());
  return $this->paginated($query->paginate(max(1,min(100,(int)$request->query('per_page',25))))->withQueryString());
 }
 public function show(Request$request,SupplierReturn$supplier_return):JsonResponse{
  $this->authorize($request);$this->scoped($supplier_return);$supplier_return->load(['lines.item:id,name,sku','goodsReceipt:id,grn_number']);
  return $this->success(['supplier_return'=>$supplier_return,'can_manage'=>$this->permissions->can($request->user(),'inventory.manage_purchase_returns')]);
 }
 public function source(Request$request,GoodsReceipt$goods_receipt):JsonResponse{
  $this->authorize($request);$this->warehouses->assertAllowed((int)$goods_receipt->warehouse_id);$goods_receipt->load('lines.item');
  abort_unless($goods_receipt->status==='posted'&&!$goods_receipt->reversed_at&&$goods_receipt->supplier_id,422);
  $lines=[];foreach($goods_receipt->lines as$line){$warehouse=(int)($line->warehouse_id?:$goods_receipt->warehouse_id);$this->warehouses->assertAllowed($warehouse);
   $physical=StockLedger::query()->where('organization_id',$goods_receipt->organization_id)->where('source_type',GoodsReceipt::class)->where('source_id',$goods_receipt->id)->where('source_line_id',$line->id)->where('direction','in')->get();
   foreach($physical as$entry){$returned=(string)DB::connection('tenant')->table('supplier_return_lines as l')->join('supplier_returns as r','r.id','=','l.supplier_return_id')
    ->where('l.organization_id',$goods_receipt->organization_id)->where('l.source_stock_ledger_id',$entry->id)->where('r.status','posted')->whereNull('r.reversed_at')->sum('l.quantity');
    $remaining=bcsub((string)$entry->quantity,$returned,8);if(bccomp($remaining,'0',8)<=0)continue;
    $factor=(string)$line->unit_conversion_factor;abort_unless(is_numeric($factor)&&bccomp($factor,'0',8)>0,422);
    $lines[]=['goods_receipt_line_id'=>$line->id,'source_stock_ledger_id'=>$entry->id,'item_id'=>$line->item_id,'item_name'=>$line->item?->name,'item_sku'=>$line->item?->sku,
     'warehouse_id'=>$warehouse,'entered_unit_id'=>$line->entered_unit_id,'unit_label'=>DB::connection('tenant')->table('units')->where('organization_id',$goods_receipt->organization_id)->where('id',$line->entered_unit_id)->value('code'),
     'remaining_entered_quantity'=>bcdiv($remaining,$factor,8),'base_quantity'=>$remaining,'lot_id'=>$entry->lot_id,'serial_id'=>$entry->serial_id,'bin_id'=>$entry->bin_id,'variant_id'=>$entry->variant_id,
     'lot_label'=>$entry->lot_id?DB::connection('tenant')->table('lots')->where('organization_id',$goods_receipt->organization_id)->where('id',$entry->lot_id)->value('lot_code'):null,
     'serial_label'=>$entry->serial_id?DB::connection('tenant')->table('serial_numbers')->where('organization_id',$goods_receipt->organization_id)->where('id',$entry->serial_id)->value('serial'):null,
     'bin_label'=>$entry->bin_id?DB::connection('tenant')->table('warehouse_bins')->where('organization_id',$goods_receipt->organization_id)->where('id',$entry->bin_id)->value('code'):null];
   }
  }
  return $this->success(['receipt_id'=>$goods_receipt->id,'receipt_number'=>$goods_receipt->grn_number,'warehouse_id'=>$goods_receipt->warehouse_id,'lines'=>$lines,
   'can_manage'=>$this->permissions->can($request->user(),'inventory.manage_purchase_returns')]);
 }
 public function store(Request$request):JsonResponse{
  $this->authorize($request,true);$data=$request->validate(['goods_receipt_id'=>'required|integer|min:1','return_date'=>'required|date','reason'=>'required|string|min:3|max:2000','notes'=>'nullable|string|max:5000','lines'=>'required|array|min:1']);
  return $this->success($this->service->createDraft(collect($data)->except('lines')->all(),$data['lines']),201);
 }
 public function post(Request$request,SupplierReturn$supplier_return):JsonResponse{
  $this->authorize($request,true);$this->scoped($supplier_return);return $this->success($this->service->post($supplier_return));
 }
 public function reverse(Request$request,SupplierReturn$supplier_return):JsonResponse{
  $this->authorize($request,true);$this->scoped($supplier_return);$data=$request->validate(['reason'=>'required|string|min:3|max:2000']);return $this->success($this->service->reverse($supplier_return,$data['reason']));
 }
}
