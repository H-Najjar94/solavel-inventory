<?php
namespace Tests\Feature\Stock;
use App\Models\Tenant\{InventorySetting,StockBalance,StockLedger,CostLayer,IntegrationAccountMapping,IntegrationOutboxEvent};
use App\Services\Documents\OpeningStockService;
use App\Services\Integration\AccountingJournalBuilder;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;
final class MoneyPrecisionThreeNativeTest extends TestCase
{
 use TenantAware;
 public function test_native_fifo_funding_valuation_retry_and_journal_contract_keep_one_milliunit():void {
  $this->useTenantA();InventorySetting::updateOrCreate(['organization_id'=>TenantTestManager::ORG_A],['default_costing_method'=>'fifo','allow_negative_stock'=>false]);$warehouse=F::warehouse();$item=F::fifoItem();$service=app(OpeningStockService::class);
  $entry=$service->createDraft(['entry_number'=>'OS-MILLI','warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'1.0000','unit_cost'=>'0.0010']]);$service->post($entry);
  $ledger=StockLedger::where('source_id',$entry->id)->sole();$balance=StockBalance::where('item_id',$item->id)->sole();$layer=CostLayer::where('item_id',$item->id)->sole();self::assertSame('0.001',$ledger->total_cost);self::assertSame('1.0000',$ledger->quantity);self::assertSame('0.0010',$ledger->unit_cost);self::assertSame('0.001000',$balance->total_value);$valuation=app(\App\Http\Controllers\Api\V1\ItemController::class)->valuation($item)->getData(true)['data'];self::assertSame('0.001',$valuation['warehouses'][0]['fifo_value']);self::assertSame('1.0000',$layer->remaining_qty);
  $before=$balance->getRawOriginal();$service->post($entry->fresh());self::assertSame(1,StockLedger::where('source_id',$entry->id)->count());self::assertSame($before,$balance->fresh()->getRawOriginal());
  foreach(['inventory_asset'=>100,'opening_offset'=>700] as $role=>$id)IntegrationAccountMapping::create(['mapping_type'=>$role,'integration'=>'solabooks','solabooks_account_id'=>(string)$id,'status'=>'mapped']);
  $event=IntegrationOutboxEvent::create(['event_uuid'=>fake()->uuid(),'integration'=>'solabooks','event_type'=>'opening_stock.posted','aggregate_type'=>'OpeningStockEntry','aggregate_id'=>$entry->id,'aggregate_number'=>$entry->entry_number,'occurred_at'=>now(),'payload'=>['document_date'=>now()->toDateString(),'total_inventory_value_change'=>$ledger->getRawOriginal('total_cost'),'suggested_debit_account_mapping'=>'inventory_asset','suggested_credit_account_mapping'=>'opening_offset'],'status'=>'pending','mapping_status'=>'complete','attempts'=>0,'idempotency_key'=>'test:opening:milli:'.$entry->id]);
  $lines=app(AccountingJournalBuilder::class)->build($event,TenantTestManager::ORG_A);self::assertSame(['0.001','0.000'],array_column($lines,'debit'));self::assertSame(['0.000','0.001'],array_column($lines,'credit'));self::assertSame([100,700],array_column($lines,'account_id'));
 }
}
