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
            $sourceLine=collect($request->source_payload['lines'])->firstWhere('source_document_line_id',(int)$source->source_document_line_id);abort_unless($sourceLine,409);
            $lines[]=['physical_line_id'=>$actual->id,'source_document_line_id'=>(int)$source->source_document_line_id,
                'item_external_id'=>(int)$sourceLine['item_external_id'],'unit_external_id'=>(int)$sourceLine['unit_external_id'],
                'quantity'=>Decimal::qty(Decimal::div($base,$factor)),'base_quantity'=>$base,'unit_conversion_factor'=>$factor,
                'base_unit_id'=>$actual->base_unit_id,'unit_conversion_hash'=>$actual->unit_conversion_hash,
                'unit_price'=>$sourceLine['unit_price'],'unit_cost'=>$shipment?null:Decimal::mul((string)$actual->unit_cost,$factor,8),'stock_item_id'=>$actual->item_id,'stock_unit_id'=>$actual->entered_unit_id];
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
            'request_uuid'=>$request->request_uuid,'request_revision'=>$request->source_revision,
            'physical'=>['type'=>$type,'id'=>$document->id,'mapping_uuid'=>$life->mapping_uuid,
                'number'=>$shipment?$document->shipment_number:$document->grn_number,'date'=>($shipment?$document->ship_date:$document->receipt_date)?->format('Y-m-d'),
                'journal_key'=>$journal->idempotency_key,'journal_event_uuid'=>$journal->event_uuid,'journal_payload_hash'=>SolaStockJournalContract::payloadHash($journal->payload),
                'currency'=>(array)data_get($journal->payload,'currency',[]),'lines'=>$lines],
            'request'=>app(OriginRequestService::class)->summary($request)];
        return FinancialOriginOutbox::create(['organization_id'=>$request->organization_id,'organization_mapping_uuid'=>$mapping->mapping_uuid,'event_uuid'=>$uuid,'operation_uuid'=>$command->operation_uuid,
            'event_type'=>$event,'source_document_type'=>$request->source_document_type,'source_document_id'=>$request->source_document_id,'source_journal_id'=>$request->source_journal_id,
            'physical_document_type'=>$type,'physical_document_id'=>$document->id,'external_source_key'=>$key,'payload_hash'=>SolaStockJournalContract::payloadHash($payload),'payload'=>$payload,'status'=>'pending']);
    }
}
