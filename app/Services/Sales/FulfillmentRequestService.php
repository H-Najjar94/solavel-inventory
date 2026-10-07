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
  \Illuminate\Support\Facades\Validator::make($data,['request_uuid'=>'required|uuid','source_invoice_id'=>'required|integer|min:1','source_revision'=>'required|string|size:64','source_status'=>'required|in:draft,posted','pricing_mode'=>'required|in:exclusive','customer_external_id'=>'required|integer|min:1','invoice_date'=>'required|date_format:Y-m-d','currency_code'=>'required|string|size:3','base_currency_code'=>'required|string|size:3','lines'=>'required|array|min:1','lines.*.source_line_id'=>'required','lines.*.item_external_id'=>'required|integer|min:1','lines.*.unit_external_id'=>'required|integer|min:1','lines.*.quantity'=>'required|numeric|gt:0','lines.*.unit_price'=>'required|numeric|min:0','lines.*.discount_rate'=>'nullable|numeric|in:0'])->validate();
  $permission=($data['source_status']??null)==='posted'?'post':'edit_draft';
  $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],$permission);
  abort_unless(($authority['request_revision']??null)===$data['source_revision'],409,__('inventory.purchasing.source_changed'));
  $canonical=$data;unset($canonical['expected_revision']);$expected=(array)($authority['fulfillment_payload']??[]);unset($expected['expected_revision']);
  abort_unless($expected!==[]&&SolaStockJournalContract::canonicalJson($canonical)===SolaStockJournalContract::canonicalJson($expected),403);
  return DB::connection('tenant')->transaction(function()use($data){
   $m=app(ReceivingRequestService::class)->mapping();$this->lockFinanceCommand($m,$data,'upsert');$m=$m->newQuery()->whereKey($m->id)->lockForUpdate()->firstOrFail();abort_unless($m->status==='verified'&&$m->activation_state==='active'&&(int)$m->solastock_organization_id===$this->context->idOrFail(),409);
   abort_if($this->cancellationQuery($m,$data)->lockForUpdate()->exists(),409,__('inventory.purchasing.edits_locked'));
   $r=FulfillmentRequest::query()->where('source_invoice_id',$data['source_invoice_id'])->lockForUpdate()->first();
   if($r){abort_unless($r->request_uuid===$data['request_uuid']&&$r->organization_mapping_uuid===$m->mapping_uuid,409);
    if($r->source_revision===$data['source_revision'])return$this->status($r);
    if($r->sales_order_id!==null){
     $before=(array)$r->source_payload;$after=$data;
     foreach(['source_revision','expected_revision','source_status','posted_invoice_journal_id','source_invoice_number']as$key){unset($before[$key],$after[$key]);}
     abort_unless($r->source_status==='draft'&&$data['source_status']==='posted'&&($data['expected_revision']??null)===$r->source_revision&&SolaStockJournalContract::canonicalJson($before)===SolaStockJournalContract::canonicalJson($after),409,__('inventory.purchasing.edits_locked'));
     $r->update(['source_status'=>'posted','posted_invoice_journal_id'=>$data['posted_invoice_journal_id'],'source_revision'=>$data['source_revision'],'source_payload'=>$data,'source_invoice_number'=>$data['source_invoice_number']??$r->source_invoice_number,'approved_revision'=>$data['source_revision']]);
     return$this->changed($r);
    }
    abort_unless($r->status==='pending'&&($data['expected_revision']??null)===$r->source_revision,409,__('inventory.purchasing.edits_locked'));
   }
   $customer=$this->local($m,'customer',(int)$data['customer_external_id'],'customer_external_id');$lines=[];$ids=[];
   foreach($data['lines']as$i=>$line){$source=(string)$line['source_line_id'];abort_unless($source!==''&&!isset($ids[$source])&&Decimal::gt((string)$line['quantity'],'0'),422);$ids[$source]=true;
    $lines[]=['source_line_id'=>$source,'item_id'=>$this->local($m,'item',(int)$line['item_external_id'],"lines.$i.item_external_id"),'entered_unit_id'=>$this->local($m,'unit',(int)$line['unit_external_id'],"lines.$i.unit_external_id"),'requested_qty'=>Decimal::qty((string)$line['quantity']),'unit_price'=>Decimal::cost((string)$line['unit_price']),'discount_rate'=>'0.0000'];
   }abort_unless($lines!==[],422);
   $attrs=['organization_id'=>$this->context->idOrFail(),'organization_mapping_uuid'=>$m->mapping_uuid,'request_uuid'=>$data['request_uuid'],'source_invoice_id'=>$data['source_invoice_id'],'source_invoice_number'=>$data['source_invoice_number']??null,'source_revision'=>$data['source_revision'],'source_status'=>$data['source_status'],'posted_invoice_journal_id'=>$data['posted_invoice_journal_id']??null,'customer_id'=>$customer,'invoice_date'=>$data['invoice_date'],'requested_ship_date'=>$data['requested_ship_date']??null,'currency_code'=>$data['currency_code'],'base_currency_code'=>$data['base_currency_code'],'exchange_rate'=>$data['exchange_rate']??null,'exchange_rate_date'=>$data['exchange_rate_date']??null,'source_payload'=>$data,'approved_at'=>null,'approved_by'=>null,'approved_revision'=>null];
   if($r){$r->update($attrs);$r->lines()->delete();}else$r=FulfillmentRequest::create($attrs);
   foreach($lines as$l)$r->lines()->create($l+['organization_id'=>$r->organization_id]);return$this->changed($r->fresh('lines'));
  },3);
 }
 /** Canonical shared tenant records close the callback-to-publication race; no remote call under locks. */
 private function lockFinanceCommand($mapping,array $data,string $command):void {
  $invoice=DB::connection('tenant')->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$data['source_invoice_id'])->lockForUpdate()->first();abort_unless($invoice,404);
  $intent=DB::connection('tenant')->table('finance_sales_requests')->where('organization_id',$mapping->finance_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('invoice_id',$data['source_invoice_id'])->where('request_uuid',$data['request_uuid'])->lockForUpdate()->first();
  abort_unless($intent&&$intent->command===$command&&$intent->source_revision===$data['source_revision'],409,__('inventory.purchasing.source_changed'));
  if($command==='upsert'&&($data['source_status']??null)==='posted')abort_unless((int)($intent->invoice_journal_id??0)>0,409);
  if((int)($intent->invoice_journal_id??0)>0){
   $journalId=(int)$intent->invoice_journal_id;abort_unless((int)($data['posted_invoice_journal_id']??$data['closing_invoice_journal_id']??0)===$journalId,409);
   $journal=DB::connection('tenant')->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$journalId)->where('source_type','App\\Models\\Invoice')->where('source_id',$invoice->id)->lockForUpdate()->first();
   abort_unless($journal&&$journal->status==='posted'&&empty($journal->voided_at)&&empty($journal->deleted_at),409,__('inventory.purchasing.source_changed'));
  }
 }
 private function changed(FulfillmentRequest $request):array {
  if(class_exists(SalesNotificationPublisher::class))app(SalesNotificationPublisher::class)->changed($request);
  return$this->status($request);
 }
 /** Acquired before the SO lock in ShipmentService; cancellation uses this same request→SO order. */
 public function lockShipmentSource(Shipment $shipment):?FulfillmentRequest {
  if(!$shipment->sales_order_id)return null;
  $known=FulfillmentRequest::query()->where('sales_order_id',$shipment->sales_order_id)->first();
  if(!$known)return null;
  $mapping=app(ReceivingRequestService::class)->mapping();
  $this->lockFinanceCommand($mapping,$known->source_payload,'upsert');
  $request=FulfillmentRequest::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();
  abort_unless($request->source_revision===$known->source_revision,409,__('inventory.purchasing.source_changed'));
  if(DB::connection('tenant')->getSchemaBuilder()->hasTable('sales_fulfillment_demand_commands'))foreach(DB::connection('tenant')->table('sales_fulfillment_demand_commands')->where('organization_id',$request->organization_id)->where('fulfillment_request_id',$request->id)->whereIn('state',['prepared','reverse_prepared'])->get()as$hold)abort_unless(!collect(json_decode($hold->payload,true)['lines']??[])->contains(fn($line)=>Decimal::gt((string)$line['unfulfilled_quantity'],'0')),409,__('inventory.sales_handoff.credit_demand_pending'));
  return$request;
 }
 public function guardOrderDemand(int $orderId):void {
  if(!$orderId)return;
  $known=FulfillmentRequest::query()->where('sales_order_id',$orderId)->first();if(!$known)return;
  $mapping=\App\Models\Tenant\IntegrationOrganizationMapping::query()->where('solastock_organization_id',$known->organization_id)->where('mapping_uuid',$known->organization_mapping_uuid)->firstOrFail();
  DB::connection('tenant')->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$known->source_invoice_id)->lockForUpdate()->sole();
  $request=FulfillmentRequest::query()->whereKey($known->id)->lockForUpdate()->firstOrFail();
  if(DB::connection('tenant')->getSchemaBuilder()->hasTable('sales_fulfillment_demand_commands'))foreach(DB::connection('tenant')->table('sales_fulfillment_demand_commands')->where('organization_id',$request->organization_id)->where('fulfillment_request_id',$request->id)->whereIn('state',['prepared','reverse_prepared'])->get()as$hold)abort_unless(!collect(json_decode($hold->payload,true)['lines']??[])->contains(fn($line)=>Decimal::gt((string)$line['unfulfilled_quantity'],'0')),409,__('inventory.sales_handoff.credit_demand_pending'));
 }
 public function validateShipment(Shipment $shipment):void {
  $request=$this->lockShipmentSource($shipment);if(!$request)return;
  abort_unless(in_array($request->status,['pending','partial'],true)&&$request->approved_at&&$request->approved_revision===$request->source_revision&&(int)$request->warehouse_id===(int)$shipment->warehouse_id,409,__('inventory.sales_handoff.order_not_dispatchable'));
 }
 public function status(FulfillmentRequest $request, ?CreditDemandScope $scope=null):array {
  $request->loadMissing('lines');if($scope&&$request->sales_order_id)$scope->assertOrder((int)$request->organization_id,(int)$request->sales_order_id);$shipmentQuery=$scope?Shipment::withoutGlobalScope('warehouse_access'):Shipment::query();$shipments=$request->sales_order_id?$shipmentQuery->where('organization_id',$request->organization_id)->where('sales_order_id',$request->sales_order_id)->where('status','posted')->whereNull('reversal_sales_return_id')->whereNotIn('id',array_column((array)data_get($request->source_payload,'origin_order.source_shipment_refs',[]),'id'))->get(['id','shipment_number','ship_date']):collect();
  return['id'=>$request->id,'request_uuid'=>$request->request_uuid,'number'=>'FR-'.$request->id,'source_invoice_id'=>$request->source_invoice_id,'source_invoice_number'=>$request->source_invoice_number,'source_revision'=>$request->source_revision,'status'=>$request->status,'warehouse_id'=>$request->warehouse_id,'sales_order_id'=>$request->sales_order_id,'approved_at'=>$request->approved_at?->toIso8601String(),'approved_revision'=>$request->approved_revision,'fulfilled_quantity'=>$request->lines->reduce(fn($sum,$line)=>Decimal::add($sum,(string)$line->fulfilled_qty),'0.0000'),'shipment_ids'=>$shipments->pluck('id')->all(),'lines'=>$request->lines->map(fn($l)=>['id'=>$l->id,'source_line_id'=>$l->source_line_id,'item_id'=>$l->item_id,'item_name'=>Item::query()->find($l->item_id)?->name,'entered_unit_id'=>$l->entered_unit_id,'remaining_qty'=>Decimal::sub(Decimal::sub((string)$l->requested_qty,(string)$l->fulfilled_qty),(string)($l->cancelled_qty??'0')),'requested_quantity'=>(string)$l->requested_qty,'fulfilled_quantity'=>(string)$l->fulfilled_qty,'cancelled_quantity'=>(string)($l->cancelled_qty??'0.0000'),'requested_qty'=>(string)$l->requested_qty,'fulfilled_qty'=>(string)$l->fulfilled_qty])->all(),'shipments'=>$shipments->toArray()];
 }
 public function approve(FulfillmentRequest $request,int $warehouse):array {
  abort_unless(app(\App\Services\Access\InventoryPermissionService::class)->can(request()->user(),'inventory.manage_sales_orders'),403);
  app(WarehouseAccessService::class)->assertAllowed($warehouse);Warehouse::query()->whereKey($warehouse)->where('is_active',true)->firstOrFail();
  return DB::connection('tenant')->transaction(function()use($request,$warehouse){$this->lockFinanceCommand(app(ReceivingRequestService::class)->mapping(),$request->source_payload,'upsert');$r=FulfillmentRequest::query()->with('lines')->whereKey($request->id)->lockForUpdate()->firstOrFail();abort_unless($r->status==='pending',409);
   if($r->sales_order_id)return$this->status($r);
   if(array_key_exists('origin_order',(array)$r->source_payload)){
    $order=app(StockBornOrderReuse::class)->lockedOrder($r,$warehouse);
    $r->update(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse,'approved_at'=>now(),'approved_by'=>auth()->id(),'approved_revision'=>$r->source_revision]);return$this->changed($r->fresh('lines'));
   }
   $native=app(SalesOrderService::class);$order=$native->createDraft(['warehouse_id'=>$warehouse,'customer_id'=>$r->customer_id,'source_app'=>'solabooks','source_document_id'=>(string)$r->source_invoice_id,'source_document_number'=>$r->source_invoice_number,'order_date'=>$r->invoice_date?->format('Y-m-d'),'requested_ship_date'=>$r->requested_ship_date?->format('Y-m-d'),'currency_code'=>$r->currency_code],$r->lines->map(fn($l)=>['item_id'=>$l->item_id,'entered_unit_id'=>$l->entered_unit_id,'ordered_qty'=>$l->requested_qty,'unit_price'=>$l->unit_price,'discount_rate'=>$l->discount_rate,'tax_rate'=>'0'])->all());
   $order=$native->confirm($order);foreach($r->lines as$i=>$line){$source=$order->lines[$i];if(Decimal::gt((string)($line->cancelled_qty??'0'),'0'))$source->update(['cancelled_qty'=>Decimal::qty(Decimal::mul((string)$line->cancelled_qty,(string)($source->unit_conversion_factor?:'1')))]);$line->update(['sales_order_line_id'=>$source->id]);}
   $r->update(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse,'approved_at'=>now(),'approved_by'=>auth()->id(),'approved_revision'=>$r->source_revision]);return$this->changed($r->fresh('lines'));
  },3);
 }
 private function cancellationQuery($mapping,array $data) {
  return DB::connection('tenant')->table('sales_fulfillment_cancellations')->where('organization_id',$this->context->idOrFail())->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id']);
 }
 private function cancellationStatus(object $cancel):array {
  return ['id'=>null,'number'=>null,'source_invoice_number'=>data_get(json_decode($cancel->source_payload,true),'source_invoice_number'),'request_uuid'=>$cancel->request_uuid,'source_invoice_id'=>(int)$cancel->source_invoice_id,'source_revision'=>$cancel->source_revision,'status'=>'cancelled','warehouse_id'=>null,'sales_order_id'=>null,'fulfilled_quantity'=>'0.0000','shipment_ids'=>[],'shipments'=>[],'lines'=>[],'cancelled_before_acceptance'=>true];
 }
 public function sourceStatus(array $data,int $actor):array {
  app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],'view');
  $mapping=app(ReceivingRequestService::class)->mapping();
  $request=FulfillmentRequest::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id'])->first();
  if($request)return $this->status($request);
  $cancel=$this->cancellationQuery($mapping,$data)->first();abort_unless($cancel,404);
  return $this->cancellationStatus($cancel);
 }
 public function cancel(array $data,int $actor):array {
  \Illuminate\Support\Facades\Validator::make($data,['request_uuid'=>'required|uuid','source_invoice_id'=>'required|integer|min:1','source_revision'=>'required|string|size:64','expected_revision'=>'nullable|string|size:64','close_permission'=>'nullable|in:unpost,void','closing_invoice_journal_id'=>'nullable|integer|min:1'])->validate();
  $known=FulfillmentRequest::query()->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id'])->first();
  $permission=$data['close_permission']??(($known?->source_status??$data['source_status']??'draft')==='posted'?'post':'edit_draft');
  $review=['command'=>'cancel','request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'expected_revision'=>$data['expected_revision']??null];
  if(isset($data['closing_invoice_journal_id']))$review['closing_invoice_journal_id']=$data['closing_invoice_journal_id'];
  $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],$permission,$review);
  abort_unless(($authority['request_uuid']??null)===$data['request_uuid']&&($authority['command']??null)==='cancel'&&($authority['command_source_revision']??null)===$data['source_revision']&&($authority['expected_revision']??null)===($data['expected_revision']??null),403);
  if(isset($data['close_permission']))abort_unless(($authority['closure_permission']??null)===$data['close_permission']&&(int)($authority['closing_invoice_journal_id']??0)===(int)($data['closing_invoice_journal_id']??0),403);
  return DB::connection('tenant')->transaction(function()use($data,$known,$actor){
   $mapping=app(ReceivingRequestService::class)->mapping();
   $this->lockFinanceCommand($mapping,array_replace((array)$known?->source_payload,$data),'cancel');
   $r=FulfillmentRequest::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$data['source_invoice_id'])->lockForUpdate()->first();
   if(!$r){
    // The exact Finance intent row is locked first, so delayed acceptance cannot race this tombstone.
    abort_if(FulfillmentRequest::query()->where('source_invoice_id',$data['source_invoice_id'])->exists(),409);
    abort_if(\App\Models\Tenant\FulfillmentCommand::query()->where('source_invoice_id',$data['source_invoice_id'])->where('status','!=','abandoned')->exists(),409);
    $cancel=$this->cancellationQuery($mapping,$data)->lockForUpdate()->first();
    if(!$cancel){DB::connection('tenant')->table('sales_fulfillment_cancellations')->insert(['organization_id'=>$this->context->idOrFail(),'organization_mapping_uuid'=>$mapping->mapping_uuid,'request_uuid'=>$data['request_uuid'],'source_invoice_id'=>$data['source_invoice_id'],'source_revision'=>$data['source_revision'],'actor_id'=>$actor,'source_payload'=>json_encode($data,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);$cancel=$this->cancellationQuery($mapping,$data)->first();}
    abort_unless($cancel->source_revision===$data['source_revision'],409);
    return $this->cancellationStatus($cancel);
   }
   abort_unless(($data['expected_revision']??$data['source_revision'])===$r->source_revision,409);
   if($r->status==='cancelled')return $this->status($r);
   if(DB::connection('tenant')->getSchemaBuilder()->hasTable('sales_fulfillment_demand_commands'))abort_if(DB::connection('tenant')->table('sales_fulfillment_demand_commands')->where('organization_id',$r->organization_id)->where('fulfillment_request_id',$r->id)->whereIn('state',['prepared','reverse_prepared'])->exists(),409,__('inventory.sales_handoff.credit_demand_pending'));
   if($r->sales_order_id){$order=SalesOrder::query()->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail();if($order->status!=='shipped'&&$order->status!=='cancelled')app(SalesOrderService::class)->cancel($order);}
   $r->update(['status'=>'cancelled']);return $this->changed($r);
  },3);
 }
 public function posted(Shipment $shipment):void {
  if(!$shipment->sales_order_id)return;$r=FulfillmentRequest::query()->where('sales_order_id',$shipment->sales_order_id)->lockForUpdate()->first();if(!$r)return;
  $r->loadMissing('lines');foreach($r->lines as$l){$source=\App\Models\Tenant\SalesOrderLine::query()->whereKey($l->sales_order_line_id)->firstOrFail();$factor=(string)$source->unit_conversion_factor;abort_unless(Decimal::gt($factor,'0'),409);$shipped=Decimal::sub((string)$source->shipped_qty,(string)($l->source_shipped_qty_base??'0'));abort_unless(Decimal::gte($shipped,'0'),409);$l->update(['fulfilled_qty'=>Decimal::qty(Decimal::div($shipped,$factor))]);}
  $r->status=$r->lines()->get()->every(fn($l)=>Decimal::gte(Decimal::add((string)$l->fulfilled_qty,(string)($l->cancelled_qty??'0')),(string)$l->requested_qty))?'complete':'partial';$r->save();$this->changed($r);
 }
}
