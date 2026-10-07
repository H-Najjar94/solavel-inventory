<?php
namespace App\Services\Sales;
use App\Models\Tenant\{SupplierReturn,IntegrationOrganizationMapping,IntegrationDocumentLifecycleMapping,IntegrationOutboxEvent,IntegrationSetting,PurchasingDocumentOutbox};
use App\Services\Integration\{IntegrationEvents,SolaStockJournalContract};
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Immutable supplier return facts use the existing durable purchasing channel, never a second financial journal. */
final class SupplierReturnDocumentBuilder
{
 public function record(SupplierReturn$return,bool$reversed=false):?PurchasingDocumentOutbox
 {
  return DB::connection('tenant')->transaction(function()use($return,$reversed){
   $org=(int)$return->organization_id;
   $mapping=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())
    ->whereIn('status',['verified','verified_hold'])->whereIn('activation_state',['active','maintenance_hold'])->lockForUpdate()->first();
   if(!$mapping)return null;
   $return=SupplierReturn::query()->where('organization_id',$org)->whereKey($return->id)->lockForUpdate()->with(['lines','goodsReceipt.lines'])->firstOrFail();
   abort_unless($reversed?$return->reversed_at:$return->status==='posted',409);
   $life=IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type','supplier_return')->where('source_document_id',(string)$return->id)->firstOrFail();
   $parent=IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('source_document_type','goods_receipt')->where('source_document_id',(string)$return->goods_receipt_id)->firstOrFail();
   $key='purchasing:return:'.$life->mapping_uuid.':'.($reversed?'reversed':'confirmed');
   if($existing=PurchasingDocumentOutbox::query()->where('organization_id',$org)->where('source_key',$key)->first())return$existing;
   $journal=IntegrationOutboxEvent::query()->where('organization_id',$org)->where('event_type',$reversed?'supplier_return.reversed':'supplier_return.posted')
    ->where('aggregate_id',$return->id)->latest('id')->firstOrFail();
   $original=PurchasingDocumentOutbox::query()->where('organization_id',$org)->where('goods_receipt_id',$return->goods_receipt_id)->where('event_type','purchasing.receipt.confirmed')->oldest('id')->first();
   abort_unless($original,409);$receiptFacts=$original->payload['receipt'];
   $lines=[];foreach($return->lines as$line){
    $source=$return->goodsReceipt->lines->firstWhere('id',$line->goods_receipt_line_id);abort_unless($source,409);
    $lines[]=['source_line_id'=>(string)$line->id,'source_receipt_line_id'=>(string)$source->id,'source_stock_ledger_id'=>(string)$line->source_stock_ledger_id,
     'item_id'=>$line->item_id,'unit_id'=>$line->entered_unit_id,'quantity'=>(string)$line->entered_qty,'base_quantity'=>(string)$line->quantity,
     'unit_conversion_factor'=>(string)$line->unit_conversion_factor,'unit_conversion_hash'=>$line->unit_conversion_hash,'unit_conversion_version'=>$line->unit_conversion_version,
     'actual_out_base'=>(string)$line->actual_return_cost_base,'variant_id'=>$line->variant_id,'bin_id'=>$line->bin_id,'lot_id'=>$line->lot_id,'serial_id'=>$line->serial_id];
   }
   $setting=IntegrationSetting::query()->where('organization_id',$org)->where('integration',IntegrationEvents::INTEGRATION)->firstOrFail();$uuid=(string)Str::uuid();
   $payload=['source_app'=>'solastock','schema_version'=>'purchasing.v1','contract_version'=>SolaStockJournalContract::VERSION,'event_uuid'=>$uuid,
    'event_type'=>$reversed?'purchasing.return.reversed':'purchasing.return.confirmed','external_source_key'=>$key,
    'inventory_organization_id'=>$org,'finance_organization_id'=>$mapping->finance_organization_id,
    'identity'=>['central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,
     'inventory_organization_id'=>$org,'integration_mapping_id'=>$mapping->id,'organization_mapping_uuid'=>$mapping->mapping_uuid,'signing_key_id'=>data_get($setting->meta,'signing_key_id')],
    'return'=>['mapping_uuid'=>$life->mapping_uuid,'id'=>$return->id,'number'=>$return->return_number,'date'=>$return->return_date->format('Y-m-d'),
     'receipt_id'=>$return->goods_receipt_id,'receipt_mapping_uuid'=>$parent->mapping_uuid,'receipt_event_uuid'=>$original->event_uuid,
     'supplier_id'=>$return->supplier_id,'source_bill_id'=>$receiptFacts['source_bill_id']??null,'currency_code'=>$receiptFacts['currency_code'],
     'base_currency_code'=>$mapping->base_currency_code,'receipt_exchange_rate'=>$receiptFacts['exchange_rate'],'receipt_exchange_rate_date'=>$receiptFacts['exchange_rate_date'],
     'journal_idempotency_key'=>$journal->idempotency_key,'lines'=>$lines]];
   return PurchasingDocumentOutbox::create(['organization_id'=>$org,'goods_receipt_id'=>$return->goods_receipt_id,'event_uuid'=>$uuid,'event_type'=>$payload['event_type'],
    'source_key'=>$key,'payload'=>$payload,'payload_hash'=>hash('sha256',SolaStockJournalContract::canonicalJson($payload))]);
  });
 }
}
