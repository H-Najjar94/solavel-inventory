<?php
namespace Tests\Feature\Sales;
use App\Models\Tenant\{Reservation,StockBalance,StockLedger};
use App\Services\Documents\{OpeningStockService,SalesOrderService};
use App\Services\Stock\StockReservationService;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;
final class CreditDemandReservationTest extends TestCase {
 use TenantAware;
 public function test_duplicate_product_credit_releases_only_proven_aggregate_excess_without_physical_movement():void {
  $this->useTenantA();$warehouse=F::warehouse();$item=F::averageItem();$opening=app(OpeningStockService::class);$opening->post($opening->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'10','unit_cost'=>'2']]));
  $native=app(SalesOrderService::class);$order=$native->confirm($native->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'3','unit_price'=>'5'],['item_id'=>$item->id,'ordered_qty'=>'3','unit_price'=>'5']]));$reservationService=app(StockReservationService::class);$reservationService->reserve($item->id,$warehouse->id,'6','sales_order',$order->id);$this->assertSame('6.0000',(string)Reservation::where('status','active')->sum('qty'));
  $before=StockLedger::count();$order->lines[0]->update(['cancelled_qty'=>'1']);$order->lines[1]->update(['cancelled_qty'=>'2']);
  $service=app(StockReservationService::class);$service->releaseExcessForSalesOrder($order);$this->assertSame('3.0000',(string)Reservation::where('status','active')->sum('qty'));$this->assertSame('3.0000',(string)StockBalance::where('item_id',$item->id)->sole()->reserved_qty);$this->assertSame('10.0000',(string)StockBalance::where('item_id',$item->id)->sole()->on_hand_qty);$this->assertSame($before,StockLedger::count());
  $this->assertSame(0,$service->releaseExcessForSalesOrder($order));$native->reserve($order->fresh());$this->assertSame('3.0000',(string)Reservation::where('status','active')->sum('qty'));$this->assertSame('3.0000',(string)$order->lines[0]->fresh()->ordered_qty);$this->assertSame('3.0000',(string)$order->lines[1]->fresh()->ordered_qty);
 }
 public function test_serial_credit_releases_only_whole_owned_trace_and_foreign_trace_fails_atomically():void {
  $this->useTenantA();$warehouse=F::warehouse();$item=F::serialItem(['costing_method'=>'fifo']);$opening=app(OpeningStockService::class);$opening->post($opening->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'2','unit_cost'=>'2','serials'=>['QA-CREDIT-S1','QA-CREDIT-S2']]]));
  $native=app(SalesOrderService::class);$order=$native->confirm($native->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'2','unit_price'=>'5']]));$serials=\App\Models\Tenant\SerialNumber::where('item_id',$item->id)->orderBy('id')->get();$native->reserve($order,['serial_ids'=>[$order->lines[0]->id=>$serials->pluck('id')->all()]]);
  $service=app(StockReservationService::class);$before=StockLedger::count();$order->lines[0]->update(['cancelled_qty'=>'1']);$last=Reservation::where('status','active')->orderByDesc('id')->first();$foreign=F::serial(F::serialItem(),'QA-FOREIGN-CREDIT');$original=$last->serial_id;$last->update(['serial_id'=>$foreign->id]);
  try{$service->releaseExcessForSalesOrder($order);$this->fail('Foreign serial reservation was released');}catch(\RuntimeException$e){$this->assertStringContainsString('Serialized reservations',$e->getMessage());}$this->assertSame(2,Reservation::where('status','active')->count());$this->assertSame($before,StockLedger::count());
  $last->update(['serial_id'=>$original,'expires_at'=>now()->subDay()]);$this->assertSame(1,$service->releaseExcessForSalesOrder($order));$this->assertSame($serials[0]->id,Reservation::where('status','active')->sole()->serial_id);$this->assertSame($before,StockLedger::count());
 }
}
