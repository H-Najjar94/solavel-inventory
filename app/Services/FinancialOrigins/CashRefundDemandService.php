<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginRequest,SalesOrder};
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\{StockReservationService};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Durable two-phase cash refund demand reduction. Financial payout is never physical return. */
final class CashRefundDemandService {
 public function dispatch(array $data,int $actor):array {
  $data=validator($data,['source_document_type'=>'required|in:sales_receipt','source_document_id'=>'required|integer|min:1','source_journal_id'=>'required|integer|min:1','request_uuid'=>'required|uuid','source_revision'=>'required|string|size:64','operation_uuid'=>'required|uuid','refund_receipt_id'=>'required|integer|min:1','purpose'=>'required|in:prepare,commit,abandon,status,reverse','hold_fingerprint'=>'sometimes|string|size:64'])->validate();
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()===0&&$actor>0,409);
  $review=['request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'cash_demand'=>array_intersect_key($data,array_flip(['operation_uuid','refund_receipt_id','purpose','hold_fingerprint']))];
  $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin($actor,FinancialOrigin::fromPayload($data),'view',$review);
  $facts=$proof['cash_demand']??[];abort_unless(($facts['purpose']??null)===$data['purpose']&&($facts['operation_uuid']??null)===$data['operation_uuid']&&(int)($facts['refund_receipt_id']??0)===(int)$data['refund_receipt_id'],403);
  $mapping=app(ReceivingRequestService::class)->mapping();$dto=OriginRequestPayload::fromArray($proof['canonical_payload']);
  return$db->transaction(function()use($db,$data,$actor,$facts,$proof,$mapping,$dto){
   LockedOriginProof::verify($dto,$mapping,$proof,$actor);
   $r=FinancialOriginRequest::query()->where('organization_id',$mapping->solastock_organization_id)->where('request_uuid',$data['request_uuid'])->where('source_document_type','sales_receipt')->lockForUpdate()->firstOrFail();
   abort_unless($r->source_revision===$data['source_revision']&&(int)$r->source_journal_id===$data['source_journal_id']&&$r->status!=='cancelled',409);
   $intent=$db->table('finance_cash_refund_demands')->where('organization_id',$mapping->finance_organization_id)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   abort_unless($intent&&(int)($data['purpose']==='reverse'?$intent->reverse_actor_id:$intent->actor_id)===$actor&&(int)$intent->refund_receipt_id===(int)$data['refund_receipt_id']&&$intent->request_uuid===$r->request_uuid&&$intent->source_revision===$r->source_revision,409);
   $payload=json_decode($intent->payload,true,512,JSON_THROW_ON_ERROR);$hash=SolaStockJournalContract::payloadHash($payload);
   abort_unless($hash===$intent->payload_hash&&$payload===array_intersect_key($facts,$payload),403);
   $scope=CashDemandScope::admitted($r,$intent,$proof);$org=(int)$r->organization_id;
   $hold=$db->table('stock_cash_refund_demands')->where('organization_id',$org)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   if($hold)abort_unless($hold->payload_hash===$hash&&(int)($data['purpose']==='reverse'?$intent->reverse_actor_id:$hold->actor_id)===$actor&&(int)$hold->refund_receipt_id===(int)$intent->refund_receipt_id,409);
   if($data['purpose']==='status'){abort_unless($hold,404);return$this->result($hold,$r);}
   $order=$r->sales_order_id?SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail():null;
   if($data['purpose']==='prepare'){
    if($hold){abort_unless($hold->state!=='abandoned',409);return$this->result($hold,$r);}
    abort_unless(in_array($intent->state,['preparing','prepared'],true)&&!$intent->refund_journal_id,409);
    abort_if($db->table('stock_cash_refund_demands')->where('organization_id',$org)->where('request_id',$r->id)->where('state','prepared')->exists(),409);
    abort_if(FinancialOriginCommand::query()->where('organization_id',$org)->where('request_uuid',$r->request_uuid)->where('status','pending')->exists(),409);
    $reductions=[];$seen=[];foreach($payload['lines']as$line){
     $source=$r->lines()->where('source_document_line_id',$line['source_document_line_id'])->lockForUpdate()->firstOrFail();abort_if(isset($seen[$source->id]),422);$seen[$source->id]=true;$q=(string)$line['unfulfilled_quantity'];
     abort_unless(Decimal::cmp($q,'0')>0&&Decimal::cmp(Decimal::qty($q),$q)===0&&Decimal::cmp($q,Decimal::sub(Decimal::sub($source->requested_quantity,$source->fulfilled_quantity),$source->cancelled_quantity))<=0,409);
     if($order){$base=Decimal::mul($q,$source->unit_conversion_factor);abort_unless(Decimal::cmp(Decimal::qty($base),$base)===0,409);$reductions[$source->sales_order_line_id]=$base;}
    }
    if($order&&$reductions)app(StockReservationService::class)->validateExcessReleaseForSalesOrder($order,$reductions,$scope);
    $fingerprint=SolaStockJournalContract::payloadHash(['organization_mapping_uuid'=>$mapping->mapping_uuid,'request_id'=>$r->id,'operation_uuid'=>$data['operation_uuid'],'payload_hash'=>$hash]);
    $id=$db->table('stock_cash_refund_demands')->insertGetId(['organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid,'request_id'=>$r->id,'request_uuid'=>$r->request_uuid,'source_document_id'=>$r->source_document_id,'source_journal_id'=>$r->source_journal_id,'source_revision'=>$r->source_revision,'refund_receipt_id'=>$intent->refund_receipt_id,'operation_uuid'=>$data['operation_uuid'],'actor_id'=>$actor,'payload_hash'=>$hash,'hold_fingerprint'=>$fingerprint,'payload'=>SolaStockJournalContract::canonicalJson($payload),'state'=>'prepared','created_at'=>now(),'updated_at'=>now()]);
    return$this->result($db->table('stock_cash_refund_demands')->where('id',$id)->first(),$r);
   }
   abort_unless($hold&&hash_equals($hold->hold_fingerprint,$data['hold_fingerprint']??'')&&hash_equals($hold->hold_fingerprint,(string)($facts['hold_fingerprint']??'')),403);
   if($data['purpose']==='reverse'){
    if($hold->state==='reversed')return$this->result($hold,$r);
    abort_unless(in_array($hold->state,['prepared','committed'],true)&&in_array($intent->state,['reverse_pending','reversed'],true),409);
    $refund=$db->table('refund_receipts')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->refund_receipt_id)->lockForUpdate()->first();
    abort_unless($refund&&$refund->status==='void'&&(int)$refund->journal_entry_id===(int)$intent->refund_journal_id,409);
    $voided=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->refund_journal_id)->where('source_type','App\\Models\\RefundReceipt')->where('source_id',$refund->id)->lockForUpdate()->first();abort_unless($voided&&$voided->status==='voided'&&!empty($voided->voided_at),409);
    if($hold->state==='committed')foreach($payload['lines']as$line){
     $source=$r->lines()->where('source_document_line_id',$line['source_document_line_id'])->lockForUpdate()->firstOrFail();$q=(string)$line['unfulfilled_quantity'];abort_unless(Decimal::cmp($source->cancelled_quantity,$q)>=0,409);
     $source->update(['cancelled_quantity'=>Decimal::qty(Decimal::sub($source->cancelled_quantity,$q))]);
     if($order){$native=$order->lines()->whereKey($source->sales_order_line_id)->lockForUpdate()->firstOrFail();$base=Decimal::mul($q,$source->unit_conversion_factor);abort_unless(Decimal::cmp($native->cancelled_qty??'0',$base)>=0,409);$native->update(['cancelled_qty'=>Decimal::qty(Decimal::sub($native->cancelled_qty,$base))]);}
    }
    $db->table('stock_cash_refund_demands')->where('id',$hold->id)->update(['state'=>'reversed','reverse_actor_id'=>$actor,'refund_journal_id'=>$intent->refund_journal_id,'updated_at'=>now()]);$hold->state='reversed';$hold->refund_journal_id=$intent->refund_journal_id;
    $r->load('lines');$complete=$r->lines->every(fn($l)=>Decimal::cmp(Decimal::add($l->fulfilled_quantity,$l->cancelled_quantity),$l->requested_quantity)>=0);
    $r->update(['status'=>$complete?'complete':($r->lines->contains(fn($l)=>Decimal::cmp($l->fulfilled_quantity,'0')>0)?'partial':'pending')]);
    return$this->result($hold,$r); // No reservation, physical return, COGS or payment is reversed here.
   }
   if($data['purpose']==='abandon'){
    abort_unless($hold->state!=='committed'&&in_array($intent->state,['abandon_pending','abandoned'],true)&&!$intent->refund_journal_id,409);
    $db->table('stock_cash_refund_demands')->where('id',$hold->id)->update(['state'=>'abandoned','updated_at'=>now()]);$hold->state='abandoned';return$this->result($hold,$r);
   }
   if($hold->state==='committed')return$this->result($hold,$r);
   abort_unless($hold->state==='prepared'&&in_array($intent->state,['commit_pending','committed'],true)&&(int)$intent->refund_journal_id>0,409);
   $refund=$db->table('refund_receipts')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->refund_receipt_id)->lockForUpdate()->first();
   abort_unless($refund&&$refund->status==='posted'&&(int)$refund->journal_entry_id===(int)$intent->refund_journal_id,409);
   $je=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->refund_journal_id)->where('source_type','App\\Models\\RefundReceipt')->where('source_id',$refund->id)->lockForUpdate()->first();abort_unless($je&&$je->status==='posted'&&empty($je->voided_at)&&empty($je->deleted_at),409);
   foreach($payload['lines']as$line){$source=$r->lines()->where('source_document_line_id',$line['source_document_line_id'])->lockForUpdate()->firstOrFail();$q=(string)$line['unfulfilled_quantity'];$source->update(['cancelled_quantity'=>Decimal::qty(Decimal::add($source->cancelled_quantity,$q))]);
    if($order){$native=$order->lines()->whereKey($source->sales_order_line_id)->lockForUpdate()->firstOrFail();$native->update(['cancelled_qty'=>Decimal::qty(Decimal::add($native->cancelled_qty??'0',Decimal::mul($q,$source->unit_conversion_factor)))]);}
   }
   if($order&&$payload['lines'])app(StockReservationService::class)->releaseExcessForSalesOrder($order,$scope);
   $db->table('stock_cash_refund_demands')->where('id',$hold->id)->update(['state'=>'committed','refund_journal_id'=>$je->id,'updated_at'=>now()]);$hold->state='committed';$hold->refund_journal_id=$je->id;
   if($r->lines()->get()->every(fn($l)=>Decimal::cmp(Decimal::add($l->fulfilled_quantity,$l->cancelled_quantity),$l->requested_quantity)>=0))$r->update(['status'=>'complete']);return$this->result($hold,$r);
  },3);
 }
 public function assertFullPhysicalReversalUnlocked(FinancialOriginRequest $r):void {
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()>0,409);if($r->source_document_type!=='sales_receipt')return;
  $mapping=\App\Models\Tenant\IntegrationOrganizationMapping::query()->where('mapping_uuid',$r->organization_mapping_uuid)->where('solastock_organization_id',$r->organization_id)->firstOrFail();
  if($db->getSchemaBuilder()->hasColumn('refund_receipts','cash_sales_receipt_id'))abort_if($db->table('refund_receipts')->where('organization_id',$mapping->finance_organization_id)->where('cash_sales_receipt_id',$r->source_document_id)->where('cash_sales_journal_id',$r->source_journal_id)->whereIn('status',['draft','posted'])->lockForUpdate()->exists(),409,'Use the separately confirmed Cash physical return; a financial refund already exists.');
  if($db->getSchemaBuilder()->hasTable('finance_cash_refund_demands'))abort_if($db->table('finance_cash_refund_demands')->where('organization_id',$mapping->finance_organization_id)->where('source_document_id',$r->source_document_id)->where('source_journal_id',$r->source_journal_id)->whereNotIn('state',['abandoned','reversed'])->lockForUpdate()->exists(),409,'Resolve the saved Cash refund operation before reversing the shipment.');
 }
 public function assertDispatchUnlocked(FinancialOriginRequest $r):void {
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()>0,409);
  if($r->source_document_type!=='sales_receipt'||!$db->getSchemaBuilder()->hasTable('stock_cash_refund_demands'))return;
  if($db->table('stock_cash_refund_demands')->where('organization_id',$r->organization_id)->where('request_id',$r->id)->where('state','prepared')->lockForUpdate()->exists())throw \Illuminate\Validation\ValidationException::withMessages(['workflow'=>app()->getLocale()==='ar'?'يوجد استرداد نقدي قيد المراجعة. أكمل مزامنة الاسترداد أو ألغِ مسودته في SolaCount قبل تسليم الكميات المتبقية.':'A cash refund is under review. Complete its synchronization or cancel its draft in SolaCount before dispatching the remaining goods.']);
 }
 private function result(object $hold,FinancialOriginRequest $r):array {return['operation_uuid'=>$hold->operation_uuid,'refund_receipt_id'=>(int)$hold->refund_receipt_id,'state'=>$hold->state,'hold_fingerprint'=>$hold->hold_fingerprint,'refund_journal_id'=>$hold->refund_journal_id,'request'=>app(OriginRequestService::class)->summary($r)];}
}
