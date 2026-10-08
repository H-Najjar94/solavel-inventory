<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\{IntegrationOrganizationMapping,IntegrationPurchaseCostAdjustment,IntegrationPurchaseCostAdjustmentComponent,StockLedger};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
/** Only actual restored late-credit sources can authorize this additive settlement inverse. */
final readonly class SupplierCreditBillClosureAuthority
{
 private function __construct(private array $facts,private array $proof,private array $sources,private array $originals,private int $org,private string $map,private array $position,private array $financial,private array $originalParts,private int $finance){}
 public static function fromLocked(array $facts,array $proof,IntegrationOrganizationMapping $map,string $action,IntegrationPurchaseCostAdjustment $original):self
 {
  $db=DB::connection('tenant');$org=(int)$map->solastock_organization_id;$fin=(int)$map->finance_organization_id;
  abort_unless($db->transactionLevel()>0&&app(\App\Tenancy\OrganizationContext::class)->idOrFail()===$org&&$map->tenant_database_identity===$db->getDatabaseName(),403);
  $closure=$proof['restoration_closure']??null;
  abort_unless(is_array($closure)&&($closure['version']??null)==='purchase-credit-bill-closure.v1'&&in_array($action,['prepare','reverse','status','release'],true),403);
  abort_unless((int)$original->organization_id===$org&&$original->organization_mapping_uuid===$map->mapping_uuid&&$original->state==='applied'
   &&data_get($original->safe_metadata,'purchase_settlement.settlement_uuid')===$facts['settlement_uuid'],409);
  $position=$db->table('finance_purchase_positions')->where('organization_id',$fin)->where('position_uuid',$facts['position_uuid'])->lockForUpdate()->first();
  $settlement=$db->table('finance_purchase_settlements')->where('organization_id',$fin)->where('settlement_uuid',$facts['settlement_uuid'])->lockForUpdate()->first();
  abort_unless($position&&$settlement&&(int)$position->bill_id===(int)$facts['source_bill_id']&&$settlement->position_uuid===$position->position_uuid,409);
  $billJE=$db->table('journal_entries')->where('organization_id',$fin)->where('id',$facts['bill_journal_id'])->lockForUpdate()->first();
  abort_unless($billJE&&$billJE->status==='posted'&&!empty($billJE->posted_at)&&empty($billJE->voided_at)&&empty($billJE->deleted_at),409);
  $snapshot=json_decode($position->snapshot,true,512,JSON_THROW_ON_ERROR);
  $financeOrg=$db->table('organizations')->where('id',$fin)->first();abort_unless($financeOrg,409);$snapshot['money_scale']=(int)$financeOrg->money_scale;
  $rows=$db->table('finance_purchase_credit_receipt_claims')->where('organization_id',$fin)->where('settlement_uuid',$facts['settlement_uuid'])->whereNotNull('restore_journal_id')->orderBy('id')->lockForUpdate()->get();
  abort_unless($rows->count()>0&&$rows->count()===count($closure['claims']??[]),409);
  $financial=[];$originalParts=[];
  $matchJE=$db->table('journal_entries')->where('organization_id',$fin)->where('id',$settlement->journal_entry_id)->lockForUpdate()->first();
  abort_unless($matchJE&&$matchJE->source_key==='purchase-settlement:'.$facts['settlement_uuid']&&$matchJE->status==='posted'&&empty($matchJE->voided_at)&&empty($matchJE->deleted_at),409);
  foreach($db->table('journal_entry_lines')->where('organization_id',$fin)->where('journal_entry_id',$matchJE->id)->lockForUpdate()->get() as $line)
   $financial[(int)$line->account_id]=Decimal::add($financial[(int)$line->account_id]??'0',Decimal::sub((string)$line->base_credit,(string)$line->base_debit,8),8);
  $receiptLine=$db->table('goods_receipt_lines')->where('organization_id',$org)->where('id',$facts['receipt_line_id'])->where('goods_receipt_id',$facts['receipt_id'])->lockForUpdate()->first();
  abort_unless($receiptLine,409);$factor=(string)($receiptLine->unit_conversion_factor?:'1');
  $quantities=[$original->adjustment_uuid=>Decimal::mul((string)$facts['quantity'],$factor,8)];
  $adjustments=[$original];$cohort=[];$quotes=[];
  foreach($rows as$i=>$row){
   $source=$closure['claims'][$i];$intent=json_decode($row->bill_closure_intent??'null',true,512,JSON_THROW_ON_ERROR);
   abort_unless(is_array($intent)&&$intent===($source['bill_closure_intent']??null)&&in_array($row->state,['bill_quote_pending','bill_quoted','bill_reverse_pending','bill_reversed'],true)
    &&(int)$intent['bill_id']===(int)$facts['source_bill_id']&&(int)$intent['bill_journal_id']===(int)$facts['bill_journal_id']
    &&$intent['settlement_uuid']===$facts['settlement_uuid']&&(int)$row->bill_closure_plan_revision===(int)($facts['plan_revision']??1)&&(int)$intent['plan_revision']===(int)$row->bill_closure_plan_revision&&in_array($intent['operation'],['unpost','void'],true),403);
   if($action==='release')abort_unless((bool)($row->bill_closure_release_requested??false)===true&&(bool)($source['bill_closure_release_requested']??false)===true&&empty($row->bill_closure_journal_id),403);
   $user=$db->table('users')->where('id',$intent['actor_id'])->where('central_user_id',$intent['central_actor_id'])->first();
   abort_unless($user&&(int)$intent['central_actor_id']>0&&$db->table('organization_user')->where('organization_id',$fin)->where('user_id',$user->id)->where('status','active')->exists(),403);
   abort_unless((int)$source['claim_id']===(int)$row->id&&$source['claim_snapshot']===$row->snapshot&&$source['claim_snapshot_hash']===$row->snapshot_hash
    &&hash_equals($row->snapshot_hash,hash('sha256',$row->snapshot))&&$source['claim_revision_hash']===$row->revision_hash,409);
   $note=$db->table('debit_notes')->where('organization_id',$fin)->where('id',$row->debit_note_id)->lockForUpdate()->first();
   $noteJE=$db->table('journal_entries')->where('organization_id',$fin)->where('id',$row->note_journal_id)->lockForUpdate()->first();
   abort_unless($note&&$note->status==='void'&&$noteJE&&$noteJE->source==='NOTE'&&$noteJE->source_type==='App\\Models\\DebitNote'&&!empty($noteJE->voided_at)&&(int)$noteJE->source_id===(int)$note->id,409);
   $quote=json_decode($row->restore_quote,true,512,JSON_THROW_ON_ERROR);$ack=json_decode($row->restore_ack,true,512,JSON_THROW_ON_ERROR);
   abort_unless($quote===($source['restore_quote']??null)&&$ack===($source['restore_ack']??null)&&($ack['state']??null)==='restored'
    &&($ack['native_value_adjustment']['state']??null)==='applied'&&($ack['physical_movement_ids']??null)===[],409);
   $native=IntegrationPurchaseCostAdjustment::query()->where('organization_id',$org)->where('organization_mapping_uuid',$map->mapping_uuid)->where('adjustment_uuid',$quote['adjustment_uuid'])->lockForUpdate()->firstOrFail();
   abort_unless($native->destination_document_type==='supplier_credit_receipt_restore'&&$native->state==='applied'
    &&data_get($native->safe_metadata,'supplier_credit.allocation_uuid')===$row->allocation_uuid
    &&data_get($native->safe_metadata,'supplier_credit.settlement_uuid')===$row->settlement_uuid,409);
   $actualComponents=IntegrationPurchaseCostAdjustmentComponent::query()->where('organization_id',$org)->where('adjustment_uuid',$native->adjustment_uuid)->orderBy('id')->lockForUpdate()->get()->map(fn($c)=>[
    'component_id'=>(int)$c->id,'component_uuid'=>$c->component_uuid,'allocation_uuid'=>$c->allocation_uuid,'stock_ledger_id'=>(int)$c->stock_ledger_id,
    'destination_role'=>$c->destination_role,'destination_source_type'=>$c->destination_source_type,'destination_source_id'=>(int)$c->destination_source_id,'posted_base_amount'=>(string)$c->posted_base_amount])->all();
   abort_unless($actualComponents===($ack['native_value_adjustment']['components']??null),409);
   $effect=$db->table('supplier_credit_value_effects')->where('organization_id',$org)->where('organization_mapping_uuid',$map->mapping_uuid)->where('adjustment_uuid',$native->adjustment_uuid)->where('direction','restore')->lockForUpdate()->sole();
   abort_unless((array)$effect===($source['restore_effect']??null)&&hash_equals($effect->snapshot_hash,hash('sha256',$effect->snapshot))&&$effect->plan_fingerprint===$quote['plan_fingerprint'],409);
   $je=$db->table('journal_entries')->where('organization_id',$fin)->where('id',$row->restore_journal_id)->lockForUpdate()->first();
   abort_unless($je&&$je->source==='NOTE'&&$je->source_type==='App\\Models\\DebitNote'&&$je->source_key==='credit-receipt-restore:'.$row->allocation_uuid.':'.$row->settlement_uuid.':'.$row->restore_plan_revision&&$je->status==='posted'&&!empty($je->posted_at)&&empty($je->voided_at)&&empty($je->deleted_at)&&(int)$je->source_id===(int)$note->id,409);
   $lines=$db->table('journal_entry_lines')->where('organization_id',$fin)->where('journal_entry_id',$je->id)->orderBy('id')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
   abort_unless((array)$je===($source['restore_journal']??null)&&$lines===($source['restore_journal_lines']??null)
    &&hash_equals($source['restore_journal_fingerprint'],hash('sha256',json_encode(['journal'=>(array)$je,'lines'=>$lines],JSON_THROW_ON_ERROR))),409);
   foreach($lines as$line)$financial[(int)$line['account_id']]=Decimal::add($financial[(int)$line['account_id']]??'0',Decimal::sub((string)$line['base_credit'],(string)$line['base_debit'],8),8);
   $cohort[]=[(int)$row->id,$row->snapshot_hash,$row->revision_hash,$native->adjustment_uuid,$quote['plan_fingerprint'],(int)$je->id,$intent];
   $adjustments[]=$native;$quantities[$native->adjustment_uuid]=Decimal::mul((string)$row->quantity,$factor,8);
   $q=json_decode($row->bill_closure_quote??'null',true,512,JSON_THROW_ON_ERROR);if($q)$quotes[]=$q;
   if($action==='reverse')abort_unless(!(bool)($row->bill_closure_release_requested??false)&&($row->state==='bill_reverse_pending'||$row->state==='bill_reversed'),409);
  }
  abort_unless(hash_equals($closure['claim_cohort_hash'],hash('sha256',json_encode($cohort,JSON_THROW_ON_ERROR))),409);
  abort_unless(!$quotes||count($quotes)===$rows->count(),409);
  if($quotes){foreach($quotes as$q)abort_unless($q===$quotes[0],409);$proof['closure_quote']=$quotes[0];}
  if($action==='reverse'){
   $je=$db->table('journal_entries')->where('organization_id',$fin)->where('id',$proof['finance_reversal_journal_id']??0)->lockForUpdate()->first();
   abort_unless($je&&$je->status==='posted'&&!empty($je->posted_at)&&empty($je->voided_at)&&empty($je->deleted_at)
    &&$je->source_key===($proof['finance_reversal_journal_key']??null),409);
   foreach($rows as$row)abort_unless((int)$row->bill_closure_journal_id===(int)$je->id,409);
  }
  $proof['_closure_action']=$action==='reverse'?'apply':$action;
  $sources=[];$ids=[];
  foreach($adjustments as$adjustment){
   $ids[]=$adjustment->adjustment_uuid;
   $parts=IntegrationPurchaseCostAdjustmentComponent::query()->where('organization_id',$org)->where('adjustment_uuid',$adjustment->adjustment_uuid)->orderBy('id')->lockForUpdate()->get();
   $group=[];
   $total='0';foreach($parts as$part){
    $originalParts[]=['destination_role'=>$part->destination_role,'posted_base_amount'=>(string)$part->posted_base_amount];$total=Decimal::add($total,(string)$part->posted_base_amount,8);
    $sourceLedgerId=data_get($part->provenance,'supplier_credit_source.source_stock_ledger_id')
     ??data_get($part->provenance,'average_replay_from_ledger_id');
    if(!$sourceLedgerId&&data_get($part->provenance,'cost_layer_id')){
     $layer=$db->table('cost_layers')->where('organization_id',$org)->where('id',data_get($part->provenance,'cost_layer_id'))->lockForUpdate()->first();
     abort_unless($layer,409);$sourceLedgerId=$layer->source_ledger_id;
    }
    $destination=StockLedger::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($part->stock_ledger_id)->lockForUpdate()->firstOrFail();
    if(!$sourceLedgerId&&$destination->direction==='in')$sourceLedgerId=$destination->id;
    abort_unless($sourceLedgerId,409);
    $ledger=StockLedger::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($sourceLedgerId)->lockForUpdate()->firstOrFail();
    abort_unless($ledger->direction==='in'&&$ledger->source_type==='App\\Models\\Tenant\\GoodsReceipt'&&(int)$ledger->source_id===(int)$facts['receipt_id']&&(int)$ledger->source_line_id===(int)$facts['receipt_line_id'],409);
    abort_unless((int)$ledger->item_id===(int)$part->item_id&&(int)$ledger->warehouse_id===(int)$part->warehouse_id
     &&(int)$destination->item_id===(int)$part->item_id&&(int)$destination->warehouse_id===(int)$part->warehouse_id,409);
    $key=(int)$ledger->id;if(!isset($group[$key]))$group[$key]=['amount'=>'0','ledger'=>$ledger];
    $group[$key]['amount']=Decimal::sub($group[$key]['amount'],(string)$part->posted_base_amount,8);
   }
   abort_unless(Decimal::cmp($total,(string)$adjustment->allocated_base_difference,8)===0,409);
   $basis='0';foreach($group as$g)$basis=Decimal::add($basis,(string)$g['ledger']->quantity,8);
   $remaining=$quantities[$adjustment->adjustment_uuid];$last=array_key_last($group);
   foreach($group as$key=>$g){$l=$g['ledger'];$quantity=$key===$last?$remaining:Decimal::mul($quantities[$adjustment->adjustment_uuid],Decimal::div((string)$l->quantity,$basis,12),8);$remaining=Decimal::sub($remaining,$quantity,8);
    abort_unless(Decimal::gt($quantity,'0')&&Decimal::cmp($quantity,(string)$l->quantity,8)<=0,409);$sources[]=['settlement_uuid'=>$facts['settlement_uuid'],'position_uuid'=>$facts['position_uuid'],
    'receipt_id'=>(int)$l->source_id,'receipt_line_id'=>(int)$l->source_line_id,'stock_ledger_id'=>(int)$l->id,'item_id'=>(int)$l->item_id,'warehouse_id'=>(int)$l->warehouse_id,
    'variant_id'=>$l->variant_id,'lot_id'=>$l->lot_id,'bin_id'=>$l->bin_id,'quantity_base'=>$quantity,'price_delta_base'=>$g['amount'],'source_adjustment_uuid'=>$adjustment->adjustment_uuid];}
  }
  return new self($facts,$proof,$sources,$ids,$org,$map->mapping_uuid,$snapshot,$financial,$originalParts,$fin);
 }
 public function financialInverse(array $plan):array
 {
  $expected=$this->financial;$roles=[];$scale=$this->moneyScale();
  $account=function(string $role)use(&$roles):int{
   if(isset($roles[$role]))return $roles[$role];$db=DB::connection('tenant');
   $master=$db->table('integration_master_data_mappings as m')->join('integration_account_mappings as a','a.id','=','m.solastock_record_id')
    ->where('m.organization_mapping_uuid',$this->map)->where('m.finance_organization_id',$this->finance)->where('m.solastock_organization_id',$this->org)
    ->where('m.entity_type','account_role')->whereIn('m.status',['mapped','verified'])->where('a.organization_id',$this->org)
    ->where('a.integration','solabooks')->where('a.mapping_type',$role)->whereIn('a.status',['mapped','verified'])->select('m.solabooks_record_id')->sole();
   $id=(int)$master->solabooks_record_id;abort_unless($db->table('accounts')->where('organization_id',$this->finance)->where('id',$id)->where('is_active',true)->where('is_postable',true)->exists(),409);return $roles[$role]=$id;
  };
  // Replace only the inverse cost classification in the exact financial source mirrors.
  foreach($this->originalParts as$part){$id=$account($part['destination_role']);$expected[$id]=Decimal::add($expected[$id]??'0',Decimal::round($part['posted_base_amount'],$scale),8);}
  foreach($plan['components']as$part){$id=$account($part['destination_role']);$expected[$id]=Decimal::add($expected[$id]??'0',Decimal::round($part['posted_base_amount'],$scale),8);}
  $sum='0';foreach($expected as$amount)$sum=Decimal::add($sum,$amount,8);
  if(!Decimal::isZero($sum,8)){
   $bound=Decimal::mul((string)max(1,count($this->originalParts)+count($plan['components'])),Decimal::div('0.5',(string)(10**$scale),12),8);abort_unless(Decimal::cmp(ltrim($sum,'-'),$bound,8)<=0,409);
   $default=DB::connection('tenant')->table('org_account_defaults')->where('organization_id',$this->finance)->sole();$id=(int)$default->rounding_account_id;
   abort_unless(DB::connection('tenant')->table('accounts')->where('organization_id',$this->finance)->where('id',$id)->where('is_active',true)->where('is_postable',true)->exists(),409);
   $expected[$id]=Decimal::sub($expected[$id]??'0',$sum,8);
  }
  ksort($expected,SORT_NUMERIC);$rows=[];foreach($expected as$id=>$amount)if(!Decimal::isZero($amount,8))$rows[]=['account_id'=>(int)$id,'base_amount'=>$amount,'currency_code'=>$this->baseCurrencyCode(),'exchange_rate'=>'1'];return $rows;
 }
 public function verifyFinancialInverse(array $plan):void
 {
  abort_unless($this->financialReverseProven(),403);$db=DB::connection('tenant');$actual=[];
  foreach($db->table('journal_entry_lines')->where('organization_id',$this->finance)->where('journal_entry_id',$this->financeJournalId())->lockForUpdate()->get()as$line){
   abort_unless($line->currency_code===$this->baseCurrencyCode()&&Decimal::cmp((string)$line->exchange_rate,'1',12)===0,409);
   $id=(int)$line->account_id;$actual[$id]=Decimal::add($actual[$id]??'0',Decimal::sub((string)$line->base_debit,(string)$line->base_credit,8),8);
  }
  $expected=[];foreach($this->financialInverse($plan)as$row)$expected[$row['account_id']]=$row['base_amount'];
  foreach(array_unique(array_merge(array_keys($actual),array_keys($expected)))as$id)abort_unless(Decimal::cmp($actual[$id]??'0',$expected[$id]??'0',8)===0,409);
 }
 public function organizationId():int{return $this->org;} public function mappingUuid():string{return $this->map;}
 public function operationUuid():string{return Uuid::uuid5(Uuid::NAMESPACE_URL,'credit-bill-closure|'.$this->map.'|'.$this->facts['settlement_uuid'].'|'.$this->planRevision())->toString();}
 public function allocationUuid():string{return $this->facts['settlement_uuid'];} public function settlementUuid():string{return $this->facts['settlement_uuid'];}
 public function noteId():int{return $this->billId();} public function billId():int{return (int)$this->facts['source_bill_id'];} public function billJournalId():int{return (int)$this->facts['bill_journal_id'];}
 public function fingerprint():string{return hash('sha256',$this->proof['restoration_closure']['claim_cohort_hash'].'|'.$this->facts['bill_revision']);}
 public function sourceAllocations():array{return $this->sources;} public function originalAdjustmentUuids():array{return $this->originals;}
 public function action():string{return $this->proof['_closure_action'];} public function reverse():bool{return false;} public function direction():string{return 'bill_closure';}
 public function planRevision():int{return (int)($this->facts['plan_revision']??1);} public function planFingerprint():?string{return $this->facts['plan_fingerprint']??null;}
 public function storedQuote():?array{return $this->proof['closure_quote']??null;} public function forwardQuote():?array{return $this->storedQuote();}
 public function moneyScale():int{return (int)$this->position['money_scale'];} public function currencyCode():string{return $this->position['currency_code'];}
 public function baseCurrencyCode():string{return $this->position['base_currency_code'];} public function exchangeRate():string{return (string)$this->position['invoice_exchange_rate'];}
 public function financialReverseProven():bool{return $this->action()==='apply'&&!empty($this->proof['finance_reversal_journal_id']);}
 public function financeJournalId():?int{return $this->financialReverseProven()?(int)$this->proof['finance_reversal_journal_id']:null;}
 public function identity():array{return ['organization_mapping_uuid'=>$this->map,'operation_uuid'=>$this->operationUuid(),'allocation_uuid'=>$this->allocationUuid(),'settlement_uuid'=>$this->settlementUuid(),'source_bill_id'=>$this->billId(),'bill_journal_id'=>$this->billJournalId(),'claim_cohort_hash'=>$this->proof['restoration_closure']['claim_cohort_hash'],'plan_revision'=>$this->planRevision()];}
 public function holdUuid(int $item,int $warehouse,string $direction='apply'):string{return Uuid::uuid5(Uuid::NAMESPACE_URL,'credit-bill-closure-hold|'.$this->operationUuid().'|'.$item.'|'.$warehouse)->toString();}
}
