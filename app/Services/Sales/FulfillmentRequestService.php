<?php
namespace App\Services\Sales;
use App\Models\Tenant\{Customer,FulfillmentRequest,IntegrationMasterDataMapping,Item,SalesOrder,Shipment,Unit,Warehouse};
use App\Services\Documents\SalesOrderService;
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\Support\Decimal;
use App\Services\Access\WarehouseAccessService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class FulfillmentRequestService
{
 public function __construct(private OrganizationContext $context){}
 private function local($mapping,string $type,int $id,string $field):int {
  $pair=IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('central_client_id',$mapping->central_client_id)->where('central_organization_id',$mapping->central_organization_id)->where('finance_organization_id',$mapping->finance_organization_id)->where('solastock_organization_id',$mapping->solastock_organization_id)->where('entity_type',$type)->where('solabooks_record_id',(string)$id)->where('status','verified')->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->first();
  $class=match($type){'customer'=>Customer::class,'item'=>Item::class,'unit'=>Unit::class};
  if(!$pair||!$class::query()->whereKey((int)$pair->solastock_record_id)->where('is_active',true)->exists()){
   $e=ValidationException::withMessages([$field=>__('inventory.purchasing.mapping_required')]);$e->response=response()->json(['message'=>__('inventory.purchasing.mapping_required'),'errors'=>$e->errors(),'dependency'=>['entity_type'=>$type,'source_id'=>$id,'field'=>$field,'reason'=>'mapping_required']],422);throw $e;
  }return(int)$pair->solastock_record_id;
 }
 public function upsert(array $data,int $actor):array {
  \Illuminate\Support\Facades\Validator::make($data,['request_uuid'=>'required|uuid','source_invoice_id'=>'required|integer|min:1','source_revision'=>'required|string|size:64','source_status'=>'required|in:draft,posted','customer_external_id'=>'required|integer|min:1','invoice_date'=>'required|date_format:Y-m-d','currency_code'=>'required|string|size:3','base_currency_code'=>'required|string|size:3','lines'=>'required|array|min:1','lines.*.source_line_id'=>'required','lines.*.item_external_id'=>'required|integer|min:1','lines.*.unit_external_id'=>'required|integer|min:1','lines.*.quantity'=>'required|numeric|gt:0','lines.*.unit_price'=>'required|numeric|min:0'])->validate();
  $permission=($data['source_status']??null)==='posted'?'post':'edit_draft';
  $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],$permission);
  abort_unless(($authority['request_revision']??null)===$data['source_revision'],409,__('inventory.purchasing.source_changed'));
  $canonical=$data;unset($canonical['expected_revision']);$expected=(array)($authority['fulfillment_payload']??[]);unset($expected['expected_revision']);
  abort_unless($expected!==[]&&SolaStockJournalContract::canonicalJson($canonical)===SolaStockJournalContract::canonicalJson($expected),403);
  return DB::connection('tenant')->transaction(function()use($data){
   $m=app(ReceivingRequestService::class)->mapping();$m=$m->newQuery()->whereKey($m->id)->lockForUpdate()->firstOrFail();abort_unless($m->status==='verified'&&$m->activation_state==='active'&&(int)$m->solastock_organization_id===$this->context->idOrFail(),409);
   $r=FulfillmentRequest::query()->where('source_invoice_id',$data['source_invoice_id'])->lockForUpdate()->first();
   if($r){abort_unless($r->request_uuid===$data['request_uuid']&&$r->organization_mapping_uuid===$m->mapping_uuid,409);
    if($r->source_revision===$data['source_revision'])return$this->status($r);
    abort_unless($r->status==='pending'&&$r->sales_order_id===null&&($data['expected_revision']??null)===$r->source_revision,409,__('inventory.purchasing.edits_locked'));
   }
   $customer=$this->local($m,'customer',(int)$data['customer_external_id'],'customer_external_id');$lines=[];$ids=[];
   foreach($data['lines']as$i=>$line){$source=(string)$line['source_line_id'];abort_unless($source!==''&&!isset($ids[$source])&&Decimal::gt((string)$line['quantity'],'0'),422);$ids[$source]=true;
    $lines[]=['source_line_id'=>$source,'item_id'=>$this->local($m,'item',(int)$line['item_external_id'],"lines.$i.item_external_id"),'entered_unit_id'=>$this->local($m,'unit',(int)$line['unit_external_id'],"lines.$i.unit_external_id"),'requested_qty'=>Decimal::qty((string)$line['quantity']),'unit_price'=>Decimal::cost((string)$line['unit_price']),'discount_rate'=>Decimal::cost((string)($line['discount_rate']??'0'))];
   }abort_unless($lines!==[],422);
   $attrs=['organization_id'=>$this->context->idOrFail(),'organization_mapping_uuid'=>$m->mapping_uuid,'request_uuid'=>$data['request_uuid'],'source_invoice_id'=>$data['source_invoice_id'],'source_invoice_number'=>$data['source_invoice_number']??null,'source_revision'=>$data['source_revision'],'source_status'=>$data['source_status'],'posted_invoice_journal_id'=>$data['posted_invoice_journal_id']??null,'customer_id'=>$customer,'invoice_date'=>$data['invoice_date'],'requested_ship_date'=>$data['requested_ship_date']??null,'currency_code'=>$data['currency_code'],'base_currency_code'=>$data['base_currency_code'],'exchange_rate'=>$data['exchange_rate']??null,'exchange_rate_date'=>$data['exchange_rate_date']??null,'source_payload'=>$data,'approved_at'=>null,'approved_by'=>null,'approved_revision'=>null];
   if($r){$r->update($attrs);$r->lines()->delete();}else$r=FulfillmentRequest::create($attrs);
   foreach($lines as$l)$r->lines()->create($l+['organization_id'=>$r->organization_id]);return$this->status($r->fresh('lines'));
  },3);
 }
 public function status(FulfillmentRequest $request):array {
  $request->loadMissing('lines');$shipments=$request->sales_order_id?Shipment::query()->where('sales_order_id',$request->sales_order_id)->where('status','posted')->whereNull('reversal_sales_return_id')->get(['id','shipment_number','ship_date']):collect();
  return['id'=>$request->id,'request_uuid'=>$request->request_uuid,'number'=>'FR-'.$request->id,'source_invoice_id'=>$request->source_invoice_id,'source_revision'=>$request->source_revision,'status'=>$request->status,'warehouse_id'=>$request->warehouse_id,'sales_order_id'=>$request->sales_order_id,'approved_at'=>$request->approved_at?->toIso8601String(),'approved_revision'=>$request->approved_revision,'lines'=>$request->lines->map(fn($l)=>['id'=>$l->id,'source_line_id'=>$l->source_line_id,'item_id'=>$l->item_id,'entered_unit_id'=>$l->entered_unit_id,'requested_qty'=>(string)$l->requested_qty,'fulfilled_qty'=>(string)$l->fulfilled_qty])->all(),'shipments'=>$shipments->toArray()];
 }
 public function approve(FulfillmentRequest $request,int $warehouse):array {
  abort_unless(app(\App\Services\Access\InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders'),403);
  app(WarehouseAccessService::class)->assertAllowed($warehouse);Warehouse::query()->whereKey($warehouse)->where('is_active',true)->firstOrFail();
  return DB::connection('tenant')->transaction(function()use($request,$warehouse){$r=FulfillmentRequest::query()->with('lines')->whereKey($request->id)->lockForUpdate()->firstOrFail();abort_unless($r->status==='pending',409);
   if($r->sales_order_id)return$this->status($r);
   $native=app(SalesOrderService::class);$order=$native->createDraft(['warehouse_id'=>$warehouse,'customer_id'=>$r->customer_id,'source_app'=>'solabooks','source_document_id'=>(string)$r->source_invoice_id,'source_document_number'=>$r->source_invoice_number,'order_date'=>$r->invoice_date?->format('Y-m-d'),'requested_ship_date'=>$r->requested_ship_date?->format('Y-m-d'),'currency_code'=>$r->currency_code],$r->lines->map(fn($l)=>['item_id'=>$l->item_id,'entered_unit_id'=>$l->entered_unit_id,'ordered_qty'=>$l->requested_qty,'unit_price'=>$l->unit_price,'discount_rate'=>$l->discount_rate,'tax_rate'=>'0'])->all());
   $order=$native->confirm($order);foreach($r->lines as$i=>$line)$line->update(['sales_order_line_id'=>$order->lines[$i]->id]);
   $r->update(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse,'approved_at'=>now(),'approved_by'=>auth()->id(),'approved_revision'=>$r->source_revision]);return$this->status($r->fresh('lines'));
  },3);
 }
 public function cancel(array $data,int $actor):array {
  $known=FulfillmentRequest::query()->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id'])->firstOrFail();
  $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],$known->source_status==='posted'?'post':'edit_draft',['command'=>'cancel','request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'expected_revision'=>$data['expected_revision']??null]);
  abort_unless(($authority['request_uuid']??null)===$data['request_uuid']&&($authority['command']??null)==='cancel',403);
  return DB::connection('tenant')->transaction(function()use($data){$r=FulfillmentRequest::query()->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id'])->lockForUpdate()->firstOrFail();
   abort_unless(($data['expected_revision']??$data['source_revision'])===$r->source_revision,409);
   if($r->status==='cancelled')return$this->status($r);
   if($r->sales_order_id){$order=SalesOrder::query()->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail();if($order->status!=='shipped'&&$order->status!=='cancelled')app(SalesOrderService::class)->cancel($order);}
   $r->update(['status'=>'cancelled']);return$this->status($r);
  },3);
 }
 public function posted(Shipment $shipment):void {
  if(!$shipment->sales_order_id)return;$r=FulfillmentRequest::query()->where('sales_order_id',$shipment->sales_order_id)->lockForUpdate()->first();if(!$r)return;
  $r->loadMissing('lines');foreach($r->lines as$l){$source=\App\Models\Tenant\SalesOrderLine::query()->whereKey($l->sales_order_line_id)->firstOrFail();$factor=(string)($source->unit_conversion_factor?:'1');$l->update(['fulfilled_qty'=>Decimal::qty(Decimal::div((string)$source->shipped_qty,$factor))]);}
  $r->status=$r->lines()->get()->every(fn($l)=>Decimal::gte((string)$l->fulfilled_qty,(string)$l->requested_qty))?'complete':'partial';$r->save();
 }
}
