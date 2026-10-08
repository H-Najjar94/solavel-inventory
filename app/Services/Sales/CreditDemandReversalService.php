<?php
namespace App\Services\Sales;
use App\Models\Tenant\{FulfillmentDemandCommand,FulfillmentRequest,SalesOrder,IntegrationOrganizationMapping};
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\{DB,Validator};
/** Native credit void restores demand metadata only, under independently proved void authority. */
final class CreditDemandReversalService {
 public function dispatch(array $data,int $actor):array {
  Validator::make($data,['source_invoice_id'=>'required|integer|min:1','request_uuid'=>'required|uuid','source_revision'=>'required|string|size:64','credit_note_id'=>'required|integer|min:1','operation_uuid'=>'required|uuid','credit_revision'=>'required|string|size:64','reversal_operation_uuid'=>'required|uuid','closure_permission'=>'required|in:void','original_credit_journal_id'=>'required|integer|min:1','purpose'=>'required|in:reverse_prepare,reverse_commit,reverse_abandon,reverse_status','lines'=>'required|array','lines.*.source_invoice_line_id'=>'required','lines.*.credit_note_line_id'=>'required|integer|min:1','lines.*.unfulfilled_quantity'=>'required|numeric|min:0'])->validate();
  $review=array_intersect_key($data,array_flip(['request_uuid','source_revision','credit_note_id','operation_uuid','credit_revision','purpose','hold_fingerprint','reversal_operation_uuid','closure_permission','original_credit_journal_id']));$review['command']='reduce-demand';
  $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],'credit_notes.void',$review);
  foreach(['command'=>'reduce-demand','purpose'=>$data['purpose'],'request_uuid'=>$data['request_uuid'],'request_revision'=>$data['source_revision'],'operation_uuid'=>$data['operation_uuid'],'credit_note_id'=>$data['credit_note_id'],'credit_revision'=>$data['credit_revision'],'reversal_operation_uuid'=>$data['reversal_operation_uuid'],'closure_permission'=>'void','original_credit_journal_id'=>$data['original_credit_journal_id']]as$key=>$value)abort_unless((string)($proof[$key]??'')===(string)$value,403);
  abort_unless(SolaStockJournalContract::payloadHash($proof['lines']??[])===SolaStockJournalContract::payloadHash($data['lines']),403);
  $mapping=app(ReceivingRequestService::class)->mapping();$org=app(OrganizationContext::class)->idOrFail();
  return DB::connection('tenant')->transaction(function()use($data,$actor,$proof,$mapping,$org){
   $db=DB::connection('tenant');
   $invoice=$db->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$data['source_invoice_id'])->lockForUpdate()->first();abort_unless($invoice,404);
   $credit=$db->table('credit_notes')->where('organization_id',$mapping->finance_organization_id)->where('invoice_id',$invoice->id)->where('id',$data['credit_note_id'])->lockForUpdate()->first();abort_unless($credit,404);
   $intent=$db->table('finance_sales_credit_demands')->where('organization_id',$mapping->finance_organization_id)->where('invoice_id',$invoice->id)->where('credit_note_id',$credit->id)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   abort_unless($intent&&$intent->request_uuid===$data['request_uuid']&&$intent->request_revision===$data['source_revision']&&$intent->credit_revision===$data['credit_revision']&&($intent->reverse_operation_uuid??null)===$data['reversal_operation_uuid']&&($intent->closure_permission??null)==='void',409);
   abort_unless((int)($proof['reverse_actor_id']??0)>0&&(int)$proof['reverse_actor_id']===(int)$intent->reverse_actor_id,403);
   $payload=json_decode($intent->payload,true);abort_unless(SolaStockJournalContract::payloadHash($payload['lines']??[])===SolaStockJournalContract::payloadHash($data['lines']),409);
   $journal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->invoice_journal_id)->where('source_type','App\\Models\\Invoice')->where('source_id',$invoice->id)->lockForUpdate()->first();abort_unless($journal&&$journal->status==='posted'&&empty($journal->voided_at)&&empty($journal->deleted_at)&&(int)$journal->id===(int)($proof['invoice_journal_id']??0),409);
   $original=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$data['original_credit_journal_id'])->where('source','NOTE')->where('source_type','App\\Models\\CreditNote')->where('source_id',$credit->id)->lockForUpdate()->first();abort_unless($original&&(int)$intent->credit_journal_id===(int)$original->id,409);
   $locked=IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();abort_unless($locked->status==='verified'&&$locked->activation_state==='active'&&(int)$locked->solastock_organization_id===$org&&(int)$locked->finance_organization_id===(int)$mapping->finance_organization_id&&$locked->mapping_uuid===$mapping->mapping_uuid,409);
   $r=FulfillmentRequest::query()->where('organization_id',$org)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_invoice_id',$invoice->id)->where('request_uuid',$data['request_uuid'])->lockForUpdate()->firstOrFail();abort_unless($r->source_revision===$data['source_revision']&&$r->source_status==='posted'&&(int)$r->posted_invoice_journal_id===(int)$journal->id,409);
   $scope=CreditDemandScope::fromLockedIntent($r,$intent,$proof);$order=$r->sales_order_id?SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail():null;
   $c=FulfillmentDemandCommand::query()->where('organization_id',$org)->where('fulfillment_request_id',$r->id)->where('operation_uuid',$data['operation_uuid'])->where('credit_note_id',$credit->id)->lockForUpdate()->firstOrFail();
   abort_unless($c->credit_revision===$data['credit_revision']&&(int)$c->credit_journal_id===(int)$original->id&&SolaStockJournalContract::payloadHash($c->payload['lines']??[])===SolaStockJournalContract::payloadHash($data['lines']),409);
   // An acknowledge_cancelled credit never touched physical demand, so its void only gives back the cancelled-quantity
   // acknowledgement; the request stays cancelled and nothing is re-reserved or re-opened.
   abort_unless(CreditDemandService::commandMode($c)!==CreditDemandService::ACKNOWLEDGE||$r->status==='cancelled',409);
   if($data['purpose']==='reverse_prepare'){
    abort_unless(in_array($intent->reversal_state??null,['reverse_preparing','reverse_prepared'],true)&&$original->status==='posted'&&empty($original->voided_at)&&empty($original->deleted_at)&&$credit->status!=='void',409);
    if($c->state==='reverse_prepared'){abort_unless($c->reversal_operation_uuid===$data['reversal_operation_uuid']&&(int)$c->reverse_actor_id===$actor,409);return$this->result($c,$r,$scope);}
    abort_unless($c->state==='committed',409);
    abort_unless(!FulfillmentDemandCommand::query()->where('organization_id',$org)->where('fulfillment_request_id',$r->id)->where('id','!=',$c->id)->whereIn('state',['prepared','reverse_prepared'])->exists(),409);
    $fingerprint=SolaStockJournalContract::payloadHash(['original_hold'=>$c->hold_fingerprint,'reversal_operation_uuid'=>$data['reversal_operation_uuid'],'credit_journal_id'=>$original->id,'closure_permission'=>'void']);
    $c->update(['state'=>'reverse_prepared','reversal_operation_uuid'=>$data['reversal_operation_uuid'],'reverse_actor_id'=>$actor,'reverse_hold_fingerprint'=>$fingerprint]);return$this->result($c,$r,$scope);
   }
   abort_unless($c->reversal_operation_uuid===$data['reversal_operation_uuid']&&(int)$c->reverse_actor_id===$actor,409);
   if($data['purpose']==='reverse_status')return$this->result($c,$r,$scope);
   abort_unless(($data['hold_fingerprint']??null)===$c->reverse_hold_fingerprint&&($proof['hold_fingerprint']??null)===$c->reverse_hold_fingerprint,403);
   if($data['purpose']==='reverse_abandon'){
    abort_unless(in_array($c->state,['reverse_prepared','committed'],true)&&in_array($intent->reversal_state??null,['reverse_abandon_pending','reverse_abandoned'],true)&&$original->status==='posted'&&empty($original->voided_at)&&$credit->status!=='void',409);
    $c->update(['state'=>'committed']);return$this->result($c,$r,$scope);
   }
   if($c->state==='reversed')return$this->result($c,$r,$scope);
   abort_unless($c->state==='reverse_prepared'&&in_array($intent->reversal_state??null,['reverse_commit_pending','reversed'],true)&&$credit->status==='void'&&$original->status==='voided'&&!empty($original->voided_at)&&empty($original->deleted_at),409);
   $snapshot=json_decode($intent->reversal_snapshot??'null',true);abort_unless(is_array($snapshot)&&SolaStockJournalContract::payloadHash($snapshot)===SolaStockJournalContract::payloadHash($proof['reversal_snapshot']??[]),403);
   foreach(['credit_note_id'=>$credit->id,'original_credit_journal_id'=>$original->id,'invoice_journal_id'=>$journal->id,'void_actor_id'=>$intent->reverse_actor_id,'credit_status'=>'void','journal_status'=>'voided']as$key=>$value)abort_unless((string)($snapshot[$key]??'')===(string)$value,409);
   abort_unless((int)$original->voided_by===(int)$intent->reverse_actor_id&&(int)($proof['reverse_actor_id']??0)===(int)$intent->reverse_actor_id,403);
   $audit=$db->table('action_logs')->where('id',$snapshot['void_audit_id']??0)->where('controller','ReversalEngine')->where('method','voidCreditNote')->where('user_id',$intent->reverse_actor_id)->lockForUpdate()->first();$auditData=$audit?json_decode($audit->data,true):null;abort_unless($audit&&($auditData['document_type']??null)==='App\\Models\\CreditNote'&&(int)($auditData['document_id']??0)===(int)$credit->id,409);
   $this->verifyTaxUndo($db,(int)$mapping->finance_organization_id,(int)$credit->id,$snapshot);
   $r->load('lines');$order?->load('lines');foreach($c->payload['lines']as$line){$source=$r->lines->firstWhere('source_line_id',(string)$line['source_invoice_line_id']);$qty=(string)$line['unfulfilled_quantity'];$stored=Decimal::qty($qty);abort_unless($source&&Decimal::gte((string)$source->cancelled_qty,$stored),409);$source->update(['cancelled_qty'=>Decimal::qty(Decimal::sub((string)$source->cancelled_qty,$stored))]);if($order&&CreditDemandService::commandMode($c)===CreditDemandService::REDUCE){$native=$order->lines->firstWhere('id',$source->sales_order_line_id);$base=Decimal::mul($qty,(string)($native?->unit_conversion_factor?:'1'));abort_unless($native&&Decimal::gte((string)$native->cancelled_qty,$base),409);$native->update(['cancelled_qty'=>Decimal::qty(Decimal::sub((string)$native->cancelled_qty,$base))]);}}
   $c->update(['state'=>'reversed','reversal_snapshot'=>$snapshot]);$r->load('lines');$complete=$r->lines->every(fn($l)=>Decimal::gte(Decimal::add($l->fulfilled_qty,(string)$l->cancelled_qty),$l->requested_qty));$partial=$r->lines->contains(fn($l)=>Decimal::gt($l->fulfilled_qty,'0'));$r->update(['status'=>$r->status==='cancelled'?'cancelled':($complete?'complete':($partial?'partial':'pending'))]);
   if(class_exists(SalesNotificationPublisher::class))app(SalesNotificationPublisher::class)->changed($r);return$this->result($c,$r,$scope);
  },3);
 }
 private function verifyTaxUndo($db,int $org,int $credit,array $snapshot):void {
  if($db->getSchemaBuilder()->hasTable('finance_sales_credit_allocations')){$alloc=$db->table('finance_sales_credit_allocations')->where('organization_id',$org)->where('credit_note_id',$credit)->orderBy('id')->lockForUpdate()->get();$expectedAlloc=array_map('intval',$snapshot['allocation_ids']??[]);sort($expectedAlloc);abort_unless($alloc->pluck('id')->map(fn($id)=>(int)$id)->all()===$expectedAlloc&&$alloc->every(fn($row)=>$row->state==='reversed'&&(int)$row->journal_entry_id===(int)$snapshot['original_credit_journal_id']),409);}else abort_unless(empty($snapshot['allocation_ids']),409);
  if($db->getSchemaBuilder()->hasTable('credit_allocations'))abort_unless(!$db->table('credit_allocations')->where('credit_note_id',$credit)->lockForUpdate()->exists(),409);
  $original=array_map('intval',$snapshot['tax_line_ids']??[]);$offset=array_map('intval',$snapshot['tax_offset_ids']??[]);abort_unless(count(array_unique(array_merge($original,$offset)))===count($original)+count($offset),409);
  if(!$db->getSchemaBuilder()->hasTable('tax_lines')){abort_unless(!$original&&!$offset,409);return;}
  $rows=$db->table('tax_lines')->where('org_id',$org)->where('taxable_type','App\\Models\\CreditNote')->where('taxable_id',$credit)->orderBy('id')->lockForUpdate()->get();$ids=$rows->pluck('id')->map(fn($id)=>(int)$id)->all();$expected=array_merge($original,$offset);sort($expected);abort_unless($ids===$expected,409);
  $a=[];$b=[];foreach($rows as$row){$scope=[$row->tax_id,$row->tax_account_id,$row->tax_date];if(in_array((int)$row->id,$original,true)){$a[]=json_encode(array_merge($scope,[Decimal::add('0',(string)$row->base_amount),Decimal::add('0',(string)$row->tax_amount)]));}else{$b[]=json_encode(array_merge($scope,[Decimal::sub('0',(string)$row->base_amount),Decimal::sub('0',(string)$row->tax_amount)]));}}sort($a);sort($b);abort_unless($a===$b,409);
 }
 private function result(FulfillmentDemandCommand$c,FulfillmentRequest$r,CreditDemandScope$scope):array{return['operation_uuid'=>$c->operation_uuid,'reversal_operation_uuid'=>$c->reversal_operation_uuid,'credit_note_id'=>$c->credit_note_id,'state'=>$c->state,'mode'=>CreditDemandService::commandMode($c),'hold_fingerprint'=>$c->reverse_hold_fingerprint,'fulfillment_request'=>app(FulfillmentRequestService::class)->status($r,$scope)];}
}
