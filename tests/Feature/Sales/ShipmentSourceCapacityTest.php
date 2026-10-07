<?php
namespace Tests\Feature\Sales;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\ShipmentService;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;
class ShipmentSourceCapacityTest extends TestCase
{
    use TenantAware;
    private function fixture(): array
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id'=>TenantTestManager::ORG_A],['default_costing_method'=>'average','allow_negative_stock'=>false]);
        $warehouse=F::warehouse();$item=F::averageItem();
        $opening=app(OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'20','unit_cost'=>'3']]));
        $sales=app(SalesOrderService::class);
        $order=$sales->confirm($sales->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'5','unit_price'=>'7']]));
        return [$warehouse,$item,$order];
    }
    public function test_foreign_order_line_cannot_be_attached_to_another_order(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$sales=app(SalesOrderService::class);
        $other=$sales->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'5','unit_price'=>'7']]);
        $before=StockLedger::query()->count();
        try{app(ShipmentService::class)->createDraft(['shipment_number'=>'QA-SHIP-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$other->lines->first()->id,'item_id'=>$item->id,'quantity'=>'1']]);$this->fail('Foreign source accepted');}
        catch(\RuntimeException $e){$this->assertStringContainsString('source line',$e->getMessage());}
        $this->assertSame($before,StockLedger::query()->count());
    }
    public function test_partial_shipments_and_replay_preserve_exact_order_capacity(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$service=app(ShipmentService::class);$source=$order->lines->first();
        $create=fn($quantity)=>$service->createDraft(['shipment_number'=>'QA-SHIP-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$source->id,'item_id'=>$item->id,'quantity'=>$quantity]]);
        $first=$service->post($create('2'));$count=StockLedger::query()->count();$service->post($first);
        $this->assertSame($count,StockLedger::query()->count());$this->assertSame('2.0000',(string)$source->fresh()->shipped_qty);
        $tooMuch=$create('4');try{$service->post($tooMuch);$this->fail('Overdispatch accepted');}catch(\RuntimeException $e){$this->assertSame(__('inventory.sales_handoff.exceeds_remaining'),$e->getMessage());}
        $this->assertSame($count,StockLedger::query()->count());$this->assertSame('draft',$tooMuch->fresh()->status);
        $service->post($create('3'));$this->assertSame('5.0000',(string)$source->fresh()->shipped_qty);
        $this->assertSame('15.0000',(string)StockBalance::query()->where('item_id',$item->id)->value('on_hand_qty'));
    }
    public function test_repeated_source_lines_are_aggregated_before_capacity_check(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$source=$order->lines->first();$service=app(ShipmentService::class);
        $line=['sales_order_line_id'=>$source->id,'item_id'=>$item->id,'quantity'=>'3'];
        $draft=$service->createDraft(['shipment_number'=>'QA-SHIP-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[$line,$line]);$before=StockLedger::query()->count();
        try{$service->post($draft);$this->fail('Duplicate lines exceeded remaining');}catch(\RuntimeException $e){$this->assertSame(__('inventory.sales_handoff.exceeds_remaining'),$e->getMessage());}
        $this->assertSame($before,StockLedger::query()->count());$this->assertSame('0.0000',(string)$source->fresh()->shipped_qty);
    }
    public function test_manual_native_line_recovers_only_unique_exact_order_source(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$service=app(ShipmentService::class);
        $draft=$service->createDraft(['shipment_number'=>'QA-SHIP-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'1']]);
        $this->assertSame((int)$order->lines->first()->id,(int)$draft->lines->first()->sales_order_line_id);
        $service->post($draft);$this->assertSame('1.0000',(string)$order->lines->first()->fresh()->shipped_qty);
        $ambiguous=app(SalesOrderService::class)->confirm(app(SalesOrderService::class)->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'2','unit_price'=>'7'],['item_id'=>$item->id,'ordered_qty'=>'3','unit_price'=>'8']]));
        $before=StockLedger::query()->count();
        try{$service->createDraft(['shipment_number'=>'QA-SHIP-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$ambiguous->id,'warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'1']]);$this->fail('Ambiguous source inferred');}catch(\RuntimeException $e){$this->assertSame(__('inventory.sales_handoff.source_line_invalid'),$e->getMessage());}
        $this->assertSame($before,StockLedger::query()->count());
    }

    public function test_partial_nonserial_shipment_retains_the_unshipped_reservation(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$order=app(SalesOrderService::class)->reserve($order);$source=$order->lines->first();$shipments=app(ShipmentService::class);
        $create=fn($qty)=>$shipments->createDraft(['shipment_number'=>'QA-PART-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$source->id,'item_id'=>$item->id,'quantity'=>$qty]]);
        $first=$shipments->post($create('2'));$count=StockLedger::query()->count();
        $this->assertSame('3.0000',(string)\App\Models\Tenant\Reservation::query()->where('source_id',$order->id)->where('status','active')->sole()->qty);
        $this->assertSame('3.0000',(string)StockBalance::query()->where('item_id',$item->id)->sole()->reserved_qty);
        $shipments->post($first);$this->assertSame($count,StockLedger::query()->count());
        $this->assertSame('3.0000',(string)\App\Models\Tenant\Reservation::query()->where('source_id',$order->id)->where('status','active')->sole()->qty);
        $shipments->post($create('3'));$this->assertSame(0,\App\Models\Tenant\Reservation::query()->where('source_id',$order->id)->where('status','active')->count());
        $this->assertSame('0.0000',(string)StockBalance::query()->where('item_id',$item->id)->sole()->reserved_qty);
        $this->assertSame('15.0000',(string)StockBalance::query()->where('item_id',$item->id)->sole()->on_hand_qty);
    }
    public function test_partial_serial_shipment_preserves_remaining_serial_hold_and_final_dispatch(): void
    {
        $this->useTenantA();$warehouse=F::warehouse();$item=F::serialItem(['costing_method'=>'fifo']);
        $opening=app(OpeningStockService::class);$opening->post($opening->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'quantity'=>'2','unit_cost'=>'3','serials'=>['QA-PART-S1','QA-PART-S2']]]));
        $serials=\App\Models\Tenant\SerialNumber::query()->where('item_id',$item->id)->orderBy('id')->get();$sales=app(SalesOrderService::class);
        $order=$sales->confirm($sales->createDraft(['warehouse_id'=>$warehouse->id],[['item_id'=>$item->id,'ordered_qty'=>'2','unit_price'=>'7']]));$source=$order->lines->first();
        $sales->reserve($order,['serial_ids'=>[$source->id=>$serials->pluck('id')->all()]]);$service=app(ShipmentService::class);
        $create=fn($serial)=>$service->createDraft(['shipment_number'=>'QA-SER-PART-'.\Illuminate\Support\Str::uuid(),'sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$source->id,'item_id'=>$item->id,'quantity'=>'1','serial_id'=>$serial->id]]);
        $first=$service->post($create($serials[0]));$count=StockLedger::query()->count();$service->post($first);
        $this->assertSame($count,StockLedger::query()->count());
        $this->assertSame((int)$serials[1]->id,(int)\App\Models\Tenant\Reservation::query()->where('source_id',$order->id)->where('status','active')->sole()->serial_id);
        $service->post($create($serials[1]));
        $this->assertSame('shipped',$order->fresh()->status);$this->assertSame('2.0000',(string)$source->fresh()->shipped_qty);
        $this->assertSame(0,\App\Models\Tenant\Reservation::query()->where('source_id',$order->id)->where('status','active')->count());
        $this->assertSame(2,\App\Models\Tenant\SerialNumber::query()->where('item_id',$item->id)->where('status','shipped')->count());
    }

}
