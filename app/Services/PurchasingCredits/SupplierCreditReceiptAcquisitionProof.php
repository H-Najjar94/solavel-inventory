<?php
namespace App\Services\PurchasingCredits;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\{DB,Schema};
/** Independently verifies immutable Finance credit claims; never modifies posted commercial lines. */
final class SupplierCreditReceiptAcquisitionProof
{
 public function verify(array $facts,array $authority,int $financeOrg,string $mappingUuid,object $bill):?array
 {
  $proof=$facts['adjusted_acquisition']??null;
  if($proof===null){abort_unless(!isset($authority['adjusted_acquisition']),403);return null;}
  abort_unless(is_array($proof)&&($authority['adjusted_acquisition']??null)===$proof
   &&($proof['version']??null)==='purchase-credit-receipt.v1',403);
  abort_unless(!array_diff(array_keys($proof),['version','position_uuid','bill_revision','settlement_uuid','quantity','original_net_unit_cost','original_nonrecoverable_tax_unit_cost','adjusted_net_unit_cost','adjusted_nonrecoverable_tax_unit_cost','invoice_exchange_rate','allocation_claims','revision_hash']),403);
  $hash=$proof['revision_hash']??'';$unsigned=$proof;unset($unsigned['revision_hash']);
  abort_unless(is_string($hash)&&strlen($hash)===64&&hash_equals($hash,hash('sha256',json_encode($unsigned,JSON_THROW_ON_ERROR))),403);
  foreach(['settlement_uuid','position_uuid','bill_revision']as$field)abort_unless(($proof[$field]??null)===($facts[$field]??null),403);
  foreach(['quantity','original_net_unit_cost','original_nonrecoverable_tax_unit_cost','adjusted_net_unit_cost','adjusted_nonrecoverable_tax_unit_cost','invoice_exchange_rate']as$field)
   abort_unless(isset($proof[$field])&&preg_match('/^\d+(?:\.\d{1,12})?$/D',(string)$proof[$field])===1,403);
  abort_unless(Decimal::cmp($proof['quantity'],$facts['quantity'],8)===0
   &&Decimal::cmp($proof['adjusted_net_unit_cost'],$facts['invoice_net_unit_cost'],8)===0
   &&Decimal::cmp($proof['adjusted_nonrecoverable_tax_unit_cost'],$facts['nonrecoverable_tax_unit_cost'],8)===0,403);
  abort_unless(Schema::connection('tenant')->hasTable('finance_purchase_credit_receipt_claims'),409);
  $db=DB::connection('tenant');abort_unless($db->transactionLevel()>0,409);
  $claims=$proof['allocation_claims']??null;abort_unless(is_array($claims)&&$claims!==[]&&array_is_list($claims),403);
  $noteIds=[];$uuids=[];
  foreach($claims as$claim){abort_unless(is_array($claim)&&is_string($claim['allocation_uuid']??null)&&\Ramsey\Uuid\Uuid::isValid($claim['allocation_uuid'])
   &&(int)($claim['note_id']??0)>0&&!isset($uuids[$claim['allocation_uuid']]),403);$uuids[$claim['allocation_uuid']]=true;$noteIds[(int)$claim['note_id']]=true;}
  $ids=array_keys($noteIds);sort($ids,SORT_NUMERIC);$notes=[];
  foreach($ids as$id){$note=$db->table('debit_notes')->where('organization_id',$financeOrg)->where('id',$id)->lockForUpdate()->first();
   abort_unless($note&&(int)$note->bill_id===(int)$bill->id&&$note->posting_status==='posted'&&in_array($note->status,['unapplied','partially_applied','fully_applied'],true),409);$notes[$id]=$note;}
  $position=$db->table('finance_purchase_positions')->where('organization_id',$financeOrg)->where('organization_mapping_uuid',$mappingUuid)
   ->where('position_uuid',$facts['position_uuid'])->where('bill_id',$bill->id)->where('bill_journal_id',$bill->journal_entry_id)
   ->where('bill_revision',$facts['bill_revision'])->lockForUpdate()->first();abort_unless($position,409);
  $source=json_decode($position->snapshot,true,512,JSON_THROW_ON_ERROR);
  foreach(['original_net_unit_cost'=>'net_unit_cost','original_nonrecoverable_tax_unit_cost'=>'nonrecoverable_tax_unit_cost','invoice_exchange_rate'=>'invoice_exchange_rate']as$key=>$sourceKey)
   abort_unless(isset($source[$sourceKey])&&Decimal::cmp($proof[$key],(string)$source[$sourceKey],12)===0,409);
  $net='0';$tax='0';
  foreach($claims as$claim){
   $allocation=$db->table('finance_purchase_credit_allocations')->where('organization_id',$financeOrg)->where('organization_mapping_uuid',$mappingUuid)
    ->where('allocation_uuid',$claim['allocation_uuid'])->where('position_uuid',$position->position_uuid)->lockForUpdate()->first();
   abort_unless($allocation&&$allocation->state==='posted'&&$allocation->credit_kind==='price_only'
    &&(int)$allocation->debit_note_id===(int)$claim['note_id']&&(int)$allocation->journal_entry_id===(int)$claim['note_journal_id']
    &&hash_equals($allocation->source_hash,(string)$claim['source_hash']),409);
   abort_unless(hash_equals($allocation->source_hash,hash('sha256',$allocation->source_snapshot)),409);
   $captured=json_decode($allocation->source_snapshot,true,512,JSON_THROW_ON_ERROR);
   $basis=Decimal::sub((string)data_get($captured,'source.arithmetic.quantity'),(string)data_get($captured,'source.arithmetic.settled_quantity'),8);
   abort_unless(Decimal::gt($basis,'0')&&Decimal::cmp($basis,(string)$claim['quantity_basis'],8)===0,409);
   $nativeLine=$db->table('debit_note_lines')->where('organization_id',$financeOrg)->where('debit_note_id',$claim['note_id'])->where('id',$allocation->debit_note_line_id)->lockForUpdate()->first();
   abort_unless($nativeLine&&hash_equals($allocation->note_revision,SupplierCreditCommercialRevision::forRows($notes[(int)$claim['note_id']],$nativeLine)),409);
   $journal=$db->table('journal_entries')->where('organization_id',$financeOrg)->where('id',$claim['note_journal_id'])->lockForUpdate()->first();
   abort_unless($journal&&$journal->source==='NOTE'&&$journal->source_type==='App\\Models\\DebitNote'&&(int)$journal->source_id===(int)$claim['note_id']
    &&$journal->status==='posted'&&!empty($journal->posted_at)&&empty($journal->voided_at)&&empty($journal->deleted_at),409);
   $stored=$db->table('finance_purchase_credit_receipt_claims')->where('organization_id',$financeOrg)->where('allocation_uuid',$claim['allocation_uuid'])
    ->where('settlement_uuid',$facts['settlement_uuid'])->lockForUpdate()->sole();
   abort_unless(in_array($stored->state,['reserved','matched'],true)&&(int)$stored->debit_note_id===(int)$claim['note_id']
    &&(int)$stored->note_journal_id===(int)$claim['note_journal_id']&&hash_equals($stored->source_hash,$claim['source_hash'])
    &&hash_equals($stored->snapshot_hash,hash('sha256',$stored->snapshot))
    &&json_decode($stored->snapshot,true,512,JSON_THROW_ON_ERROR)===$claim,409);
   foreach(['net_amount','nonrecoverable_tax_amount','acquisition_base','quantity_basis']as$field)
    abort_unless(isset($claim[$field])&&Decimal::cmp((string)$claim[$field],(string)$stored->$field,8)===0,409);
   abort_unless(hash_equals((string)$stored->revision_hash,$hash)&&Decimal::gt((string)$stored->quantity_basis,'0'),409);
   foreach(['net_amount'=>'unreceived_net_amount','nonrecoverable_tax_amount'=>'unreceived_nonrecoverable_tax','acquisition_base'=>'unreceived_acquisition_base']as$field=>$limit){
    $total=(string)$db->table('finance_purchase_credit_receipt_claims')->where('organization_id',$financeOrg)->where('allocation_uuid',$allocation->allocation_uuid)
     ->where('state','<>','reversed')->sum($field);abort_unless(Decimal::cmp($total,(string)$allocation->$limit,8)<=0,409);
   }
   $net=Decimal::add($net,(string)$claim['net_amount'],12);$tax=Decimal::add($tax,(string)$claim['nonrecoverable_tax_amount'],12);
  }
  // Effective costs are validated against exact prorated reductions, with one native decimal quantum for unit division.
  foreach([[$net,'original_net_unit_cost','adjusted_net_unit_cost'],[$tax,'original_nonrecoverable_tax_unit_cost','adjusted_nonrecoverable_tax_unit_cost']]as[$amount,$original,$adjusted]){
   $expected=Decimal::sub($proof[$original],Decimal::div($amount,$proof['quantity'],12),12);
   abort_unless(Decimal::cmp($expected,$proof[$adjusted],8)===0,409);
  }
  return $proof;
 }
}
