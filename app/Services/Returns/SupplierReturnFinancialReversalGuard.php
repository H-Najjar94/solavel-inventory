<?php
namespace App\Services\Returns;
use App\Models\Tenant\SupplierReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Native shared-tenant proof, ordered like Finance NOTE posting/voiding. No actor inherits Finance access. */
final class SupplierReturnFinancialReversalGuard
{
 public function lockAndAssert(SupplierReturn $return):?object
 {
  $db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
  $maps=$db->table('integration_organization_mappings')->where('solastock_organization_id',$return->organization_id)->where('tenant_database_identity',$db->getDatabaseName());
  if(!(clone$maps)->exists())return null;
  foreach(['finance_supplier_returns','finance_supplier_return_lines','finance_supplier_return_credit_allocations','bills','debit_notes','journal_entries','action_logs','debit_allocations','supplier_refunds','integration_outbox_events','integration_document_lifecycle_mappings','finance_supplier_return_reversals']as$table)if(!$schema->hasTable($table))$this->review();
  if(!$schema->hasColumns('finance_supplier_return_reversals',['id','organization_id','organization_mapping_uuid','supplier_return_id','return_mapping_uuid','stock_return_id','stock_reversal_id','original_event_uuid','original_out_journal_id','inverse_import_journal_id','debit_note_id','voided_note_journal_id','source_key','source_hash','payload','created_at','updated_at']))$this->review();
  $sources=$db->table('finance_supplier_returns as r')->join('integration_organization_mappings as m',function($j){$j->on('m.mapping_uuid','=','r.organization_mapping_uuid')->on('m.finance_organization_id','=','r.organization_id');})
   ->where('m.solastock_organization_id',$return->organization_id)->where('m.tenant_database_identity',$db->getDatabaseName())->where('r.stock_return_id',$return->id)->get(['r.*']);
  if($sources->count()!==1)$this->review();$hint=$sources->sole();
  $mapping=(clone$maps)->where('mapping_uuid',$hint->organization_mapping_uuid)->where('finance_organization_id',$hint->organization_id)->whereIn('status',['verified','verified_hold'])->whereIn('activation_state',['active','maintenance_hold'])->first();
  if(!$mapping||!$hint->bill_id||!$hint->debit_note_id||$hint->bridge_journal_id)$this->review();
  $org=(int)$mapping->finance_organization_id;
  $bill=$db->table('bills')->where('organization_id',$org)->where('id',$hint->bill_id)->lockForUpdate()->first();
  $note=$db->table('debit_notes')->where('organization_id',$org)->where('id',$hint->debit_note_id)->lockForUpdate()->first();
  $source=$db->table('finance_supplier_returns')->where('organization_id',$org)->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('id',$hint->id)->lockForUpdate()->first();
  if(!$bill||!$note||!$source||$source->bill_id!=$bill->id||$source->debit_note_id!=$note->id||$source->bridge_journal_id||$note->bill_id!=$bill->id||$note->status!=='void'||$source->bill_journal_id!=$bill->journal_entry_id)$this->review();
  $payload=json_decode($source->payload,true,512,JSON_THROW_ON_ERROR);$original=$payload['return']??[];
  if(!hash_equals($source->source_hash,hash('sha256',\App\Services\Integration\SolaStockJournalContract::canonicalJson($payload)))||($original['document_uuid']??null)!==$return->return_uuid||(int)($original['receipt_id']??0)!==(int)$return->goods_receipt_id)$this->review();
  foreach(['central_client_id','central_organization_id','finance_organization_id','solastock_organization_id'] as $field){$claim=$field==='solastock_organization_id'?'inventory_organization_id':$field;if((int)($payload['identity'][$claim]??0)!==(int)$mapping->$field)$this->review();}
  if(($payload['identity']['organization_mapping_uuid']??null)!==$mapping->mapping_uuid||!$db->table('integration_document_lifecycle_mappings')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('mapping_uuid',$source->return_mapping_uuid)->where('source_document_type','supplier_return')->where('source_document_id',(string)$return->id)->where('solastock_organization_id',$return->organization_id)->where('finance_organization_id',$org)->exists())$this->review();
  $active=fn($q)=>$q->where('status','posted')->whereNotNull('posted_at')->whereNull('voided_at')->whereNull('deleted_at');
  if(!$active($db->table('journal_entries')->where('organization_id',$org)->where('id',$bill->journal_entry_id)->where('source','AP')->where('source_type','App\\Models\\Bill')->where('source_id',$bill->id))->exists())$this->review();
  $event=$db->table('integration_outbox_events')->where('organization_id',$return->organization_id)->where('event_type','supplier_return.posted')->where('aggregate_id',$return->id)->first();
  if(!$event||($original['journal_idempotency_key']??null)!==$event->idempotency_key)$this->review();
  if(!$db->table('journal_entries')->where('organization_id',$org)->where('id',$source->stock_return_journal_id)->where('source_key','external-api:'.hash('sha256',$event->idempotency_key))->exists())$this->review();
  foreach([$source->receipt_journal_id,$source->stock_return_journal_id] as $journalId) if(!$journalId||!$active($db->table('journal_entries')->where('organization_id',$org)->where('id',$journalId))->exists())$this->review();
  if($active($db->table('journal_entries')->where('organization_id',$org)->where('source','NOTE')->where('source_type','App\\Models\\DebitNote')->where('source_id',$note->id))->exists())$this->review();
  $void=$db->table('journal_entries')->where('organization_id',$org)->where('id',$note->journal_entry_id)->where('source','NOTE')->where('source_type','App\\Models\\DebitNote')->where('source_id',$note->id)->where('status','voided')->whereNotNull('posted_at')->whereNotNull('voided_at')->where('voided_by','>',0)->where('void_reason','debit-note-void')->whereNull('deleted_at')->lockForUpdate()->first();
  if(!$void||!$db->table('action_logs')->where('controller','ReversalEngine')->where('method','voidDebitNote')->where('user_id',$void->voided_by)->where('data->document_type','App\\Models\\DebitNote')->where('data->document_id',$note->id)->exists())$this->review();
  $allocations=$db->table('finance_supplier_return_credit_allocations')->where('organization_id',$org)->where('debit_note_id',$note->id)->lockForUpdate()->get();
  $lines=$db->table('finance_supplier_return_lines')->where('organization_id',$org)->where('supplier_return_id',$source->id)->get()->keyBy('id');
  if($allocations->isEmpty()||$allocations->count()!==$lines->count()||$allocations->pluck('supplier_return_line_id')->unique()->count()!==$lines->count())$this->review();
  foreach($allocations as$a)if($a->state!=='voided'||$a->branch!=='matched_physical'||$a->supplier_return_id!=$source->id||$a->bill_id!=$bill->id||$a->bill_journal_id!=$source->bill_journal_id||$a->journal_entry_id!=$void->id||!$lines->has($a->supplier_return_line_id)||bccomp((string)$a->quantity,(string)$lines[$a->supplier_return_line_id]->entered_quantity,8)!==0||$a->debit_note_line_id!=$lines[$a->supplier_return_line_id]->debit_note_line_id||bccomp((string)$a->actual_out_base,(string)$lines[$a->supplier_return_line_id]->actual_out_base,6)!==0)$this->review();
  if($db->table('debit_allocations')->where('organization_id',$org)->where('debit_note_id',$note->id)->lockForUpdate()->exists()||$db->table('supplier_refunds')->where('organization_id',$org)->where('debit_note_id',$note->id)->whereNotIn('status',['draft','void','cancelled'])->lockForUpdate()->exists())$this->review();
  return $mapping;
 }
 /** Exact read-only scope check; no Mapping row lock is acquired beneath native source locks. */
 public function assertMappingCurrent(object $mapping,SupplierReturn $return):void
 {
  $db=DB::connection('tenant');
  if(!$db->table('integration_organization_mappings')->where('id',$mapping->id)->where('mapping_uuid',$mapping->mapping_uuid)->where('solastock_organization_id',$return->organization_id)->where('finance_organization_id',$mapping->finance_organization_id)->where('central_client_id',$mapping->central_client_id)->where('central_organization_id',$mapping->central_organization_id)->where('tenant_database_identity',$db->getDatabaseName())->whereIn('status',['verified','verified_hold'])->whereIn('activation_state',['active','maintenance_hold'])->exists())$this->review();
 }
 private function review():never {throw ValidationException::withMessages(['integration'=>__('return_reversal.supplier_credit_before_return')]);}
}
