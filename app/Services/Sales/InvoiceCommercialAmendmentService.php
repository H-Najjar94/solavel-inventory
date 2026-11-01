<?php

namespace App\Services\Sales;

use App\Models\Tenant\{FulfillmentCommand,FulfillmentRequest,InventoryAuditLog};
use App\Services\Integration\{SolaBooksOutboxDeliveryService,SolaStockJournalContract};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\{DB,Validator};

/** Versioned commercial acknowledgement. Never posts, invents or edits a physical movement. */
final class InvoiceCommercialAmendmentService
{
    public const KIND='invoice_commercial_amendment.v1';
    public const DEMAND_KIND='invoice_pending_demand_amendment.v2';

    public function dispatch(array $input,int $actor,string $phase): array
    {
        Validator::make($input,['version'=>'required|integer|in:1,2','operation_uuid'=>'required|uuid','source_invoice_id'=>'required|integer|min:1'])->validate();
        $input['version']=(int)$input['version'];
        abort_unless($actor>0 && in_array($phase,['prepare','commit','cancel','status'],true),403);
        if(DB::connection('tenant')->transactionLevel())throw new \LogicException('Commercial amendment authority must be requested outside a Stock transaction.');
        $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeSales($actor,(int)$input['source_invoice_id'],'post',[
            'command'=>'invoice-amendment','purpose'=>$phase,'operation_uuid'=>$input['operation_uuid'],
        ]);
        $proof=$authority['amendment']??[];
        abort_unless(($proof['version']??null)===$input['version'] && ($proof['operation_uuid']??null)===$input['operation_uuid']
            && (int)($proof['invoice_id']??0)===(int)$input['source_invoice_id'] && ($proof['phase']??null)===$phase,403);
        $demand=$input['version']===2;abort_unless(($proof['amendment_mode']??'commercial')===($demand?'pending_demand':'commercial'),403);
        return DB::connection('tenant')->transaction(function()use($input,$actor,$phase,$proof,$demand){
            $mapping=app(ReceivingRequestService::class)->mapping();
            $db=DB::connection('tenant');
            $db->table('organizations')->where('id',$mapping->finance_organization_id)->lockForUpdate()->sole();
            $invoice=$db->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$input['source_invoice_id'])->lockForUpdate()->first();abort_unless($invoice,404);
            $intent=$db->table('finance_sales_events')->where('organization_id',$mapping->finance_organization_id)->where('organization_mapping_uuid',$mapping->mapping_uuid)
                ->where('event_type','sales.invoice_amendment.v1')->where('source_key','invoice-amendment:'.$input['operation_uuid'])->lockForUpdate()->first();
            abort_unless($intent && hash_equals($intent->source_hash,(string)($proof['approved_hash']??'')),409);
            $workflow=json_decode($intent->result,true,512,JSON_THROW_ON_ERROR);
            abort_unless((int)($workflow['invoice_id']??0)===(int)$invoice->id
                && hash_equals($intent->source_hash,hash('sha256',json_encode($workflow['approved']??[],JSON_THROW_ON_ERROR)))
                && $workflow['state']===($proof['state']??null),409);
            $request=FulfillmentRequest::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_invoice_id',$invoice->id)->lockForUpdate()->firstOrFail();
            $scope=InvoiceCommercialAmendmentScope::fromLockedApproval($request,$intent,$proof);
            if($demand)abort_unless(($workflow['approved']['review']['inventory']['stock']['version']??null)===2 && ($workflow['approved']['review']['inventory']['stock']['amendment_mode']??null)==='pending_demand',403);
            $kind=$demand?self::DEMAND_KIND:self::KIND;
            $command=FulfillmentCommand::query()->where('operation_uuid',$input['operation_uuid'])->lockForUpdate()->first();
            if($command)abort_unless((int)$command->fulfillment_request_id===(int)$request->id && (int)$command->actor_id===$actor
                && ($command->payload['kind']??null)===$kind && hash_equals($command->payload_hash,$intent->source_hash),409);
            if($phase==='status')return $command?$this->result($command):['version'=>$input['version'],'operation_uuid'=>$input['operation_uuid'],'status'=>'not_prepared'];
            // Exact immutable receipts remain readable while a later amendment is pending.
            if($command && ($phase==='prepare' || ($phase==='commit' && $command->status==='committed')) && in_array($command->status,['committed','abandoned'],true))return $this->result($command);
            app(PendingInvoiceAmendment::class)->assertAvailable((int)$mapping->finance_organization_id,(int)$invoice->id,$input['operation_uuid']);
            if($phase==='prepare'){
                if($command)return $this->result($command);
                abort_unless(in_array($workflow['state'],['queued','stock_prepared'],true),409);
                abort_if(FulfillmentCommand::query()->where('fulfillment_request_id',$request->id)->where('status','prepared')->lockForUpdate()->exists(),409,__('inventory.sales_handoff.invoice_amendment_pending'));
                $original=(array)($proof['original_request']??[]);$stored=(array)$request->source_payload;
                unset($original['expected_revision'],$stored['expected_revision']);
                abort_unless($original!==[] && $request->source_status==='posted' && $request->source_revision===($original['source_revision']??null)
                    && SolaStockJournalContract::canonicalJson($original)===SolaStockJournalContract::canonicalJson($stored),409);
                if($demand)$this->assertUnacceptedDemand($request,$stored);
                $this->samePhysical($stored,(array)($proof['proposed_request']??[]),$demand);
                $snapshot=$this->physical($request,$scope);
                $command=FulfillmentCommand::create(['organization_id'=>$request->organization_id,'operation_uuid'=>$input['operation_uuid'],
                    'fulfillment_request_id'=>$request->id,'source_invoice_id'=>$invoice->id,'actor_id'=>$actor,'payload_hash'=>$intent->source_hash,
                    'payload'=>['kind'=>$kind,'protocol_version'=>$input['version'],'amendment_mode'=>$demand?'pending_demand':'commercial','original_request'=>$stored,'proposed_request'=>$proof['proposed_request'],'physical'=>$snapshot,
                        'hold_fingerprint'=>hash('sha256',SolaStockJournalContract::canonicalJson($snapshot))],'status'=>'prepared']);
                $this->audit($request,$command,'sales.invoice_amendment_prepared',null,$command->payload);
                return $this->result($command);
            }
            if($phase==='cancel'){
                abort_unless(in_array($workflow['state'],['cancel_requested','cancelled'],true),409);
                if(!$command){
                    $command=FulfillmentCommand::create(['organization_id'=>$request->organization_id,'operation_uuid'=>$input['operation_uuid'],
                        'fulfillment_request_id'=>$request->id,'source_invoice_id'=>$invoice->id,'actor_id'=>$actor,'payload_hash'=>$intent->source_hash,
                        'payload'=>['kind'=>$kind,'protocol_version'=>$input['version']],'status'=>'abandoned']);
                }
                abort_unless(in_array($command->status,['prepared','abandoned'],true),409);
                if($command->status==='prepared'){$command->update(['status'=>'abandoned']);$this->audit($request,$command,'sales.invoice_amendment_cancelled',null,['physical_effects'=>0]);}
                return $this->result($command);
            }
            abort_unless($command && in_array($workflow['state'],['finance_posted','stock_committed','completed'],true),409);
            if($command->status==='committed')return $this->result($command);
            abort_unless($command->status==='prepared' && (int)($workflow['execution']['replacement_journal_id']??0)>0
                && hash_equals((string)$command->payload['hold_fingerprint'],hash('sha256',SolaStockJournalContract::canonicalJson($this->physical($request,$scope)))),409);
            $target=(array)($proof['replacement_request']??[]);
            if($demand)$this->assertUnacceptedDemand($request,$command->payload['original_request']);
            $this->samePhysical($command->payload['original_request'],$target,$demand);
            $this->sameCommercial($command->payload['proposed_request'],$target,$demand);
            abort_unless(($target['source_status']??null)==='posted' && (int)($target['posted_invoice_journal_id']??0)===(int)$workflow['execution']['replacement_journal_id']
                && ($target['request_uuid']??null)===$request->request_uuid && strlen((string)($target['source_revision']??''))===64,409);
            $journal=$db->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)->where('id',$target['posted_invoice_journal_id'])->where('source_type','App\\Models\\Invoice')->where('source_id',$invoice->id)->where('status','posted')->whereNotNull('posted_at')->whereNull('voided_at')->whereNull('deleted_at')->lockForUpdate()->first();abort_unless($journal,409);
            $before=$request->source_payload;
            // The native order is an original commercial/warehouse document. Its
            // quantities, quoted prices and shipments remain historical evidence.
            // Current invoice terms live on the versioned fulfillment request.
            $request->update(['source_revision'=>$target['source_revision'],'source_payload'=>$target,'posted_invoice_journal_id'=>$journal->id,
                'approved_revision'=>$request->approved_at?$target['source_revision']:null]);
            foreach($request->lines()->orderBy('id')->lockForUpdate()->get() as $line){
                $commercial=collect($target['lines'])->first(fn($row)=>(string)$row['source_line_id']===(string)$line->source_line_id);
                $values=['unit_price'=>Decimal::cost((string)$commercial['unit_price'])];if($demand)$values['requested_qty']=Decimal::qty((string)$commercial['quantity']);
                $line->update($values);
            }
            $payload=$command->payload;$payload['receipt']=['request_revision'=>$target['source_revision'],'replacement_journal_id'=>$journal->id,'physical_effects'=>0,'fulfillment'=>app(FulfillmentRequestService::class)->status($request->fresh('lines'),$scope)];
            $command->update(['payload'=>$payload,'status'=>'committed']);
            $this->audit($request,$command,'sales.invoice_amendment_committed',$before,$target);
            return $this->result($command);
        },3);
    }

    private function assertUnacceptedDemand(FulfillmentRequest $request,array $original): void
    {
        abort_unless($request->status==='pending' && !$request->sales_order_id && !$request->warehouse_id && !$request->approved_at
            && empty($original['origin_order']) && empty($original['commercial_source']) && $request->currency_code===$request->base_currency_code,409,__('inventory.sales_handoff.invoice_amendment_physical'));
        foreach($request->lines()->lockForUpdate()->get() as $line)abort_unless(!Decimal::gt((string)$line->fulfilled_qty,'0') && !Decimal::gt((string)($line->cancelled_qty??'0'),'0') && !$line->sales_order_line_id,409);
        if(DB::connection('tenant')->getSchemaBuilder()->hasTable('sales_fulfillment_demand_commands'))abort_if(DB::connection('tenant')->table('sales_fulfillment_demand_commands')->where('organization_id',$request->organization_id)->where('fulfillment_request_id',$request->id)->whereIn('state',['prepared','reverse_prepared'])->lockForUpdate()->exists(),409,__('inventory.sales_handoff.invoice_amendment_pending'));
    }

    private function samePhysical(array $before,array $after,bool $demand=false): void
    {
        foreach(['request_uuid','source_invoice_id','customer_external_id','invoice_date','currency_code','base_currency_code','exchange_rate','exchange_rate_date'] as $field)
            abort_unless(isset($before[$field],$after[$field]) && (string)$before[$field]===(string)$after[$field],409,__('inventory.sales_handoff.invoice_amendment_physical'));
        $a=$this->lines($before);$b=$this->lines($after);abort_unless(array_keys($a)===array_keys($b),409,__('inventory.sales_handoff.invoice_amendment_physical'));
        foreach($a as$id=>$line)foreach(['item_external_id','unit_external_id','quantity'] as $field){
            $same=$field==='quantity'?($demand?is_numeric($b[$id][$field])&&Decimal::gt((string)$b[$id][$field],'0')&&bccomp((string)$b[$id][$field],Decimal::qty((string)$b[$id][$field]),8)===0:bccomp((string)$line[$field],(string)$b[$id][$field],8)===0):(string)$line[$field]===(string)$b[$id][$field];
            abort_unless($same,409,__('inventory.sales_handoff.invoice_amendment_physical'));
        }
        foreach(['origin_order','commercial_source']as$field)abort_unless(SolaStockJournalContract::canonicalJson($before[$field]??[])===SolaStockJournalContract::canonicalJson($after[$field]??[]),409);
    }

    private function sameCommercial(array $proposal,array $actual,bool $demand=false): void
    {
        $a=$this->lines($proposal);$b=$this->lines($actual);abort_unless(array_keys($a)===array_keys($b),409);
        foreach($a as$id=>$line)abort_unless(is_numeric($line['unit_price']??null) && is_numeric($b[$id]['unit_price']??null)
            && bccomp((string)$line['unit_price'],(string)$b[$id]['unit_price'],8)===0 && (!$demand || bccomp((string)$line['quantity'],(string)$b[$id]['quantity'],8)===0),409);
    }

    private function lines(array $payload): array
    {
        $lines=[];
        foreach($payload['lines']??[] as$line){$id=(string)($line['source_line_id']??'');abort_unless($id!==''&&!isset($lines[$id])&&isset($line['item_external_id'],$line['unit_external_id'],$line['quantity']),409);$lines[$id]=$line;}
        abort_unless($lines!==[],409);ksort($lines);return $lines;
    }

    private function physical(FulfillmentRequest $request,InvoiceCommercialAmendmentScope $scope): array
    {
        $orderEvidence=null;
        if($request->sales_order_id){
            // The source invoice is already locked. Native order mutations acquire
            // that same barrier before order locks; read the warehouse evidence here.
            $scope->assertOrder((int)$request->organization_id,(int)$request->sales_order_id);
            $order=\App\Models\Tenant\SalesOrder::withoutGlobalScope('warehouse_access')->where('organization_id',$request->organization_id)->whereKey($request->sales_order_id)->first();
            abort_unless($order && $order->status!=='cancelled' && (int)$order->warehouse_id===(int)$request->warehouse_id
                && (int)$order->customer_id===(int)$request->customer_id,409,__('inventory.sales_handoff.invoice_amendment_physical'));
            $shipmentIds=\App\Models\Tenant\Shipment::withoutGlobalScope('warehouse_access')->where('organization_id',$request->organization_id)->where('sales_order_id',$order->id)->select('id');
            abort_if(\App\Models\Tenant\SalesReturn::query()->where('organization_id',$request->organization_id)->whereIn('shipment_id',$shipmentIds)->whereNotIn('status',['cancelled','reversed'])->exists(),409,__('inventory.sales_handoff.invoice_amendment_return'));
            $orderLines=$order->lines()->orderBy('id')->get()->keyBy('id');
            foreach($request->lines()->get()as$line){
                $source=$orderLines->get($line->sales_order_line_id);
                abort_unless($source && (int)$source->item_id===(int)$line->item_id
                    && (int)$source->entered_unit_id===(int)$line->entered_unit_id,409,__('inventory.sales_handoff.invoice_amendment_physical'));
            }
            $orderEvidence=['id'=>(int)$order->id,'customer_id'=>(int)$order->customer_id,'warehouse_id'=>(int)$order->warehouse_id,
                'lines'=>$orderLines->values()->map(fn($line)=>$line->only(['id','item_id','entered_unit_id','ordered_qty','shipped_qty','cancelled_qty']))->all()];
        }else abort_unless(!$request->approved_at,409,__('inventory.sales_handoff.invoice_amendment_physical'));
        return ['order_evidence'=>$orderEvidence,'request_id'=>(int)$request->id,'status'=>$request->status,'warehouse_id'=>$request->warehouse_id,'sales_order_id'=>$request->sales_order_id,
            'approved_at'=>$request->approved_at?->toIso8601String(),'lines'=>$request->lines()->orderBy('id')->get()->map(fn($line)=>$line->only(['id','source_line_id','item_id','entered_unit_id','requested_qty','fulfilled_qty','cancelled_qty','sales_order_line_id']))->all()];
    }

    private function result(FulfillmentCommand $command): array
    {
        return ['version'=>(int)($command->payload['protocol_version']??1),'operation_uuid'=>$command->operation_uuid,'source_invoice_id'=>(int)$command->source_invoice_id,
            'status'=>$command->status,'hold_fingerprint'=>$command->payload['hold_fingerprint']??null,'receipt'=>$command->payload['receipt']??null,'physical_effects'=>0];
    }

    private function audit(FulfillmentRequest $request,FulfillmentCommand $command,string $action,?array $before,array $after): void
    {
        InventoryAuditLog::create(['organization_id'=>$request->organization_id,'actor_user_id'=>$command->actor_id,'action'=>$action,
            'entity_type'=>'sales_invoice_amendment','entity_id'=>$command->id,'document_ref'=>$request->source_invoice_number,
            'before'=>$before,'after'=>$after,'ip'=>request()?->ip(),'created_at'=>now()]);
    }
}
