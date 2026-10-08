<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
/** Private positive value restoration of an actual immutable late-receipt credit claim. */
final readonly class SupplierCreditReceiptRestoreAuthority
{
 private function __construct(private array $proof,private object $claim,private object $allocation,private array $sources,private int $org,private string $map){}
 public static function fromLocked(array $identity,array $proof,IntegrationOrganizationMapping $map,string $action,int $actor):self
 {
  $db=DB::connection('tenant');$org=(int)$map->solastock_organization_id;$finance=(int)$map->finance_organization_id;
  abort_unless(app(\App\Tenancy\OrganizationContext::class)->idOrFail()===$org&&$map->tenant_database_identity===$db->getDatabaseName()
   &&$db->transactionLevel()>0&&$actor>0&&in_array($action,['prepare','apply','status','release'],true)
   &&($proof['allowed']??false)===true&&($proof['contract_version']??null)==='purchase-credit-receipt-restore.v1'
   &&($proof['authority_kind']??null)==='posted_supplier_credit_receipt_restore'&&($proof['operation']??null)===$action
   &&(int)($proof['actor_id']??0)===$actor&&(int)($proof['finance_organization_id']??0)===$finance
   &&(int)($proof['central_organization_id']??0)===$org,403);
  foreach($identity as$key=>$value)abort_unless((string)$value===(string)($proof[$key]??''),403);
  abort_unless($identity['organization_mapping_uuid']===$map->mapping_uuid,403);
  $bill=$db->table('bills')->where('organization_id',$finance)->where('id',$identity['source_bill_id'])->lockForUpdate()->first();
  $note=$db->table('debit_notes')->where('organization_id',$finance)->where('id',$identity['debit_note_id'])->lockForUpdate()->first();
  $position=$db->table('finance_purchase_positions')->where('organization_id',$finance)->where('position_uuid',$identity['position_uuid'])->lockForUpdate()->first();
  $allocation=$db->table('finance_purchase_credit_allocations')->where('organization_id',$finance)->where('allocation_uuid',$identity['allocation_uuid'])->lockForUpdate()->first();
  $claim=$db->table('finance_purchase_credit_receipt_claims')->where('organization_id',$finance)->where('allocation_uuid',$identity['allocation_uuid'])
   ->where('settlement_uuid',$identity['settlement_uuid'])->lockForUpdate()->sole();
  abort_unless($bill&&$note&&$position&&$allocation&&(int)$note->bill_id===(int)$bill->id&&(int)$allocation->bill_id===(int)$bill->id
   &&(int)$claim->debit_note_id===(int)$note->id&&(int)$position->bill_id===(int)$bill->id
   &&$allocation->position_uuid===$position->position_uuid&&$claim->position_uuid===$position->position_uuid
   &&$position->organization_mapping_uuid===$map->mapping_uuid&&(int)$bill->journal_entry_id===(int)$identity['bill_journal_id']
   &&(int)$allocation->journal_entry_id===(int)$identity['original_journal_id']&&(int)$claim->note_journal_id===(int)$identity['original_journal_id']
   &&hash_equals($allocation->source_hash,$identity['source_hash'])&&hash_equals($claim->revision_hash,$identity['claim_revision_hash'])
   &&(int)$claim->restore_plan_revision===(int)$identity['plan_revision'],409);
  abort_unless(in_array($claim->state,['matched','restore_quote_pending','restore_quoted','restore_release_pending','restore_pending','restored'],true),409);
  if(in_array($claim->state,['restore_quote_pending','restore_quoted','restore_release_pending'],true))abort_unless(in_array($action,['prepare','status','release'],true)&&empty($claim->restore_journal_id),409);
  if($action==='apply')abort_unless(in_array($claim->state,['restore_pending','restored'],true),409);
  $intent=json_decode($claim->restore_intent??'null',true,512,JSON_THROW_ON_ERROR);
  abort_unless(is_array($intent)&&$intent===($proof['restore_intent']??null)
   &&(int)($intent['central_actor_id']??0)===$actor&&($intent['restore_operation_uuid']??null)===$identity['restore_operation_uuid'],403);
  foreach(['allocation_uuid','settlement_uuid','restore_operation_uuid']as$key)abort_unless(($intent[$key]??null)===$identity[$key],403);
  abort_unless((int)($intent['original_journal_id']??0)===(int)$identity['original_journal_id'],403);
  $nativeLine=$db->table('debit_note_lines')->where('organization_id',$finance)->where('debit_note_id',$note->id)->where('id',$allocation->debit_note_line_id)->lockForUpdate()->first();
  abort_unless($nativeLine&&hash_equals($allocation->note_revision,SupplierCreditCommercialRevision::forRows($note,$nativeLine)),409);
  if($action==='release')abort_unless($claim->state==='restore_release_pending'&&($intent['release_requested']??false)===true,403);
  if($action==='apply')abort_unless(($intent['release_requested']??false)!==true,403);
  $user=$db->table('users')->where('id',$intent['actor_id']??0)->where('central_user_id',$actor)->first();
  abort_unless($user&&$db->table('organization_user')->where('organization_id',$finance)->where('user_id',$user->id)->where('status','active')->exists(),403);
  $locked=IntegrationOrganizationMapping::query()->whereKey($map->id)->lockForUpdate()->firstOrFail();
  abort_unless($locked->status==='verified'&&$locked->activation_state==='active'&&$locked->mapping_uuid===$map->mapping_uuid,409);
  foreach(['claim_snapshot'=>[$claim->snapshot,$claim->snapshot_hash],
   'original_credit_source_snapshot'=>[$allocation->source_snapshot,$allocation->source_hash]]as$field=>[$raw,$hash])
   abort_unless($raw===($proof[$field]??null)&&hash_equals($hash,hash('sha256',$raw)),409);
  abort_unless($position->snapshot===($proof['position_snapshot']??null),409);
  $original=json_decode($position->snapshot,true,512,JSON_THROW_ON_ERROR);$captured=json_decode($allocation->source_snapshot,true,512,JSON_THROW_ON_ERROR);
  abort_unless($allocation->credit_kind==='price_only'&&($original['base_currency_code']??null)===$map->base_currency_code
   &&Decimal::gt((string)($original['invoice_exchange_rate']??'0'),'0'),409);
  $nativeBillJournal=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$bill->journal_entry_id)->lockForUpdate()->first();
  self::active($nativeBillJournal);
  $journal=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$identity['original_journal_id'])->lockForUpdate()->first();
  abort_unless($journal&&$journal->source==='NOTE'&&$journal->source_type==='App\\Models\\DebitNote'&&(int)$journal->source_id===(int)$note->id
   &&(int)$note->journal_entry_id===(int)$journal->id,409);
  $voided=!empty($journal->voided_at)&&$note->status==='void';
  if($action==='apply')abort_unless($voided&&(int)($proof['native_voided_journal_id']??0)===(int)$journal->id&&($proof['finance_reversal_journal_id']??null)===null,409);
  elseif($action==='release')abort_unless(!$voided&&empty($claim->restore_journal_id),409);
  elseif(in_array($claim->state,['restore_quote_pending','restore_quoted','restore_release_pending'],true))abort_unless(!$voided&&$journal->status==='posted'&&$note->posting_status==='posted',409);
  else abort_unless($voided||($journal->status==='posted'&&empty($journal->voided_at)&&$note->posting_status==='posted'),409);
  $settlement=$db->table('finance_purchase_settlements')->where('organization_id',$finance)->where('position_uuid',$position->position_uuid)
   ->where('settlement_uuid',$claim->settlement_uuid)->lockForUpdate()->sole();
  abort_unless($settlement->state==='settled'&&$settlement->payload===($proof['settlement_payload']??null)
   &&hash_equals($settlement->payload_hash,hash('sha256',$settlement->payload)),409);
  $payload=json_decode($settlement->payload,true,512,JSON_THROW_ON_ERROR);
  $savedClaim=json_decode($claim->snapshot,true,512,JSON_THROW_ON_ERROR);
  abort_unless(in_array($savedClaim,(array)data_get($payload,'adjusted_acquisition.allocation_claims'),true)
   &&data_get($payload,'adjusted_acquisition.revision_hash')===$claim->revision_hash,409);
  $source=['settlement_uuid'=>$settlement->settlement_uuid,'receipt_id'=>$settlement->receipt_id,'receipt_line_id'=>$settlement->receipt_line_id,
   'receipt_mapping_uuid'=>$settlement->receipt_mapping_uuid,'receipt_journal_key'=>$settlement->receipt_journal_key,'quantity'=>(string)$claim->quantity,
   'receipt_currency_code'=>$payload['currency_code'],'receipt_exchange_rate'=>$payload['receipt_exchange_rate'],
   'receipt_net_credit_amount'=>(string)$claim->net_amount,'receipt_nonrecoverable_tax_credit_amount'=>(string)$claim->nonrecoverable_tax_amount];
  $scale=(int)data_get($captured,'source.arithmetic.money_scale');
  $sources=SupplierCreditReceiptProvenance::fromSource($source,$position,$map,$org,$scale,(int)$bill->supplier_id,(string)$original['invoice_exchange_rate']);
  // Preserve the original claimed carrying amount, including native allocation rounding.
  $total=Decimal::sub('0',(string)$claim->acquisition_base,8);$left=$total;$last=count($sources)-1;$quantity='0';
  foreach($sources as$part)$quantity=Decimal::add($quantity,$part['quantity_base'],8);
  foreach($sources as$i=>&$part){$part['price_delta_base']=$i===$last?$left:Decimal::mul($total,Decimal::div($part['quantity_base'],$quantity,12),8);$left=Decimal::sub($left,$part['price_delta_base'],8);}unset($part);
  abort_unless(Decimal::isZero($left,8),409);
  $quote=json_decode($claim->restore_quote??'null',true,512,JSON_THROW_ON_ERROR);
  abort_unless($quote===($proof['restore_quote']??null),409);
  if($quote)abort_unless(($quote['plan_fingerprint']??null)===$claim->restore_plan_fingerprint,409);
  if($action==='apply'){
   $je=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$claim->restore_journal_id)->lockForUpdate()->first();self::active($je);
   abort_unless($je->source_key==='credit-receipt-restore:'.$claim->allocation_uuid.':'.$claim->settlement_uuid.':'.$claim->restore_plan_revision
    &&$je->source_type==='App\\Models\\DebitNote'&&(int)$je->source_id===(int)$note->id&&(int)($proof['restore_journal_id']??0)===(int)$je->id,409);
   $lines=$db->table('journal_entry_lines')->where('organization_id',$finance)->where('journal_entry_id',$je->id)->orderBy('line_no')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
   abort_unless($lines===($proof['restore_journal_lines']??null)&&hash_equals((string)($proof['restore_journal_fingerprint']??''),hash('sha256',json_encode([(array)$je,$lines],JSON_THROW_ON_ERROR))),409);
   self::matchingJournal($lines,$quote,$claim,$position,$map,$finance,$proof);
  }
  return new self($proof,$claim,$allocation,$sources,$org,$map->mapping_uuid);
 }
 private static function matchingJournal(array $lines,array $quote,object $claim,object $position,IntegrationOrganizationMapping $map,int $finance,array $proof):void
 {
  $db=DB::connection('tenant');$expected=[];$bindings=[];$sum='0';
  $organization=$db->table('organizations')->where('id',$finance)->first();abort_unless($organization,409);
  $scale=(int)$organization->money_scale;abort_unless($scale>=0&&$scale<=6,409);
  foreach($quote['native_plan']['components']as$part){
   $role=$part['destination_role'];
   if(!isset($bindings[$role])){
    $reference=$db->table('integration_master_data_mappings')->where('organization_mapping_uuid',$map->mapping_uuid)->where('finance_organization_id',$finance)
     ->where('solastock_organization_id',$map->solastock_organization_id)->where('integration_master_data_mappings.entity_type','account_role')->whereIn('integration_master_data_mappings.status',['mapped','verified'])
     ->join('integration_account_mappings as canonical_account','canonical_account.id','=','integration_master_data_mappings.solastock_record_id')
     ->where('canonical_account.organization_id',$map->solastock_organization_id)->where('canonical_account.integration','solabooks')
     ->where('canonical_account.mapping_type',$role)->whereIn('canonical_account.status',['mapped','verified'])->select('canonical_account.solabooks_account_id')->sole();
    $bindings[$role]=(int)$reference->solabooks_account_id;
   }
   $amount=Decimal::round((string)$part['posted_base_amount'],$scale);$sum=Decimal::add($sum,$amount,8);
   $account=$bindings[$role];$expected[$account]=Decimal::add($expected[$account]??'0',$amount,8);
  }
  $carry=Decimal::round((string)$claim->acquisition_base,$scale);$rounding=Decimal::sub($carry,$sum,8);
  $quantum=$scale===0?'0.5':'0.'.str_repeat('0',$scale).'5';
  $bound=Decimal::mul((string)(count($quote['native_plan']['components'])+1),$quantum,8);
  abort_unless(Decimal::cmp(ltrim($rounding,'-'),$bound,8)<=0,409);
  if(!Decimal::isZero($rounding,8)){
   $default=$db->table('org_account_defaults')->where('organization_id',$finance)->lockForUpdate()->sole();
   $account=(int)$default->rounding_account_id;abort_unless($account>0,409);$bindings['rounding']=$account;
   $expected[$account]=Decimal::add($expected[$account]??'0',$rounding,8);
  }
  $bui=(int)$position->billed_unreceived_account_id;$bindings['billed_unreceived']=$bui;
  $expected[$bui]=Decimal::sub($expected[$bui]??'0',$carry,8);
  foreach($bindings as$role=>$account)abort_unless((int)($proof['restore_account_bindings'][$role]??0)===$account
   &&$db->table('accounts')->where('organization_id',$finance)->where('id',$account)->where('is_active',true)->where('is_postable',true)->exists(),409);
  $actual=[];foreach($lines as$line){$account=(int)$line['account_id'];abort_unless(array_key_exists($account,$expected),409);
   $actual[$account]=Decimal::add($actual[$account]??'0',Decimal::sub((string)$line['base_debit'],(string)$line['base_credit'],8),8);}
  foreach($expected as$account=>$amount)abort_unless(Decimal::cmp($actual[$account]??'0',$amount,8)===0,409);
 }
 private static function active(?object $row):void{abort_unless($row&&$row->status==='posted'&&!empty($row->posted_at)&&empty($row->voided_at)&&empty($row->deleted_at),409);}
 public function organizationId():int{return $this->org;}
 public function mappingUuid():string{return $this->map;}
 public function operationUuid():string{return $this->proof['restore_operation_uuid'];}
 public function allocationUuid():string{return $this->claim->allocation_uuid;}
 public function settlementUuid():string{return $this->claim->settlement_uuid;}
 public function noteId():int{return (int)$this->claim->debit_note_id;}
 public function billId():int{return (int)$this->allocation->bill_id;}
 public function billJournalId():int{return (int)$this->allocation->bill_journal_id;}
 public function fingerprint():string{return hash('sha256',$this->allocation->source_hash.'|'.$this->claim->revision_hash.'|'.$this->claim->settlement_uuid);}
 public function sourceAllocations():array{return $this->sources;}
 public function action():string{return $this->proof['operation'];}
 public function reverse():bool{return true;}
 public function direction():string{return 'receipt_restore';}
 public function planRevision():int{return (int)$this->claim->restore_plan_revision;}
 public function planFingerprint():?string{return $this->claim->restore_plan_fingerprint;}
 public function storedQuote():?array{return json_decode($this->claim->restore_quote??'null',true,512,JSON_THROW_ON_ERROR);}
 public function moneyScale():int{return (int)data_get(json_decode($this->allocation->source_snapshot,true),'source.arithmetic.money_scale');}
 public function currencyCode():string{return json_decode($this->proof['position_snapshot'],true)['currency_code'];}
 public function baseCurrencyCode():string{return json_decode($this->proof['position_snapshot'],true)['base_currency_code'];}
 public function exchangeRate():string{return (string)json_decode($this->proof['position_snapshot'],true)['invoice_exchange_rate'];}
 public function financialReverseProven():bool{return $this->action()==='apply'&&(int)($this->proof['native_voided_journal_id']??0)===(int)$this->claim->note_journal_id&&$this->claim->restore_journal_id>0;}
 public function restoreJournalId():?int{return $this->claim->restore_journal_id?(int)$this->claim->restore_journal_id:null;}
 public function identity():array{return array_intersect_key($this->proof,array_flip(['organization_mapping_uuid','allocation_uuid','settlement_uuid','position_uuid','debit_note_id','source_bill_id','bill_journal_id','original_journal_id','source_hash','claim_revision_hash','restore_operation_uuid','plan_revision']));}
 public function holdUuid(int $item,int $warehouse,string $direction='reverse'):string{return Uuid::uuid5(Uuid::NAMESPACE_URL,'supplier-credit-receipt-restore|'.$this->map.'|'.$this->claim->allocation_uuid.'|'.$this->claim->settlement_uuid.'|'.$item.'|'.$warehouse.'|'.$this->planRevision())->toString();}
}
