<?php
namespace App\Services\Purchasing;
use App\Models\Tenant\{GoodsReceipt,StockLedger,SupplierReturn,IntegrationOrganizationMapping};
use App\Services\Access\{InventoryPermissionService,WarehouseAccessService};
use App\Services\Documents\SupplierReturnService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Support\Facades\{DB,Auth};
/** Closed nonphysical requests never borrow the warehouse actor's physical authority. */
final class SupplierReturnRequestService {
 public function dispatch(array$envelope,object$organization):array {
  $action=str_replace('purchasing.return_request.','',(string)($envelope['action']??$envelope['event_type']??''));abort_unless(in_array($action,['options','create','post','status'],true),422);
  $actor=(int)($envelope['actor_id']??0);abort_unless($actor>0&&($envelope['authority_kind']??null)==='supplier_return_request',403);
  $data=(array)($envelope['data']??[]);$transport=app(SolaBooksOutboxDeliveryService::class);abort_unless(method_exists($transport,'authorizeSupplierReturnRequest'),503);
  // Independent signed Finance callback happens before all native row locks.
  $proof=$transport->authorizeSupplierReturnRequest($data,$action,$actor);
  abort_unless(($proof['allowed']??false)&&($proof['contract']??null)==='purchasing.return_request.v1'&&(int)($proof['actor_id']??0)===$actor&&($proof['operation']??null)===$action,403);
  $payload=(array)($proof['canonical_payload']??[]);$org=(int)$organization->id;
  $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$payload['organization_mapping_uuid']??'')->where('solastock_organization_id',$org)->where('finance_organization_id',$payload['finance_organization_id']??0)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->where('status','verified')->where('activation_state','active')->firstOrFail();
  // Signed Finance source proof grants only nonphysical provenance inspection; physical authority is independently enforced below.
  $receipt=GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($payload['stock_receipt_id']??0)->with('lines')->firstOrFail();abort_unless($receipt->status==='posted'&&$receipt->posted_at&&!$receipt->reversed_at,409);
  if($action==='options')return $this->options($receipt,$payload,$actor);
  validator($payload,['operation_uuid'=>'required|uuid','lines'=>'required|array|min:1','return_date'=>'required|date','reason'=>'required|string|min:3'])->validate();
  $json=json_encode($payload,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);$db=DB::connection('tenant');
  $request=$db->transaction(function()use($db,$receipt,$payload,$json,$hash,$mapping,$org,$proof,$action){
   $this->lockFinancialSource($payload,$mapping);
   GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
   $row=$db->table('supplier_return_requests')->where('organization_id',$org)->where('operation_uuid',$payload['operation_uuid'])->lockForUpdate()->first();
   if($row){abort_unless($row->organization_mapping_uuid===$mapping->mapping_uuid&&hash_equals($row->payload_hash,$hash),409);return $row;}
   abort_unless($action==='create',404);$this->assertSourceLines($receipt,$payload,$org);
   $id=$db->table('supplier_return_requests')->insertGetId(['organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid,'operation_uuid'=>$payload['operation_uuid'],'finance_organization_id'=>$mapping->finance_organization_id,'source_bill_id'=>$payload['source_bill_id'],'bill_journal_id'=>$payload['bill_journal_id'],'finance_receipt_id'=>$payload['finance_receipt_id'],'goods_receipt_id'=>$receipt->id,'receipt_mapping_uuid'=>$payload['receipt_mapping_uuid'],'request_actor_id'=>$proof['request_actor_id']??$payload['actor_id'],'state'=>'requested','payload_hash'=>$hash,'payload'=>$json,'created_at'=>now(),'updated_at'=>now()]);return $db->table('supplier_return_requests')->where('id',$id)->first();
  });
  if($action!=='post')return $this->result($request);
  abort_unless(($data['arrival_confirmed']??false)===true||($data['arrival_confirmed']??null)==='1',422);
  $user=\App\Models\User::query()->whereKey($actor)->firstOrFail();abort_unless(app(InventoryPermissionService::class)->can($user,'inventory.manage_purchase_returns'),403);
  $this->assertActiveWarehouses($receipt);$allowed=app(WarehouseAccessService::class)->allowedIds($actor);abort_unless($allowed===null||in_array((int)$receipt->warehouse_id,$allowed,true),403);
  $previous=Auth::user();Auth::setUser($user);
  try {
   $request=$db->transaction(function()use($db,$request,$receipt,$actor,$org,$payload,$mapping){
    $this->lockFinancialSource($payload,$mapping);
    GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($receipt->id)->lockForUpdate()->firstOrFail();$row=$db->table('supplier_return_requests')->where('organization_id',$org)->where('id',$request->id)->lockForUpdate()->first();
    if($row->supplier_return_id)return $row;$payload=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);$this->assertSourceLines($receipt,$payload,$org,$row->id);
    $lines=array_map(fn($l)=>['goods_receipt_line_id'=>$l['source_receipt_line_id'],'source_stock_ledger_id'=>$l['source_stock_ledger_id'],'entered_qty'=>$l['entered_quantity']],$payload['lines']);
    $draft=app(SupplierReturnService::class)->createDraft(['goods_receipt_id'=>$receipt->id,'return_date'=>$payload['return_date'],'reason'=>$payload['reason']],$lines);
    $db->table('supplier_return_requests')->where('id',$row->id)->update(['supplier_return_id'=>$draft->id,'physical_actor_id'=>$actor,'state'=>'physical_pending','updated_at'=>now()]);return $db->table('supplier_return_requests')->where('id',$row->id)->first();
   });
   // Draft identity is committed before native physical post; uncertain retries reuse it.
   $draft=SupplierReturn::query()->where('organization_id',$org)->whereKey($request->supplier_return_id)->firstOrFail();$posted=$db->transaction(function()use($draft,$payload,$mapping){$this->lockFinancialSource($payload,$mapping);$receipt=GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$draft->organization_id)->whereKey($draft->goods_receipt_id)->with('lines')->lockForUpdate()->firstOrFail();$this->assertActiveWarehouses($receipt);return app(SupplierReturnService::class)->post($draft);});
   $db->table('supplier_return_requests')->where('id',$request->id)->update(['state'=>'posted','physical_actor_id'=>$actor,'updated_at'=>now()]);return $this->result($db->table('supplier_return_requests')->where('id',$request->id)->first());
  }finally{if($previous)Auth::setUser($previous);else Auth::forgetUser();}
 }
 private function options(GoodsReceipt$r,array$p,int$actor):array {
  $known=collect($p['source_lines']??[])->keyBy('source_receipt_line_id');$lines=[];foreach($r->lines as$l){$source=$known->get($l->id);if(!$source)continue;
   $ledgers=StockLedger::withoutGlobalScope('warehouse_access')->where('organization_id',$r->organization_id)->where('source_type',GoodsReceipt::class)->where('source_id',$r->id)->where('source_line_id',$l->id)->where('direction','in')->get();
   $lines[]=['source_receipt_line_id'=>$l->id,'bill_line_id'=>$source['bill_line_id'],'unit_id'=>$l->entered_unit_id,'unit_conversion_factor'=>$l->unit_conversion_factor,'base_unit_id'=>$l->base_unit_id,'accepted_base_quantity'=>$l->accepted_qty,'source_stock_rows'=>$ledgers->map(fn($x)=>['id'=>$x->id,'quantity'=>$x->quantity,'lot_id'=>$x->lot_id,'serial_id'=>$x->serial_id,'bin_id'=>$x->bin_id,'variant_id'=>$x->variant_id])->all()];
  }$user=\App\Models\User::query()->whereKey($actor)->first();$allowed=app(WarehouseAccessService::class)->allowedIds($actor);$canPost=$user&&app(InventoryPermissionService::class)->can($user,'inventory.manage_purchase_returns');foreach($r->lines as $line)$canPost=$canPost&&($allowed===null||in_array((int)($line->warehouse_id?:$r->warehouse_id),$allowed,true));$canPost=$canPost&&($allowed===null||in_array((int)$r->warehouse_id,$allowed,true))&&$this->activeWarehouses($r);return ['stock_receipt_id'=>$r->id,'warehouse_id'=>$r->warehouse_id,'can_post'=>(bool)$canPost,'lines'=>$lines];
 }
 private function assertSourceLines(GoodsReceipt$r,array$p,int$org,?int$exclude=null):void {
  $known=collect($p['source_lines']??[])->keyBy('source_receipt_line_id');$requested=[];$requestedLedger=[];
  foreach($p['lines']as$l){$source=$r->lines->firstWhere('id',(int)$l['source_receipt_line_id']);$bound=$known->get($l['source_receipt_line_id']);abort_unless($source&&$bound&&(int)$l['bill_line_id']===(int)$bound['bill_line_id']&&is_numeric($source->unit_conversion_factor)&&bccomp((string)$source->unit_conversion_factor,'0',8)>0,409);
   $ledger=StockLedger::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($l['source_stock_ledger_id'])->where('source_type',GoodsReceipt::class)->where('source_id',$r->id)->where('source_line_id',$source->id)->where('direction','in')->firstOrFail();
   abort_unless(is_numeric($l['entered_quantity'])&&bccomp((string)$l['entered_quantity'],'0',8)>0&&bccomp((string)$l['entered_quantity'],(string)$bound['confirmed_entered_quantity'],8)<=0,409);
   $qty=bcmul((string)$l['entered_quantity'],(string)$source->unit_conversion_factor,8);$requestedLedger[$ledger->id]=bcadd($requestedLedger[$ledger->id]??'0',$qty,8);$requested[$source->id]=bcadd($requested[$source->id]??'0',$qty,8);
   $returned=(string)DB::connection('tenant')->table('supplier_return_lines as l')->join('supplier_returns as h','h.id','=','l.supplier_return_id')->where('l.organization_id',$org)->where('l.goods_receipt_line_id',$source->id)->where('h.status','posted')->whereNull('h.reversed_at')->sum('l.quantity');
   $returnedLedger=(string)DB::connection('tenant')->table('supplier_return_lines as l')->join('supplier_returns as h','h.id','=','l.supplier_return_id')->where('l.organization_id',$org)->where('l.source_stock_ledger_id',$ledger->id)->where('h.status','posted')->whereNull('h.reversed_at')->sum('l.quantity');
   $pending='0';$pendingLedger='0';foreach(DB::connection('tenant')->table('supplier_return_requests')->where('organization_id',$org)->where('goods_receipt_id',$r->id)->whereIn('state',['requested','physical_pending'])->when($exclude,fn($q)=>$q->where('id','<>',$exclude))->lockForUpdate()->get()as$other)foreach(json_decode($other->payload,true)['lines']as$ol)if((int)$ol['source_receipt_line_id']===$source->id){$amount=bcmul($ol['entered_quantity'],(string)$source->unit_conversion_factor,8);$pending=bcadd($pending,$amount,8);if((int)$ol['source_stock_ledger_id']===$ledger->id)$pendingLedger=bcadd($pendingLedger,$amount,8);}
   abort_unless(bccomp(bcadd(bcadd($returned,$pending,8),$requested[$source->id],8),(string)$source->accepted_qty,8)<=0&&bccomp(bcadd(bcadd($returnedLedger,$pendingLedger,8),$requestedLedger[$ledger->id],8),(string)$ledger->quantity,8)<=0,409);
  }
 }
 private function lockFinancialSource(array$p,IntegrationOrganizationMapping$mapping):void {
  $db=DB::connection('tenant');$bill=$db->table('bills')->where('organization_id',$mapping->finance_organization_id)->where('id',$p['source_bill_id'])->whereNull('deleted_at')->lockForUpdate()->first();abort_unless($bill&&(int)$bill->journal_entry_id===(int)$p['bill_journal_id'],409);
  abort_unless($db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$p['bill_journal_id'])->where('source','AP')->where('source_type','App\\Models\\Bill')->where('source_id',$p['source_bill_id'])->where('status','posted')->whereNotNull('posted_at')->whereNull('voided_at')->whereNull('deleted_at')->exists(),409);
  $intent=$db->table('finance_supplier_return_requests')->where('organization_id',$mapping->finance_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('operation_uuid',$p['operation_uuid'])->lockForUpdate()->first();abort_unless($intent&&(int)$intent->bill_id===(int)$bill->id&&(int)$intent->bill_journal_id===(int)$p['bill_journal_id']&&hash_equals($intent->payload_hash,hash('sha256',$intent->payload))&&json_decode($intent->payload,true,512,JSON_THROW_ON_ERROR)===$p,409);
  $currentMapping=IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->first();abort_unless($currentMapping&&$currentMapping->status==='verified'&&$currentMapping->activation_state==='active'&&$currentMapping->mapping_uuid===$mapping->mapping_uuid,409);
 }
 private function activeWarehouses(GoodsReceipt$r):bool {
  $ids=array_values(array_unique(array_merge([(int)$r->warehouse_id],$r->lines->map(fn($l)=>(int)($l->warehouse_id?:$r->warehouse_id))->all())));
  return !in_array(0,$ids,true)&&DB::connection('tenant')->table('warehouses')->where('organization_id',$r->organization_id)->whereIn('id',$ids)->where('is_active',true)->whereNull('deleted_at')->count()===count($ids);
 }
 private function assertActiveWarehouses(GoodsReceipt$r):void{abort_unless($this->activeWarehouses($r),409);}
 private function result(object$r):array{return ['operation_uuid'=>$r->operation_uuid,'request_id'=>(int)$r->id,'return_id'=>$r->supplier_return_id?(int)$r->supplier_return_id:null,'state'=>$r->state];}
}
