<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginRequest,GoodsReceipt,Shipment};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Native posting hooks use exact durable command links, never source-name or number heuristics. */
final class OriginPhysicalService
{
    public function beforeReverse(GoodsReceipt|Shipment $document):OriginPhysicalReversalContext|OriginCashPhysicalReversalContext|null
    {
        $db=DB::connection('tenant');abort_unless($db->transactionLevel()===0,409);
        if(!$db->getSchemaBuilder()->hasTable('stock_financial_origin_commands'))return null;
        $field=$document instanceof Shipment?'shipment_id':'goods_receipt_id';
        $command=FinancialOriginCommand::query()->where('organization_id',$document->organization_id)->where($field,$document->id)->first();
        if(!$command)return null;
        abort_unless($command->status==='completed',409);
        $request=FinancialOriginRequest::query()->where('organization_id',$document->organization_id)->where('request_uuid',$command->request_uuid)->firstOrFail();
        OriginSourceAdmission::stock($request,(int)(request()->user()?->getAuthIdentifier()??0),
            $document instanceof Shipment?'inventory.manage_returns':'inventory.manage_adjustments');
        $event=\App\Models\Tenant\FinancialOriginOutbox::query()->where('organization_id',$document->organization_id)
            ->where('operation_uuid',$command->operation_uuid)->where('physical_document_id',$document->id)
            ->where('event_type',$document instanceof Shipment?'financial-origin.shipment.confirmed':'financial-origin.receipt.confirmed')->firstOrFail();
        abort_unless($event->payload_hash===\App\Services\Integration\SolaStockJournalContract::payloadHash($event->payload),409);
        $facts=['source_document_type'=>$request->source_document_type,'source_document_id'=>(int)$request->source_document_id,
            'source_journal_id'=>(int)$request->source_journal_id,'request_uuid'=>$request->request_uuid,'source_revision'=>$request->source_revision,
            'physical_document_type'=>$event->physical_document_type,'physical_document_id'=>(int)$document->id,
            'physical_mapping_uuid'=>data_get($event->payload,'physical.mapping_uuid'),'physical_journal_key'=>data_get($event->payload,'physical.journal_key'),
            'physical_journal_event_uuid'=>data_get($event->payload,'physical.journal_event_uuid'),
            'physical_journal_payload_hash'=>data_get($event->payload,'physical.journal_payload_hash')];
        $proof=app(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->authorizeOriginPhysicalReversal($facts);
        return $request->source_document_type==='sales_receipt'
            ? OriginCashPhysicalReversalContext::fromProof($facts,$proof,(int)$document->organization_id,(int)$command->id)
            : OriginPhysicalReversalContext::fromProof($facts,$proof,(int)$document->organization_id,(int)$command->id);
    }

    public function reversed(GoodsReceipt|Shipment $document,\App\Models\Tenant\InventoryReversal|\App\Models\Tenant\SalesReturn $inverse):?array
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409);
        if(!DB::connection('tenant')->getSchemaBuilder()->hasTable('stock_financial_origin_commands'))return null;
        $field=$document instanceof Shipment?'shipment_id':'goods_receipt_id';
        $command=FinancialOriginCommand::query()->where('organization_id',$document->organization_id)->where($field,$document->id)->lockForUpdate()->first();
        if(!$command)return null;
        $request=FinancialOriginRequest::query()->where('organization_id',$document->organization_id)->where('request_uuid',$command->request_uuid)->firstOrFail();
        if($request->source_document_type==='sales_receipt')app(CashRefundDemandService::class)->assertFullPhysicalReversalUnlocked($request);
        $event=app(OriginDocumentBuilder::class)->recordReversed($request,$command,$document,$inverse);
        // Fulfilled is gross physical history: reversal never reopens demand or reserves stock.
        return ['event_uuid'=>$event->event_uuid,'event_type'=>$event->event_type,'request'=>app(OriginRequestService::class)->summary($request)];
    }

    public function lockAndValidateDocument(GoodsReceipt|Shipment $document): ?FinancialOriginRequest
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0,409);
        if(!DB::connection('tenant')->getSchemaBuilder()->hasTable('stock_financial_origin_commands'))return null;
        $field=$document instanceof Shipment?'shipment_id':'goods_receipt_id';
        $known=FinancialOriginCommand::query()->where('organization_id',$document->organization_id)->where($field,$document->id)->first();
        if(!$known){
            if($document instanceof Shipment && $document->sales_order_id && $document->status!=='posted'){
                $cash=FinancialOriginRequest::query()->where('organization_id',$document->organization_id)
                    ->where('source_document_type','sales_receipt')->where('sales_order_id',$document->sales_order_id)->lockForUpdate()->first();
                if($cash){
                    app(CashRefundDemandService::class)->assertDispatchUnlocked($cash);
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'sales_order_id'=>app()->getLocale()==='ar'
                            ? 'هذا الطلب مرتبط ببيع نقدي. أكّد التسليم من طلب التنفيذ المرتبط حتى لا يتم إنشاء فاتورة أخرى.'
                            : 'This order belongs to a cash sale. Confirm dispatch through its linked fulfillment request to avoid creating another invoice.',
                    ]);
                }
            }
            return null;
        }
        $r=FinancialOriginRequest::query()->where('organization_id',$document->organization_id)->where('request_uuid',$known->request_uuid)
            ->where('source_document_type',$known->source_document_type)->where('source_document_id',$known->source_document_id)->where('source_journal_id',$known->source_journal_id)->firstOrFail();
        LockedOriginProof::lockAccepted($r,(int)(request()->user()?->getAuthIdentifier()??0));
        $r=$r->newQuery()->whereKey($r->id)->lockForUpdate()->firstOrFail();
        $command=$known->newQuery()->whereKey($known->id)->lockForUpdate()->firstOrFail();
        if($command->status==='completed') {
            OriginSourceAdmission::stock($r,(int)(request()->user()?->getAuthIdentifier()??0),
                $document instanceof Shipment?'inventory.manage_shipments':'inventory.receive_goods');
            app(\App\Services\Access\WarehouseAccessService::class)->assertAllowed((int)$document->warehouse_id);
            $posted=$document->newQuery()->where('organization_id',$r->organization_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless($posted->status==='posted' && (int)$posted->warehouse_id===(int)$r->warehouse_id
                && $r->approved_at && $r->approved_revision===$r->source_revision && data_get($command->payload,'request_revision')===$r->source_revision,409);
            if($document instanceof Shipment)abort_unless($r->side==='sales' && $r->source_document_type==='sales_receipt'
                && (int)$posted->sales_order_id===(int)$r->sales_order_id,409);
            else abort_unless($r->side==='purchase' && $r->source_document_type==='expense' && !$posted->purchase_order_id && !$posted->receiving_request_id,409);
            $event=\App\Models\Tenant\FinancialOriginOutbox::query()->where('organization_id',$r->organization_id)
                ->where('operation_uuid',$command->operation_uuid)->where('physical_document_id',$posted->id)
                ->where('event_type',$posted instanceof Shipment?'financial-origin.shipment.confirmed':'financial-origin.receipt.confirmed')->firstOrFail();
            abort_unless($event->payload_hash===\App\Services\Integration\SolaStockJournalContract::payloadHash($event->payload)
                && data_get($event->payload,'request_uuid')===$r->request_uuid
                && data_get($event->payload,'request_revision')===$r->source_revision
                && (int)data_get($event->payload,'physical.id')===(int)$posted->id
                && (int)data_get($event->payload,'source_document_id')===(int)$r->source_document_id,409);
            $native=$posted->lines()->get()->keyBy('id');$facts=data_get($event->payload,'physical.lines',[]);
            abort_unless(count($facts)===$native->count(),409);
            foreach($facts as $fact){
                $line=$native->get((int)$fact['physical_line_id']);
                abort_unless($line && (int)$line->item_id===(int)$fact['stock_item_id'] && (int)$line->entered_unit_id===(int)$fact['stock_unit_id']
                    && Decimal::cmp((string)($posted instanceof Shipment?$line->quantity:$line->accepted_qty),(string)$fact['base_quantity'])===0
                    && (string)$line->unit_conversion_hash===(string)$fact['unit_conversion_hash'],409);
            }
            return $r; // Native caller's posted-document idempotent return; no fulfilment/outbox writes.
        }
        app(CashRefundDemandService::class)->assertDispatchUnlocked($r);
        // The exact durable command was already accepted. Gate changes cannot strand its native confirmation.
        abort_unless($command->status==='pending' && in_array($r->status,['pending','partial'],true) && $r->approved_at
            && $r->approved_revision===$r->source_revision && (int)$r->warehouse_id===(int)$document->warehouse_id,409);
        if($document instanceof Shipment)abort_unless($r->side==='sales' && $r->source_document_type==='sales_receipt' && (int)$r->sales_order_id===(int)$document->sales_order_id,409);
        else abort_unless($r->side==='purchase' && $r->source_document_type==='expense' && !$document->purchase_order_id && !$document->receiving_request_id,409);
        $r->loadMissing('lines');
        foreach($command->payload['lines'] as $line){
            $source=$r->lines->firstWhere('id',(int)$line['request_line_id']);
            abort_unless($source && (int)$source->source_document_line_id===(int)$line['source_document_line_id']
                && Decimal::cmp((string)$line['quantity'],Decimal::sub(Decimal::sub((string)$source->requested_quantity,(string)$source->fulfilled_quantity),(string)$source->cancelled_quantity)) <= 0,409);
        }
        return $r;
    }

    /** Bind exact native creation order before posting; duplicate products keep distinct source-line identities. */
    public function bindNativeLines(FinancialOriginCommand $command,GoodsReceipt|Shipment $document,FinancialOriginRequest $request):void
    {
        $native=$document->lines()->orderBy('id')->get();$links=[];$cursor=0;
        foreach($command->payload['lines']as$line){
            $source=$request->lines()->findOrFail($line['request_line_id']);
            $count=count($line['serial_ids']??$line['serials']??[]);if(!$count)$count=1;
            $total='0';
            for($i=0;$i<$count;$i++){
                $actual=$native[$cursor++]??null;abort_unless($actual && (int)$actual->item_id===(int)$source->item_id && (int)$actual->entered_unit_id===(int)$source->unit_id,409);
                if($document instanceof Shipment)abort_unless((int)$actual->sales_order_line_id===(int)$source->sales_order_line_id,409);
                abort_unless(Decimal::cmp((string)$actual->unit_conversion_factor,(string)$source->unit_conversion_factor)===0,409);
                $quantity=(string)($document instanceof Shipment?$actual->quantity:$actual->accepted_qty);
                $total=Decimal::add($total,$quantity);
                $links[]=['physical_line_id'=>$actual->id,'request_line_id'=>$source->id,'source_document_line_id'=>$source->source_document_line_id];
            }
            abort_unless(Decimal::cmp($total,Decimal::qty(Decimal::mul((string)$line['quantity'],(string)$source->unit_conversion_factor)))===0,409);
        }
        abort_unless($cursor===$native->count(),409);$command->update(['native_line_links'=>$links]);
    }

    public function posted(GoodsReceipt|Shipment $document): ?array
    {
        $field=$document instanceof Shipment?'shipment_id':'goods_receipt_id';
        if(!DB::connection('tenant')->getSchemaBuilder()->hasTable('stock_financial_origin_commands'))return null;
        $command=FinancialOriginCommand::query()->where('organization_id',$document->organization_id)->where($field,$document->id)->lockForUpdate()->first();
        if(!$command)return null;
        abort_unless($document->status==='posted',409);
        $r=FinancialOriginRequest::query()->where('request_uuid',$command->request_uuid)->lockForUpdate()->firstOrFail();
        if($command->status==='completed')return $command->response;
        foreach($command->payload['lines']as$line){
            $source=$r->lines()->whereKey($line['request_line_id'])->lockForUpdate()->firstOrFail();
            $source->update(['fulfilled_quantity'=>Decimal::qty(Decimal::add((string)$source->fulfilled_quantity,(string)$line['quantity']))]);
        }
        $r->status=$r->lines()->get()->every(fn($l)=>Decimal::cmp(Decimal::add((string)$l->fulfilled_quantity,(string)$l->cancelled_quantity),(string)$l->requested_quantity)>=0)?'complete':'partial';$r->save();
        $response=app(OriginRequestService::class)->summary($r->fresh('lines'));
        $command->update(['status'=>'completed','response'=>$response]);
        app(OriginDocumentBuilder::class)->record($r,$command,$document);
        if($r->source_document_type==='sales_receipt')app(CashNotificationPublisher::class)->changed($r);
        return $response;
    }
}
