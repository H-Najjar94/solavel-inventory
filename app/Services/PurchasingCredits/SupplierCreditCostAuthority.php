<?php
namespace App\Services\PurchasingCredits;

use App\Models\Tenant\{GoodsReceipt,IntegrationOrganizationMapping,IntegrationDocumentLifecycleMapping,IntegrationMasterDataMapping,IntegrationOutboxEvent};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Private value-only authority; constructed from independently locked native source facts. */
final readonly class SupplierCreditCostAuthority
{
    private function __construct(private array $proof, private object $row, private array $sources, private int $org, private string $map) {}

    public static function fromLockedNativeProvenance(array $identity,array $proof,IntegrationOrganizationMapping $mapping,string $action,int $actor):self
    {
        $db=DB::connection('tenant');$org=app(OrganizationContext::class)->idOrFail();
        // Every lifecycle action is bound to its independently persisted actor and immutable plan.
        $reverse=($identity['direction']??null)==='reverse';
        abort_unless($db->transactionLevel()>0 && $actor>0 && in_array($action,['prepare','apply','status','reverse','release'],true)
            && in_array($identity['direction']??null,['forward','reverse'],true)
            && ($reverse ? $action!=='apply' : $action!=='reverse'),403);
        abort_unless(($proof['allowed']??false)===true && ($proof['contract_version']??null)==='purchase-credit-value.v1'
            && ($proof['authority_kind']??null)==='posted_supplier_credit_value' && ($proof['operation']??null)===$action
            && ($proof['direction']??null)===$identity['direction'] && (int)($proof['actor_id']??0)===$actor,403);
        abort_unless((int)($proof['finance_organization_id']??0)===(int)$mapping->finance_organization_id
            && (int)($proof['central_organization_id']??0)===$org && (int)$mapping->solastock_organization_id===$org
            && (int)$mapping->central_organization_id===$org,403);
        $fields=['operation_uuid','allocation_uuid','organization_mapping_uuid','debit_note_id','debit_note_line_id','note_revision',
            'source_bill_id','bill_line_id','bill_journal_id','bill_revision','position_uuid','source_hash','plan_revision'];
        foreach($fields as$key)abort_unless(isset($identity[$key]) && (string)$identity[$key]===(string)($proof[$key]??''),403);
        abort_unless($identity['organization_mapping_uuid']===$mapping->mapping_uuid,403);
        $finance=(int)$mapping->finance_organization_id;
        $bill=$db->table('bills')->where('organization_id',$finance)->where('id',$identity['source_bill_id'])->lockForUpdate()->first();
        $note=$db->table('debit_notes')->where('organization_id',$finance)->where('id',$identity['debit_note_id'])->lockForUpdate()->first();
        $position=$db->table('finance_purchase_positions')->where('organization_id',$finance)->where('position_uuid',$identity['position_uuid'])->lockForUpdate()->first();
        $row=$db->table('finance_purchase_credit_allocations')->where('organization_id',$finance)->where('allocation_uuid',$identity['allocation_uuid'])->lockForUpdate()->first();
        abort_unless($bill && $note && $position && $row && (int)$note->bill_id===(int)$bill->id && (int)$note->supplier_id===(int)$bill->supplier_id,409);
        foreach($fields as$key){$column=$key==='source_bill_id'?'bill_id':($key==='plan_revision'&&$reverse?'reverse_plan_revision':$key);abort_unless((string)$row->$column===(string)$identity[$key],409);}
        abort_unless($row->credit_kind==='price_only' && Decimal::isZero((string)$row->quantity,8)
            && (int)$row->bill_journal_id===(int)$bill->journal_entry_id && (int)$position->bill_journal_id===(int)$bill->journal_entry_id
            && $position->bill_revision===$row->bill_revision && (int)$position->bill_line_id===(int)$row->bill_line_id
            && $position->organization_mapping_uuid===$mapping->mapping_uuid && !in_array($position->state,['reversed','reversal_pending'],true),409);
        abort_unless(in_array($action,['status','release'],true) || in_array($row->state,$reverse?['posted','reverse_quote_pending','reverse_pending','reversed']:($action==='prepare'?['draft_reserved','quote_pending','quoted']:['quoted','valuation_pending','posted']),true),409);
        $user=$db->table('users')->where('id',$action==='release'?$row->release_actor_id:($reverse?$row->reverse_actor_id:$row->actor_id))->where('central_user_id',$actor)->first();
        abort_unless($user && (int)($action==='release'?$row->release_central_actor_id:($reverse?$row->reverse_central_actor_id:$row->central_actor_id))===$actor && $db->table('organization_user')->where('organization_id',$finance)
            ->where('user_id',$user->id)->where('status','active')->exists(),403);
        $locked=IntegrationOrganizationMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->mapping_uuid===$mapping->mapping_uuid && $locked->status==='verified' && $locked->activation_state==='active'
            && (int)$locked->finance_organization_id===$finance && (int)$locked->central_client_id===(int)$mapping->central_client_id,403);
        self::activeJournal($db->table('journal_entries')->where('organization_id',$finance)->where('id',$bill->journal_entry_id)
            ->where('source_type','App\\Models\\Bill')->where('source_id',$bill->id)->lockForUpdate()->first());
        abort_unless(is_string($proof['source_snapshot']??null) && $row->source_snapshot===$proof['source_snapshot']
            && hash_equals($row->source_hash,hash('sha256',$row->source_snapshot)),409);
        $snapshot=json_decode($row->source_snapshot,true,512,JSON_THROW_ON_ERROR);
        $capturedPosition=$snapshot['source']['position']??[];
        foreach(['organization_id','position_uuid','bill_journal_id','bill_line_id','bill_revision','inventory_item_id','entered_unit_id']as$key)
            abort_unless((string)($capturedPosition[$key]??'')===(string)($position->$key??''),409);
        $originalSource=json_decode($capturedPosition['snapshot']??'null',true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($originalSource) && ($proof['currency_code']??null)===($originalSource['currency_code']??null)
            && ($proof['base_currency_code']??null)===$mapping->base_currency_code
            && ($originalSource['base_currency_code']??null)===$mapping->base_currency_code
            && Decimal::gt((string)($proof['exchange_rate']??'0'),'0')
            && Decimal::cmp((string)$proof['exchange_rate'],(string)($originalSource['invoice_exchange_rate']??'0'),12)===0,409);
        $line=$db->table('debit_note_lines')->where('organization_id',$finance)->where('debit_note_id',$note->id)->where('id',$row->debit_note_line_id)->lockForUpdate()->first();
        abort_unless($line && hash_equals($row->note_revision,SupplierCreditCommercialRevision::forRows($note,$line)),409);
        foreach(($snapshot['source']['bill']??[])as$key=>$value)abort_unless(property_exists($bill,$key) && $bill->$key===$value,409);
        $scale=$snapshot['source']['arithmetic']['money_scale']??null;
        abort_unless(is_int($scale) && $scale>=0 && $scale<=6 && $scale===($proof['finance_money_scale']??null),409);
        $receiptRows=json_decode($row->receipt_scope_snapshot??'null',true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($receiptRows) && $receiptRows && hash_equals((string)$row->receipt_scope_hash,hash('sha256',$row->receipt_scope_snapshot))
            && $receiptRows===($proof['receipt_sources']??null) && $row->receipt_scope_snapshot===($proof['receipt_scope_snapshot']??null),409);
        $sources=[];$seen=[];$net=$tax='0';
        foreach($receiptRows as$source){
            $required=['settlement_uuid','receipt_id','receipt_line_id','receipt_mapping_uuid','receipt_journal_key','quantity','receipt_currency_code','receipt_exchange_rate','receipt_net_credit_amount','receipt_nonrecoverable_tax_credit_amount'];
            abort_unless(is_array($source) && !array_diff($required,array_keys($source)) && !array_diff(array_keys($source),$required),409);
            $settlement=$db->table('finance_purchase_settlements')->where('organization_id',$finance)->where('position_uuid',$row->position_uuid)
                ->where('settlement_uuid',$source['settlement_uuid']??'')->lockForUpdate()->first();
            abort_unless($settlement && $settlement->state==='settled' && !isset($seen[$settlement->settlement_uuid]),409);$seen[$settlement->settlement_uuid]=true;
            $saved=json_decode($settlement->payload,true,512,JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($settlement->payload_hash,hash('sha256',$settlement->payload)),409);
            foreach(['receipt_mapping_uuid','receipt_journal_key','receipt_id','receipt_line_id']as$key)abort_unless((string)($source[$key]??'')===(string)$settlement->$key,409);
            abort_unless(Decimal::cmp((string)$source['quantity'],(string)$settlement->quantity,8)===0
                && Decimal::cmp((string)$source['receipt_exchange_rate'],(string)$saved['receipt_exchange_rate'],12)===0
                && $source['receipt_currency_code']===$saved['currency_code'] && (int)$saved['bill_journal_id']===(int)$bill->journal_entry_id && (int)($saved['source_bill_id']??0)===(int)$bill->id
                && ($saved['position_uuid']??null)===$row->position_uuid && $settlement->organization_mapping_uuid===$mapping->mapping_uuid,409);
            self::activeJournal($db->table('journal_entries')->where('organization_id',$finance)->where('id',$settlement->journal_entry_id)
                ->where('source_key','purchase-settlement:'.$settlement->settlement_uuid)->lockForUpdate()->first());
            $sources=array_merge($sources,self::receipt($source,$position,$mapping,$org,$scale,(int)$bill->supplier_id,(string)$originalSource['invoice_exchange_rate']));
            $net=Decimal::add($net,(string)$source['receipt_net_credit_amount'],8);$tax=Decimal::add($tax,(string)$source['receipt_nonrecoverable_tax_credit_amount'],8);
        }
        abort_unless(Decimal::cmp($net,(string)$row->matched_net_amount,8)===0 && Decimal::cmp($tax,(string)$row->matched_nonrecoverable_tax,8)===0
            && Decimal::gt(Decimal::add($net,$tax,8),'0'),409);
        $forward=json_decode($row->stock_value_quote??'null',true,512,JSON_THROW_ON_ERROR);
        $quote=json_decode(($reverse?$row->reverse_quote:$row->stock_value_quote)??'null',true,512,JSON_THROW_ON_ERROR);
        abort_unless($forward===($proof['forward_quote']??null) && $quote===($proof['quote']??null) && $row->state===($proof['state']??null),409);
        if(is_array($quote)){
            abort_unless(($quote['direction']??null)===$identity['direction'] && ($quote['allocation_uuid']??null)===$row->allocation_uuid
                && ($quote['operation_uuid']??null)===$row->operation_uuid && (int)($quote['plan_revision']??0)===(int)($reverse?$row->reverse_plan_revision:$row->plan_revision)
                && ($quote['plan_fingerprint']??null)===($proof['plan_fingerprint']??null) && is_array($quote['native_plan']??null),409);
            abort_unless(($proof['plan_fingerprint']??null)===($reverse?$row->reverse_plan_fingerprint:$row->plan_fingerprint),409);
        }
        if($action==='release'){
            $release=json_decode($row->release_snapshot??'null',true,512,JSON_THROW_ON_ERROR);
            abort_unless(is_array($quote) && is_array($release) && $release===($proof['release_snapshot']??null)
                && ($release['direction']??null)===$identity['direction']
                && ($release['allocation_uuid']??null)===$row->allocation_uuid && ($release['operation_uuid']??null)===$row->operation_uuid
                && (int)($release['plan_revision']??0)===(int)$identity['plan_revision']
                && ($release['plan_fingerprint']??null)===($quote['plan_fingerprint']??null)
                && (int)($release['actor_id']??0)===(int)$row->release_actor_id
                && (int)($release['central_actor_id']??0)===$actor
                && in_array($release['reason']??null,['cancel','abandon'],true)
                && ($reverse ? empty($row->reversed_at) : empty($row->journal_entry_id)),409);
            if($reverse){
                $original=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$row->journal_entry_id)->lockForUpdate()->first();
                self::activeJournal($original);
                abort_unless($note->posting_status==='posted' && $note->status==='unapplied'
                    && !$db->table('debit_allocations')->where('debit_note_id',$note->id)->lockForUpdate()->exists()
                    && (int)$note->journal_entry_id===(int)$original->id,409);
            }else abort_unless($note->status==='draft',409);
        }elseif($reverse){
            self::nativeInverse($proof,$row,$note,$db,$finance,$action);
        }elseif($action==='apply'||($action==='status'&&$row->journal_entry_id)){
            $journal=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$row->journal_entry_id)
                ->where('source','NOTE')->where('source_type','App\\Models\\DebitNote')->where('source_id',$note->id)
                ->where('source_key','purchase-credit-position:'.$row->operation_uuid)->lockForUpdate()->first();self::activeJournal($journal);
            abort_unless(is_array($quote) && (int)$note->journal_entry_id===(int)$journal->id && (int)($proof['finance_journal_id']??0)===(int)$journal->id,409);
            $journalLines=$db->table('journal_entry_lines')->where('organization_id',$finance)->where('journal_entry_id',$journal->id)->orderBy('line_no')->lockForUpdate()->get()->map(fn($r)=>(array)$r)->all();
            abort_unless($journalLines===($proof['finance_journal_lines']??null) && hash_equals((string)$proof['finance_journal_fingerprint'],hash('sha256',json_encode([(array)$journal,$journalLines],JSON_THROW_ON_ERROR))),409);
        }else abort_unless(!$row->journal_entry_id && $note->status==='draft',409);
        return new self($proof,$row,$sources,$org,$mapping->mapping_uuid);
    }

    private static function receipt(array $source,object $position,IntegrationOrganizationMapping $map,int $org,int $scale,int $supplier,string $invoiceRate):array
    {
        $db=DB::connection('tenant');
        IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('mapping_uuid',$source['receipt_mapping_uuid'])
            ->where('source_document_type','goods_receipt')->where('source_document_id',(string)$source['receipt_id'])->firstOrFail();
        $grn=GoodsReceipt::withoutGlobalScope('warehouse_access')->where('organization_id',$org)->whereKey($source['receipt_id'])->lockForUpdate()->firstOrFail();
        abort_unless($grn->status==='posted' && !$grn->reversal_id,409);
        abort_unless(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('entity_type','supplier')
            ->where('central_client_id',$map->central_client_id)->where('central_organization_id',$org)->where('finance_organization_id',$map->finance_organization_id)
            ->where('solastock_organization_id',$org)->where('solastock_record_id',(string)$grn->supplier_id)->where('solabooks_record_id',(string)$supplier)->where('status','verified')
            ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->exists(),403);
        $line=$grn->lines()->whereKey($source['receipt_line_id'])->lockForUpdate()->firstOrFail();
        foreach(['item'=>[$line->item_id,$position->inventory_item_id],'unit'=>[$line->entered_unit_id,$position->entered_unit_id]]as$type=>$pair)
            abort_unless(IntegrationMasterDataMapping::query()->where('organization_mapping_uuid',$map->mapping_uuid)->where('entity_type',$type)
                ->where('central_client_id',$map->central_client_id)->where('central_organization_id',$org)->where('finance_organization_id',$map->finance_organization_id)
                ->where('solastock_organization_id',$org)->where('solastock_record_id',(string)$pair[0])->where('solabooks_record_id',(string)$pair[1])->where('status','verified')
                ->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived',false)->where('solabooks_archived',false)->exists(),403);
        $event=IntegrationOutboxEvent::query()->where('organization_id',$org)->where('event_type','grn.posted')->where('aggregate_id',$grn->id)
            ->where('idempotency_key',$source['receipt_journal_key'])->firstOrFail();
        abort_unless($source['receipt_currency_code']===data_get($event->payload,'currency.code')
            && Decimal::gt((string)$source['receipt_exchange_rate'],'0')
            && Decimal::cmp((string)$source['receipt_exchange_rate'],(string)data_get($event->payload,'currency.exchange_rate','0'),12)===0,409);
        self::activeJournal($db->table('journal_entries')->where('organization_id',$map->finance_organization_id)
            ->where('source_key','external-api:'.hash('sha256',$source['receipt_journal_key']))->lockForUpdate()->first());
        $ledger=$db->table('stock_ledger')->where('organization_id',$org)->where('source_type',GoodsReceipt::class)->where('source_id',$grn->id)
            ->where('source_line_id',$line->id)->where('direction','in')->orderBy('id')->lockForUpdate()->get();
        abort_unless($ledger->isNotEmpty(),409);$cohort='0';
        foreach($ledger as$movement){
            abort_unless(Decimal::gt((string)$movement->quantity,'0') && (int)$movement->item_id===(int)$line->item_id
                && (int)$movement->warehouse_id===(int)$grn->warehouse_id,409);
            $cohort=Decimal::add($cohort,(string)$movement->quantity,8);
        }
        $factor=(string)$line->unit_conversion_factor;$base=Decimal::mul((string)$source['quantity'],$factor,8);
        abort_unless(Decimal::gt($factor,'0') && !empty($line->unit_conversion_hash) && Decimal::gt($base,'0')
            && Decimal::cmp((string)$line->accepted_qty,$cohort,8)===0
            && Decimal::cmp($base,$cohort,8)<=0,409);
        // A bill may settle a subset of one receipt line. The durable settlement scope supplies that subset;
        // the shared native ledger explains the whole cohort. Reject cumulative claims beyond the physical cohort.
        $claims=$db->table('finance_purchase_settlements')->where('organization_id',$map->finance_organization_id)
            ->where('organization_mapping_uuid',$map->mapping_uuid)->where('receipt_mapping_uuid',$source['receipt_mapping_uuid'])
            ->where('receipt_id',$grn->id)->where('receipt_line_id',$line->id)->where('state','settled')->orderBy('id')->lockForUpdate()->get();
        $claimed='0';foreach($claims as$claim){
            abort_unless(Decimal::gt((string)$claim->quantity,'0'),409);
            $claimed=Decimal::add($claimed,Decimal::mul((string)$claim->quantity,$factor,8),8);
        }
        abort_unless(Decimal::cmp($claimed,$cohort,8)<=0,409);
        foreach(['receipt_net_credit_amount','receipt_nonrecoverable_tax_credit_amount']as$key)abort_unless(preg_match('/^\d+(?:\.\d{1,12})?$/D',(string)$source[$key])===1,409);
        // Credit scope amounts are stored Bill-currency amounts; dated receipt FX authenticates the receipt, not the invoice carrying value.
        $delta=Decimal::sub('0',Decimal::round(Decimal::div(Decimal::add((string)$source['receipt_net_credit_amount'],(string)$source['receipt_nonrecoverable_tax_credit_amount'],12),$invoiceRate,12),$scale),8);
        $result=[];$quantityLeft=$base;$deltaLeft=$delta;$last=$ledger->count()-1;
        foreach($ledger as$index=>$movement){
            // Durable native line cohort is distributed by actual ledger quantity; the final member absorbs only arithmetic residuals.
            $quantity=$index===$last?$quantityLeft:Decimal::mul($base,Decimal::div((string)$movement->quantity,$cohort,12),8);
            $value=$index===$last?$deltaLeft:Decimal::mul($delta,Decimal::div((string)$movement->quantity,$cohort,12),8);
            $quantityLeft=Decimal::sub($quantityLeft,$quantity,8);$deltaLeft=Decimal::sub($deltaLeft,$value,8);
            abort_unless(Decimal::gt($quantity,'0') && Decimal::cmp($quantity,(string)$movement->quantity,8)<=0,409);
            $result[]=['receipt_id'=>(int)$grn->id,'receipt_line_id'=>(int)$line->id,'stock_ledger_id'=>(int)$movement->id,'position_uuid'=>$position->position_uuid,
                'quantity_base'=>$quantity,'price_delta_base'=>$value,'item_id'=>(int)$movement->item_id,'warehouse_id'=>(int)$movement->warehouse_id,
                'variant_id'=>$movement->variant_id?(int)$movement->variant_id:null,'lot_id'=>$movement->lot_id?(int)$movement->lot_id:null,
                'bin_id'=>$movement->bin_id?(int)$movement->bin_id:null];
        }
        abort_unless(Decimal::isZero($quantityLeft,8) && Decimal::isZero($deltaLeft,8),409);
        return $result;
    }

    /** A native void invalidates the original NOTE journal; it never invents a reversing twin. */
    private static function nativeInverse(array $proof,object $row,object $note,object $db,int $finance,string $action):void
    {
        $intent=json_decode($row->reverse_snapshot??'null',true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($intent) && $intent===($proof['reverse_intent']??null)
            && ($intent['allocation_uuid']??null)===$row->allocation_uuid
            && (int)($intent['original_journal_id']??0)===(int)$row->journal_entry_id
            && ($intent['closure_permission']??null)==='void'
            && hash_equals(hash('sha256',json_encode($intent,JSON_THROW_ON_ERROR)),(string)($proof['inverse_intent_hash']??''))
            && (int)($proof['reverse_actor_id']??0)===(int)$row->reverse_actor_id
            && (int)($proof['reverse_central_actor_id']??0)===(int)$row->reverse_central_actor_id
            && !$db->table('debit_allocations')->where('debit_note_id',$note->id)->lockForUpdate()->exists(),409);
        $forward=json_decode($row->stock_value_quote??'null',true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($forward) && ($forward['direction']??null)==='forward'
            && ($forward['operation_uuid']??null)===$row->operation_uuid && ($forward['allocation_uuid']??null)===$row->allocation_uuid
            && (int)($forward['plan_revision']??0)===(int)$row->plan_revision
            && ($forward['plan_fingerprint']??null)===$row->plan_fingerprint && is_array($forward['native_plan']??null),409);
        $journal=$db->table('journal_entries')->where('organization_id',$finance)->where('id',$row->journal_entry_id)
            ->where('source','NOTE')->where('source_type','App\\Models\\DebitNote')->where('source_id',$note->id)
            ->where('source_key','purchase-credit-position:'.$row->operation_uuid)->lockForUpdate()->first();
        abort_unless($journal && !empty($journal->posted_at) && empty($journal->deleted_at)
            && (int)$note->journal_entry_id===(int)$journal->id,409);
        $voided=!empty($journal->voided_at) && $note->status==='void';
        if($action==='reverse')abort_unless($voided && ($intent['native_inverse_mode']??null)==='native_void_no_twin'
            && ($proof['native_inverse_mode']??null)==='native_void_no_twin'
            && (int)($proof['native_voided_journal_id']??0)===(int)$journal->id
            && empty($proof['finance_reversal_journal_id']) && empty($row->reversal_journal_id),409);
        else abort_unless($voided || ($journal->status==='posted' && empty($journal->voided_at)
            && $note->posting_status==='posted' && $note->status==='unapplied'),409);
        if($action==='reverse'){
            $quote=json_decode($row->reverse_quote??'null',true,512,JSON_THROW_ON_ERROR);
            $difference=$quote['native_plan']['classification_difference']??null;
            abort_unless(is_array($difference),409);
            if($difference){
                $classification=$db->table('journal_entries')->where('organization_id',$finance)
                    ->where('id',(int)($proof['inverse_classification_journal_id']??0))->where('source_type','App\\Models\\DebitNote')
                    ->where('source_id',$note->id)->where('source_key','credit-inverse-classification:'.$row->allocation_uuid.':'.$row->reverse_plan_revision)
                    ->lockForUpdate()->first();self::activeJournal($classification);
                $lines=$db->table('journal_entry_lines')->where('organization_id',$finance)->where('journal_entry_id',$classification->id)
                    ->orderBy('line_no')->lockForUpdate()->get()->map(fn($line)=>(array)$line)->all();
                abort_unless($lines===($proof['inverse_classification_journal_lines']??null)
                    && ($proof['inverse_classification_plan_fingerprint']??null)===$row->reverse_plan_fingerprint
                    && hash_equals(hash('sha256',json_encode([(array)$classification,$lines],JSON_THROW_ON_ERROR)),
                        (string)($proof['inverse_classification_journal_fingerprint']??'')),409);
            }else abort_unless(empty($proof['inverse_classification_journal_id']),409);
        }
    }

    private static function activeJournal(?object $row):void {abort_unless($row && $row->status==='posted' && !empty($row->posted_at) && empty($row->voided_at) && empty($row->deleted_at),409);}
    public function organizationId():int{return $this->org;}
    public function identity():array {return array_intersect_key($this->proof,array_flip(['organization_mapping_uuid','operation_uuid','allocation_uuid','debit_note_id','debit_note_line_id','note_revision','source_bill_id','bill_line_id','bill_journal_id','bill_revision','position_uuid','source_hash','plan_revision','direction']));}
    public function storedQuote():?array{return $this->proof['quote']??null;}
    public function mappingUuid():string{return $this->map;}
    public function operationUuid():string{return $this->row->operation_uuid;}
    public function allocationUuid():string{return $this->row->allocation_uuid;}
    public function noteId():int{return (int)$this->row->debit_note_id;}
    public function billId():int{return (int)$this->row->bill_id;}
    public function billJournalId():int{return (int)$this->row->bill_journal_id;}
    public function fingerprint():string{return $this->row->source_hash;}
    public function moneyScale():int{return $this->proof['finance_money_scale'];}
    public function currencyCode():string{return $this->proof['currency_code'];}
    public function baseCurrencyCode():string{return $this->proof['base_currency_code'];}
    public function exchangeRate():string{return (string)$this->proof['exchange_rate'];}
    public function sourceAllocations():array{return $this->sources;}
    public function direction():string{return $this->proof['direction'];}
    public function action():string{return $this->proof['operation'];}
    public function reverse():bool{return $this->direction()==='reverse';}
    public function financialReverseProven():bool{return $this->reverse() && $this->action()==='reverse' && ($this->proof['native_inverse_mode']??null)==='native_void_no_twin' && (int)($this->proof['native_voided_journal_id']??0)===(int)$this->row->journal_entry_id;}
    public function inverseClassificationJournalId():?int{return !empty($this->proof['inverse_classification_journal_id'])?(int)$this->proof['inverse_classification_journal_id']:null;}
    public function financeJournalId():?int{return $this->row->journal_entry_id?(int)$this->row->journal_entry_id:null;}
    public function forwardQuote():?array{return json_decode($this->row->stock_value_quote??'null',true,512,JSON_THROW_ON_ERROR);}
    public function planRevision():int{return (int)($this->reverse()?$this->row->reverse_plan_revision:$this->row->plan_revision);}
    public function planFingerprint():?string{return $this->proof['plan_fingerprint']??null;}
    public function holdUuid(int $item,int $warehouse,string $direction='apply'):string {abort_unless($direction===($this->reverse()?'reverse':'apply'),403);return Uuid::uuid5(Uuid::NAMESPACE_URL,'supplier-credit|'.$this->allocationUuid().'|'.$this->direction().'|'.$item.'|'.$warehouse.'|'.$this->planRevision())->toString();}
}
