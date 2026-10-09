<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,GoodsReceipt,IntegrationDocumentLifecycleMapping,IntegrationOrganizationMapping,IntegrationOutboxEvent,IntegrationSetting,Shipment};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** No HTTP or financial document creation inside physical posting. */
final class OriginDocumentBuilder
{
    /** Full source reversal preserves the original financial and conversion snapshots. */
    public function recordReversed(FinancialOriginRequest $request,FinancialOriginCommand $command,GoodsReceipt|Shipment $document,\App\Models\Tenant\InventoryReversal|\App\Models\Tenant\SalesReturn $inverse):FinancialOriginOutbox
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0 && $command->status==='completed' && $inverse->status==='posted'
            && (int)$inverse->organization_id===(int)$request->organization_id,409);
        $shipment=$document instanceof Shipment;
        abort_unless(($shipment && $inverse instanceof \App\Models\Tenant\SalesReturn && $inverse->is_source_reversal
            && (int)$inverse->source_reversal_shipment_id===(int)$document->id && (int)$document->reversal_sales_return_id===(int)$inverse->id)
            || (!$shipment && $inverse instanceof \App\Models\Tenant\InventoryReversal && $inverse->source_type==='goods_receipt'
            && (int)$inverse->source_id===(int)$document->id && (int)$document->reversal_id===(int)$inverse->id),409);
        $original=FinancialOriginOutbox::query()->where('organization_id',$request->organization_id)->where('operation_uuid',$command->operation_uuid)
            ->where('physical_document_id',$document->id)->where('event_type',$shipment?'financial-origin.shipment.confirmed':'financial-origin.receipt.confirmed')->firstOrFail();
        abort_unless(SolaStockJournalContract::payloadHash($original->payload)===$original->payload_hash,409);
        $key=$original->external_source_key.':reversed:'.$inverse->id;
        if($existing=FinancialOriginOutbox::query()->where('organization_id',$request->organization_id)->where('external_source_key',$key)->first())return $existing;
        $journal=IntegrationOutboxEvent::query()->where('organization_id',$request->organization_id)->where('aggregate_id',$inverse->id)
            ->where('event_type',$shipment?'sales_return.posted':'grn.reversed')->firstOrFail();
        if(!$shipment)abort_unless($inverse->original_event_uuid===data_get($original->payload,'physical.journal_event_uuid'),409);
        else {
            $returns=$inverse->lines()->get()->groupBy('source_shipment_line_id');
            foreach(data_get($original->payload,'physical.lines',[])as$line){
                $actual=$returns->get($line['physical_line_id']);abort_unless($actual,409);
                $quantity=$actual->reduce(fn($sum,$row)=>Decimal::add($sum,(string)$row->returned_qty),'0');
                abort_unless(Decimal::cmp($quantity,(string)$line['base_quantity'])===0,409);
            }
            abort_unless($returns->count()===count(data_get($original->payload,'physical.lines',[])),409);
        }
        $payload=$original->payload;$uuid=(string)Str::uuid();$event=$shipment?'financial-origin.shipment.reversed':'financial-origin.receipt.reversed';
        abort_unless(!isset($payload['operation_uuid']) || $payload['operation_uuid']===$command->operation_uuid,409);
        $payload['operation_uuid']=$command->operation_uuid;
        $payload['event_type']=$event;$payload['event_uuid']=$uuid;$payload['external_source_key']=$key;
        $payload['original_event_uuid']=$original->event_uuid;$payload['original_payload_hash']=$original->payload_hash;
        $payload['reversal']=['type'=>$shipment?'sales_return':'inventory_reversal','id'=>(int)$inverse->id,
            'journal_key'=>$journal->idempotency_key,'journal_event_uuid'=>$journal->event_uuid,
            'journal_payload_hash'=>self::journalHash($journal),'currency'=>(array)data_get($journal->payload,'currency',[])];
        $payload['request']=app(OriginRequestService::class)->summary($request->fresh('lines'));
        return FinancialOriginOutbox::create(['organization_id'=>$request->organization_id,'organization_mapping_uuid'=>$request->organization_mapping_uuid,
            'event_uuid'=>$uuid,'operation_uuid'=>$command->operation_uuid,'event_type'=>$event,'source_document_type'=>$request->source_document_type,
            'source_document_id'=>$request->source_document_id,'source_journal_id'=>$request->source_journal_id,
            'physical_document_type'=>$original->physical_document_type,'physical_document_id'=>$document->id,'external_source_key'=>$key,
            'payload_hash'=>SolaStockJournalContract::payloadHash($payload),'payload'=>$payload,'status'=>'pending']);
    }

    public function record(FinancialOriginRequest $request,FinancialOriginCommand $command,GoodsReceipt|Shipment $document):FinancialOriginOutbox
    {
        abort_unless(DB::connection('tenant')->transactionLevel()>0 && $document->status==='posted' && $command->status==='completed',409);
        $shipment=$document instanceof Shipment;$type=$shipment?'shipment':'goods_receipt';
        abort_unless(($shipment && $request->source_document_type==='sales_receipt') || (!$shipment && $request->source_document_type==='expense'),409);
        $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$request->organization_mapping_uuid)->where('solastock_organization_id',$document->organization_id)->firstOrFail();
        $life=IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type',$type)->where('source_document_id',(string)$document->id)->firstOrFail();
        $key='financial-origin:'.$request->source_document_type.':'.$request->source_document_id.':journal:'.$request->source_journal_id.':'.$life->mapping_uuid.':confirmed';
        if($existing=FinancialOriginOutbox::query()->where('external_source_key',$key)->first())return $existing;
        $journal=IntegrationOutboxEvent::query()->where('organization_id',$document->organization_id)->where('event_type',$shipment?'shipment.posted':'grn.posted')->where('aggregate_id',$document->id)->firstOrFail();
        $lines=[];$native=$document->lines()->get()->keyBy('id');$requested=$request->lines()->get()->keyBy('id');
        foreach($command->native_line_links??[]as$link){
            $actual=$native->get($link['physical_line_id']);$source=$requested->get($link['request_line_id']);
            abort_unless($actual && $source && (int)$source->source_document_line_id===(int)$link['source_document_line_id'],409);
            $factor=(string)$actual->unit_conversion_factor;abort_unless(Decimal::gt($factor,'0'),409);
            $base=(string)($shipment?$actual->quantity:$actual->accepted_qty);
            $nativeValueFields=[];
            if(!$shipment){
                $ledger=\App\Models\Tenant\StockLedger::query()->where('organization_id',$request->organization_id)
                    ->where('source_type',GoodsReceipt::class)->where('source_id',$document->id)->where('source_line_id',$actual->id)->where('direction','in')->sole();
                abort_unless(Decimal::cmp((string)$ledger->quantity,$base)===0 && (int)$ledger->item_id===(int)$actual->item_id
                    && (int)$ledger->warehouse_id===(int)$document->warehouse_id,409);
                $nativeValueFields=['stock_ledger_id'=>(int)$ledger->id,'stock_value_base'=>(string)$ledger->total_cost,'stock_money_scale'=>Decimal::MONEY_SCALE];
            }
            $sourceLine=collect($request->source_payload['lines'])->firstWhere('source_document_line_id',(int)$source->source_document_line_id);abort_unless($sourceLine,409);
            $lines[]=['physical_line_id'=>$actual->id,'source_document_line_id'=>(int)$source->source_document_line_id,
                'item_external_id'=>(int)$sourceLine['item_external_id'],'unit_external_id'=>(int)$sourceLine['unit_external_id'],
                'quantity'=>Decimal::qty(Decimal::div($base,$factor)),'base_quantity'=>$base,'unit_conversion_factor'=>$factor,
                'base_unit_id'=>$actual->base_unit_id,'unit_conversion_hash'=>$actual->unit_conversion_hash,
                'unit_price'=>$sourceLine['unit_price'],'unit_cost'=>$shipment?null:Decimal::mul((string)$actual->unit_cost,$factor,8),'stock_item_id'=>$actual->item_id,'stock_unit_id'=>$actual->entered_unit_id]+$nativeValueFields;
        }
        abort_unless(count($lines)===$native->count(),409);
        $uuid=(string)Str::uuid();$setting=IntegrationSetting::query()->where('organization_id',$request->organization_id)->where('integration','solabooks')->firstOrFail();
        $event=$shipment?'financial-origin.shipment.confirmed':'financial-origin.receipt.confirmed';
        $payload=['source_app'=>'solastock','schema_version'=>'financial-origin.v1','contract_version'=>SolaStockJournalContract::VERSION,
            'event_type'=>$event,'event_uuid'=>$uuid,'external_source_key'=>$key,'inventory_organization_id'=>$request->organization_id,'finance_organization_id'=>$mapping->finance_organization_id,
            'identity'=>['central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,
                'inventory_organization_id'=>$mapping->solastock_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,
                'organization_mapping_uuid'=>$mapping->mapping_uuid,'integration_mapping_id'=>$mapping->id,'signing_key_id'=>(string)data_get($setting->meta,'signing_key_id')],
            'source_document_type'=>$request->source_document_type,'source_document_id'=>(int)$request->source_document_id,
            'source_document_number'=>$request->source_document_number,'source_journal_id'=>(int)$request->source_journal_id,
            'request_uuid'=>$request->request_uuid,'request_revision'=>$request->source_revision,'operation_uuid'=>$command->operation_uuid,
            'physical'=>['type'=>$type,'id'=>$document->id,'mapping_uuid'=>$life->mapping_uuid,
                'number'=>$shipment?$document->shipment_number:$document->grn_number,'date'=>($shipment?$document->ship_date:$document->receipt_date)?->format('Y-m-d'),
                'journal_key'=>$journal->idempotency_key,'journal_event_uuid'=>$journal->event_uuid,'journal_payload_hash'=>self::journalHash($journal),
                'currency'=>(array)data_get($journal->payload,'currency',[]),'lines'=>$lines],
            'request'=>app(OriginRequestService::class)->summary($request)];
        return FinancialOriginOutbox::create(['organization_id'=>$request->organization_id,'organization_mapping_uuid'=>$mapping->mapping_uuid,'event_uuid'=>$uuid,'operation_uuid'=>$command->operation_uuid,
            'event_type'=>$event,'source_document_type'=>$request->source_document_type,'source_document_id'=>$request->source_document_id,'source_journal_id'=>$request->source_journal_id,
            'physical_document_type'=>$type,'physical_document_id'=>$document->id,'external_source_key'=>$key,'payload_hash'=>SolaStockJournalContract::payloadHash($payload),'payload'=>$payload,'status'=>'pending']);
    }

    /** Hash of the canonical signed journal envelope Finance receives (SolaBooksOutboxDeliveryService::sendClaimed), not of the raw outbox payload. */
    public static function journalHash(\App\Models\Tenant\IntegrationOutboxEvent $journal): string
    {
        return SolaStockJournalContract::payloadHash(app(\App\Services\Integration\SolaStockJournalContractBuilder::class)->build($journal));
    }

    /** Proofs emitted before the canonical envelope binding carried the raw outbox hash; both are bound to the same native event identity. */
    public static function journalHashMatches(\App\Models\Tenant\IntegrationOutboxEvent $journal, string $hash): bool
    {
        return strlen($hash) === 64 && (hash_equals(SolaStockJournalContract::payloadHash($journal->payload), $hash)
            || hash_equals(self::journalHash($journal), $hash));
    }
}
