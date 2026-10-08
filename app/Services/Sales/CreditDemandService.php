<?php
namespace App\Services\Sales;
use App\Models\Tenant\{FulfillmentDemandCommand,FulfillmentRequest,SalesOrder};
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\{StockReservationService};
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
/** Two-phase demand metadata only: financial credit belongs to Finance, shipment remains native Stock. */
final class CreditDemandService {
 public function dispatch(array $data,int $actor):array {
  if(in_array($data['purpose']??null,['reverse_prepare','reverse_commit','reverse_abandon','reverse_status'],true))return app(CreditDemandReversalService::class)->dispatch($data,$actor);
  Validator::make($data,['source_invoice_id'=>'required|integer|min:1','request_uuid'=>'required|uuid','source_revision'=>'required|string|size:64','credit_note_id'=>'required|integer|min:1','operation_uuid'=>'required|uuid','credit_revision'=>'required|string|size:64','purpose'=>'required|in:prepare,commit,abandon,status','lines'=>'required|array','lines.*.source_invoice_line_id'=>'required','lines.*.credit_note_line_id'=>'required|integer|min:1','lines.*.unfulfilled_quantity'=>'required|numeric|min:0'])->validate();
  $review=array_intersect_key($data,array_flip(['request_uuid','source_revision','credit_note_id','operation_uuid','credit_revision','purpose','hold_fingerprint']));$review['command']='reduce-demand';
  $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$data['source_invoice_id'],'credit_notes.post',$review);
  foreach(['command'=>'reduce-demand','purpose'=>$data['purpose'],'request_uuid'=>$data['request_uuid'],'request_revision'=>$data['source_revision'],'operation_uuid'=>$data['operation_uuid'],'credit_note_id'=>$data['credit_note_id'],'credit_revision'=>$data['credit_revision']]as$key=>$expected)abort_unless((string)($proof[$key]??'')===(string)$expected,403);
  abort_unless(SolaStockJournalContract::payloadHash($proof['lines']??[])===SolaStockJournalContract::payloadHash($data['lines']),403);
  $mapping=app(ReceivingRequestService::class)->mapping();$org=app(OrganizationContext::class)->idOrFail();$immutable=$data;unset($immutable['purpose'],$immutable['hold_fingerprint']);$hash=SolaStockJournalContract::payloadHash($immutable);
  return DB::connection('tenant')->transaction(function()use($data,$actor,$proof,$mapping,$org,$hash,$immutable){
   $db=DB::connection('tenant');$invoice=$db->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$data['source_invoice_id'])->lockForUpdate()->first();abort_unless($invoice,404);
   $credit=$db->table('credit_notes')->where('organization_id',$mapping->finance_organization_id)->where('id',$data['credit_note_id'])->where('invoice_id',$invoice->id)->lockForUpdate()->first();abort_unless($credit,404);
   $intent=$db->table('finance_sales_credit_demands')->where('organization_id',$mapping->finance_organization_id)->where('invoice_id',$invoice->id)->where('credit_note_id',$credit->id)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   abort_unless($intent&&$intent->request_uuid===$data['request_uuid']&&$intent->request_revision===$data['source_revision']&&$intent->credit_revision===$data['credit_revision'],409);
   $intentPayload=json_decode($intent->payload,true);abort_unless(SolaStockJournalContract::payloadHash($intentPayload['lines']??[])===SolaStockJournalContract::payloadHash($data['lines']),409);
   abort_unless((int)$intent->invoice_journal_id===(int)($proof['invoice_journal_id']??0),409);
   $journal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->invoice_journal_id)->where('source_type','App\\Models\\Invoice')->where('source_id',$invoice->id)->lockForUpdate()->first();abort_unless($journal&&$journal->status==='posted'&&empty($journal->voided_at)&&empty($journal->deleted_at),409);
   $activeCreditJournal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('source','NOTE')->where('source_type','App\\Models\\CreditNote')->where('source_id',$credit->id)->where('status','posted')->whereNull('voided_at')->whereNull('deleted_at')->lockForUpdate()->first();
   if(in_array($data['purpose'],['prepare','abandon'],true))abort_unless(!$activeCreditJournal,409);
   $lockedMapping=\App\Models\Tenant\IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();abort_unless($lockedMapping->status==='verified'&&$lockedMapping->activation_state==='active'&&(int)$lockedMapping->solastock_organization_id===$org&&(int)$lockedMapping->finance_organization_id===(int)$mapping->finance_organization_id&&$lockedMapping->mapping_uuid===$mapping->mapping_uuid,409);
   $r=FulfillmentRequest::query()->where('organization_id',$org)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('request_uuid',$data['request_uuid'])->where('source_invoice_id',$invoice->id)->lockForUpdate()->firstOrFail();abort_unless($r->source_revision===$data['source_revision']&&$r->source_status==='posted'&&(int)$r->posted_invoice_journal_id===(int)$intent->invoice_journal_id,409);
   $scope=CreditDemandScope::fromLockedIntent($r,$intent,$proof);
   $order=$r->sales_order_id?SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($r->sales_order_id)->lockForUpdate()->firstOrFail():null;
   // A cancelled request already released its physical demand (order cancelled, reservations released). A credit for its
   // billed-but-undispatched remainder only ACKNOWLEDGES that cancelled quantity: no reservation release, no re-open, no status change.
   $c=FulfillmentDemandCommand::query()->where('organization_id',$org)->where('operation_uuid',$data['operation_uuid'])->lockForUpdate()->first();
   if($c)abort_unless($c->payload_hash===$hash&&(int)$c->actor_id===$actor&&(int)$c->credit_note_id===(int)$credit->id&&$c->credit_revision===$data['credit_revision'],409);
   // An existing command keeps the mode it was prepared in (a committed reduce credit stays a reduce even if the request is
   // cancelled later); a prepared one must still match the request, which cannot change while the hold fences cancel.
   $mode=$c?self::commandMode($c):$this->mode($r,$order);
   if($c&&$c->state==='prepared')abort_unless($this->mode($r,$order)===$mode,409);
   if($data['purpose']==='status'){abort_unless($c,404);return$this->result($c,$r,$scope);}
   if($data['purpose']==='prepare'){
    if($c){abort_unless($c->state!=='abandoned',409);return$this->result($c,$r,$scope);}
    abort_unless(in_array($intent->state,['preparing','prepared'],true)&&!$intent->credit_journal_id,409);
    abort_unless(!FulfillmentDemandCommand::query()->where('organization_id',$org)->where('credit_note_id',$credit->id)->where('operation_uuid','!=',$data['operation_uuid'])->where('state','!=','abandoned')->exists(),409);
    abort_unless(!FulfillmentDemandCommand::query()->where('organization_id',$org)->where('fulfillment_request_id',$r->id)->where('state','prepared')->exists(),409,__('inventory.sales_handoff.credit_demand_pending'));
    $seen=[];$r->load('lines');foreach($data['lines']as$line){$source=$r->lines->firstWhere('source_line_id',(string)$line['source_invoice_line_id']);abort_unless($source&&!isset($seen[$source->id]),422);$seen[$source->id]=true;$remaining=Decimal::sub(Decimal::sub($source->requested_qty,$source->fulfilled_qty),(string)($source->cancelled_qty??'0'));abort_unless(!Decimal::gt((string)$line['unfulfilled_quantity'],$remaining),409);}
    if($mode===self::REDUCE&&$order&&collect($data['lines'])->contains(fn($line)=>Decimal::gt((string)$line['unfulfilled_quantity'],'0'))){$order->load('lines');$reductions=[];foreach($data['lines']as$line){$source=$r->lines->firstWhere('source_line_id',(string)$line['source_invoice_line_id']);$native=$order->lines->firstWhere('id',$source->sales_order_line_id);abort_unless($native,409);$raw=Decimal::mul((string)$line['unfulfilled_quantity'],(string)($native->unit_conversion_factor?:'1'));if(Decimal::cmp(Decimal::qty($raw),$raw)!==0)throw \Illuminate\Validation\ValidationException::withMessages(['lines'=>__('inventory.sales_handoff.credit_quantity_precision')]);$reductions[$native->id]=$raw;}app(StockReservationService::class)->validateExcessReleaseForSalesOrder($order,$reductions,$scope);}
    $fingerprint=SolaStockJournalContract::payloadHash(['organization_mapping_uuid'=>$mapping->mapping_uuid,'request_id'=>$r->id,'operation_uuid'=>$data['operation_uuid'],'payload_hash'=>$hash]);
    $c=FulfillmentDemandCommand::create(['organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid,'operation_uuid'=>$data['operation_uuid'],'fulfillment_request_id'=>$r->id,'source_invoice_id'=>$invoice->id,'credit_note_id'=>$credit->id,'actor_id'=>$actor,'credit_revision'=>$data['credit_revision'],'payload_hash'=>$hash,'hold_fingerprint'=>$fingerprint,'payload'=>$mode===self::ACKNOWLEDGE?$immutable+['demand_mode'=>self::ACKNOWLEDGE]:$immutable,'state'=>'prepared']);return$this->result($c,$r,$scope);
   }
   abort_unless($c,404);abort_unless(($proof['hold_fingerprint']??null)===$c->hold_fingerprint&&($data['hold_fingerprint']??null)===$c->hold_fingerprint,403);
   if($data['purpose']==='abandon'){
    abort_unless($c->state!=='committed'&&in_array($intent->state,['abandon_pending','abandoned'],true)&&!$intent->credit_journal_id,409);$c->update(['state'=>'abandoned']);return$this->result($c,$r,$scope);
   }
   if($c->state==='committed')return$this->result($c,$r,$scope);abort_unless($c->state==='prepared'&&in_array($intent->state,['commit_pending','committed'],true),409);
   $creditJournal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$intent->credit_journal_id)->where('source','NOTE')->where('source_type','App\\Models\\CreditNote')->where('source_id',$credit->id)->lockForUpdate()->first();abort_unless($creditJournal&&$creditJournal->status==='posted'&&empty($creditJournal->voided_at)&&empty($creditJournal->deleted_at)&&(int)$creditJournal->id===(int)($proof['credit_journal_id']??0),409);
   $r->load('lines');$order?->load('lines');foreach($data['lines']as$line){$source=$r->lines->firstWhere('source_line_id',(string)$line['source_invoice_line_id']);abort_unless($source,409);$qty=(string)$line['unfulfilled_quantity'];$source->update(['cancelled_qty'=>Decimal::qty(Decimal::add((string)($source->cancelled_qty??'0'),$qty))]);/* acknowledge mode: the order is already cancelled, its lines stay untouched */if($order&&$mode===self::REDUCE){$native=$order->lines->firstWhere('id',$source->sales_order_line_id);abort_unless($native,409);$native->update(['cancelled_qty'=>Decimal::qty(Decimal::add((string)($native->cancelled_qty??'0'),Decimal::mul($qty,(string)($native->unit_conversion_factor?:'1'))))]);}}
   if($mode===self::REDUCE&&$order&&collect($data['lines'])->contains(fn($line)=>Decimal::gt((string)$line['unfulfilled_quantity'],'0')))app(StockReservationService::class)->releaseExcessForSalesOrder($order,$scope);
   $c->update(['state'=>'committed','credit_journal_id'=>$creditJournal->id]);$r->load('lines');if($mode===self::REDUCE&&$r->lines->every(fn($l)=>Decimal::gte(Decimal::add($l->fulfilled_qty,(string)$l->cancelled_qty),$l->requested_qty)))$r->update(['status'=>'complete']);
   if(class_exists(SalesNotificationPublisher::class))app(SalesNotificationPublisher::class)->changed($r);return$this->result($c,$r,$scope);
  },3);
 }
 public const REDUCE='reduce';
 public const ACKNOWLEDGE='acknowledge_cancelled';
 /** reduce: a live request (native demand is reduced and excess reservations released). acknowledge_cancelled: Stock
  * already cancelled the request and its order, so the credit only records the cancelled billed quantity. A cancelled
  * request whose order is still live (shipped or open) is never acknowledged. */
 private function mode(FulfillmentRequest $r,?SalesOrder $order):string {
  if($r->status!=='cancelled')return self::REDUCE;
  abort_unless(!$order||$order->status==='cancelled',409,__('inventory.sales_handoff.credit_cancelled_order_live'));
  return self::ACKNOWLEDGE;
 }
 public static function commandMode(FulfillmentDemandCommand $c):string {return(string)(($c->payload['demand_mode']??null)?:self::REDUCE);}
 private function result(FulfillmentDemandCommand $c,FulfillmentRequest $r,CreditDemandScope $scope):array{return['operation_uuid'=>$c->operation_uuid,'credit_note_id'=>$c->credit_note_id,'state'=>$c->state,'mode'=>self::commandMode($c),'hold_fingerprint'=>$c->hold_fingerprint,'fulfillment_request'=>app(FulfillmentRequestService::class)->status($r,$scope)];}
}
