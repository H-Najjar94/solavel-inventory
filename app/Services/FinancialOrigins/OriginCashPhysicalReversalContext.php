<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,IntegrationOrganizationMapping};
use App\Services\Integration\SolaStockJournalContract;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
/** Cash source facts are independently locked locally; the remote actor-zero proof grants no warehouse authority. */
final readonly class OriginCashPhysicalReversalContext
{
 private function __construct(private array $facts,private array $proof,private int $org,private int $commandId,private int $actor){}
 public static function fromProof(array $facts,array $proof,int $org,int $commandId):self
 {
  abort_unless(($proof['allowed']??false)===true && ($proof['actor_id']??null)===0
   && ($proof['authority_kind']??null)==='posted_financial_origin_physical_reversal'
   && ($proof['physical_policy']??null)==='cash_receipt_active_source_only' && ($proof['cash_refund_authorized']??null)===false
   && ($proof['source_closure_authorized']??null)===false && $facts['source_document_type']==='sales_receipt' && $facts['physical_document_type']==='shipment',403);
  foreach($facts as $key=>$value)abort_unless(isset($proof[$key]) && (string)$proof[$key]===(string)$value,403);
  $actor=(int)(request()->user()?->getAuthIdentifier()??0);abort_unless($actor>0,403);
  return new self($facts,$proof,$org,$commandId,$actor);
 }
 public function lockAndValidate():FinancialOriginRequest
 {
  $db=DB::connection('tenant');$f=$this->facts;$p=$this->proof;$finance=(int)($p['finance_organization_id']??0);
  abort_unless($db->transactionLevel()>0 && $this->org===app(OrganizationContext::class)->idOrFail()
   && (int)(request()->user()?->getAuthIdentifier()??0)===$this->actor && $finance>0,403);
  $source=$db->table('sales_receipts')->where('organization_id',$finance)->where('id',$f['source_document_id'])->lockForUpdate()->first();
  abort_unless($source && $source->status==='posted',409);
  $intent=$db->table('finance_document_requests')->where('organization_id',$finance)->where('request_uuid',$f['request_uuid'])
   ->where('source_document_type','sales_receipt')->where('source_document_id',$source->id)->where('source_journal_id',$f['source_journal_id'])->lockForUpdate()->first();
  abort_unless($intent && $intent->side==='sales' && $intent->command==='upsert' && $intent->source_revision===$f['source_revision']
   && $intent->organization_mapping_uuid===($p['organization_mapping_uuid']??null),409);
  if($db->getSchemaBuilder()->hasTable('finance_document_physical_operations'))abort_if($db->table('finance_document_physical_operations')
   ->where('organization_id',$finance)->where('request_uuid',$intent->request_uuid)->where('state','pending')->exists(),409);
  $accepted=$db->table('finance_document_physical_events')->where('organization_id',$finance)->where('request_uuid',$intent->request_uuid)
   ->where('event_type','financial-origin.shipment.confirmed')->where('physical_mapping_uuid',$f['physical_mapping_uuid'])
   ->where('physical_document_id',$f['physical_document_id'])->where('state','reviewed')->first();
  abort_unless($accepted,409);$acceptedPayload=json_decode($accepted->payload,true,512,JSON_THROW_ON_ERROR);
   abort_unless(hash_equals($accepted->source_hash,SolaStockJournalContract::payloadHash($acceptedPayload)),409);
  $positions=$db->table('finance_document_positions')->where('organization_id',$finance)->where('request_uuid',$intent->request_uuid)->orderBy('position_uuid')->lockForUpdate()->get()->keyBy('position_uuid');
  $matches=$db->table('finance_document_matches')->where('organization_id',$finance)->where('request_uuid',$intent->request_uuid)
   ->where('physical_mapping_uuid',$f['physical_mapping_uuid'])->where('physical_document_id',$f['physical_document_id'])->orderBy('operation_uuid')->lockForUpdate()->get();
  abort_unless(count(data_get($acceptedPayload,'physical.lines',[]))===$matches->count(),409);
  $journal=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$intent->source_journal_id)
   ->where('source','SR')->where('source_type','App\\Models\\SalesReceipt')->where('source_id',$source->id)->lockForUpdate()->first();
  $this->active($journal);$actual=[];
  foreach($matches as $match){
   $position=$positions->get($match->position_uuid);$snapshot=json_decode($match->snapshot,true,512,JSON_THROW_ON_ERROR);
   abort_unless(SolaStockJournalContract::canonicalJson(data_get($snapshot,'physical'))===SolaStockJournalContract::canonicalJson(data_get($acceptedPayload,'physical')),409);
   abort_unless($position && $position->source_document_type==='sales_receipt' && (int)$position->source_document_id===(int)$source->id && (int)$position->source_journal_id===(int)$journal->id
    && (int)$position->source_document_line_id===(int)$match->source_document_line_id && in_array($match->state,['settled','reversed'],true),409);
   abort_unless($db->table('sales_receipt_lines')->where('sales_receipt_id',$source->id)->where('id',$match->source_document_line_id)->exists(),409);
   foreach(['mapping_uuid'=>'physical_mapping_uuid','id'=>'physical_document_id','journal_key'=>'physical_journal_key','journal_event_uuid'=>'physical_journal_event_uuid','journal_payload_hash'=>'physical_journal_payload_hash'] as $native=>$input)
    abort_unless((string)data_get($snapshot,'physical.'.$native)===(string)$f[$input],409);
   $cost=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$match->physical_journal_id)->where('source','SOLASTOCK')
    ->where('source_type','shipment')->where('source_id',$f['physical_document_id'])->where('source_key','external-api:'.hash('sha256',$f['physical_journal_key']))->first();$this->active($cost);
   if(bccomp((string)$match->booked_base,'0',6)>0){$recognition=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$match->journal_entry_id)
     ->where('source','FINANCIAL-ORIGIN')->where('source_type','App\\Models\\SalesReceipt')->where('source_id',$source->id)->where('source_key','financial-origin-match:'.$match->operation_uuid)->first();$this->active($recognition);}
   else abort_unless(!$match->journal_entry_id,409);
   if($match->state==='settled')abort_unless(!$match->reversal_journal_id,409);
   else {
    abort_unless($match->reverse_state==='committed',409);
    if($match->journal_entry_id){
     $inverse=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$match->reversal_journal_id)
      ->where('source','FINANCIAL-ORIGIN')->where('source_type','App\\Models\\SalesReceipt')->where('source_id',$source->id)
      ->where('source_key','financial-origin-match-reversal:'.$match->operation_uuid)->where('reverses_entry_id',$match->journal_entry_id)->first();$this->active($inverse);
    } else abort_unless(!$match->reversal_journal_id,409);
   }
   $actual[]=['operation_uuid'=>$match->operation_uuid,'position_uuid'=>$match->position_uuid,'physical_line_id'=>(int)$match->physical_line_id,
    'match_state'=>$match->state,'recognition_reversal_journal_id'=>$match->reversal_journal_id?(int)$match->reversal_journal_id:null,
    'recognition_journal_id'=>$match->journal_entry_id?(int)$match->journal_entry_id:null,'quantity'=>(string)$match->quantity,'booked_base'=>(string)$match->booked_base,
    'match_snapshot_hash'=>SolaStockJournalContract::payloadHash($snapshot)];
  }
  abort_unless($actual!==[] && SolaStockJournalContract::canonicalJson($actual)===SolaStockJournalContract::canonicalJson($p['matches']??[]),409);
  $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$intent->organization_mapping_uuid)->where('solastock_organization_id',$this->org)->where('finance_organization_id',$finance)->lockForUpdate()->firstOrFail();
  abort_unless($mapping->status==='verified' && $mapping->activation_state==='active' && $mapping->tenant_database_identity===$db->getDatabaseName()
   && (int)$mapping->central_organization_id===$this->org && (int)($p['central_organization_id']??0)===$this->org,409);
  $request=FinancialOriginRequest::query()->where('organization_id',$this->org)->where('request_uuid',$intent->request_uuid)
   ->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type','sales_receipt')->where('source_document_id',$source->id)->where('source_journal_id',$journal->id)->lockForUpdate()->firstOrFail();
  abort_unless($request->source_revision===$intent->source_revision,409);OriginSourceAdmission::stock($request,$this->actor,'inventory.manage_returns');
  app(\App\Services\Access\WarehouseAccessService::class)->assertAllowed((int)$request->warehouse_id);
  $command=FinancialOriginCommand::query()->where('organization_id',$this->org)->whereKey($this->commandId)->where('request_uuid',$request->request_uuid)->where('shipment_id',$f['physical_document_id'])->lockForUpdate()->firstOrFail();
  abort_unless($command->status==='completed' && $command->source_document_type==='sales_receipt' && (int)$command->source_document_id===(int)$source->id
   && (int)$command->source_journal_id===(int)$journal->id,409);
  $event=FinancialOriginOutbox::query()->where('organization_id',$this->org)->where('operation_uuid',$command->operation_uuid)->where('physical_document_id',$f['physical_document_id'])->where('event_type','financial-origin.shipment.confirmed')->firstOrFail();
  abort_unless($event->payload_hash===SolaStockJournalContract::payloadHash($event->payload),409);
  foreach(['mapping_uuid'=>'physical_mapping_uuid','id'=>'physical_document_id','journal_key'=>'physical_journal_key','journal_event_uuid'=>'physical_journal_event_uuid','journal_payload_hash'=>'physical_journal_payload_hash'] as $native=>$input)
   abort_unless((string)data_get($event->payload,'physical.'.$native)===(string)$f[$input],409);
  $shipment=\App\Models\Tenant\Shipment::query()->where('organization_id',$this->org)->whereKey($f['physical_document_id'])->lockForUpdate()->firstOrFail();
  abort_unless($shipment->status==='posted' && (int)$shipment->warehouse_id===(int)$request->warehouse_id
   && (int)$shipment->sales_order_id===(int)$request->sales_order_id,409);
  $native=$shipment->lines()->get()->keyBy('id');$lines=data_get($event->payload,'physical.lines',[]);
  abort_unless(count($lines)===$native->count(),409);
  foreach($lines as $line){$actual=$native->get((int)$line['physical_line_id']);
   abort_unless($actual && (int)$actual->item_id===(int)$line['stock_item_id'] && (int)$actual->entered_unit_id===(int)$line['stock_unit_id']
    && \App\Services\Stock\Support\Decimal::cmp((string)$actual->quantity,(string)$line['base_quantity'])===0
    && (string)$actual->unit_conversion_hash===(string)$line['unit_conversion_hash'],409);
  }
  return $request;
 }
 private function active(?object $journal):void {abort_unless($journal && $journal->status==='posted' && !empty($journal->posted_at) && empty($journal->voided_at) && empty($journal->deleted_at),409);}
}
