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
        try{app(ShipmentService::class)->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$other->lines->first()->id,'item_id'=>$item->id,'quantity'=>'1']]);$this->fail('Foreign source accepted');}
        catch(\RuntimeException $e){$this->assertStringContainsString('source line',$e->getMessage());}
        $this->assertSame($before,StockLedger::query()->count());
    }
    public function test_partial_shipments_and_replay_preserve_exact_order_capacity(): void
    {
        [$warehouse,$item,$order]=$this->fixture();$service=app(ShipmentService::class);$source=$order->lines->first();
        $create=fn($quantity)=>$service->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[['sales_order_line_id'=>$source->id,'item_id'=>$item->id,'quantity'=>$quantity]]);
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
        $draft=$service->createDraft(['sales_order_id'=>$order->id,'warehouse_id'=>$warehouse->id],[$line,$line]);$before=StockLedger::query()->count();
        try{$service->post($draft);$this->fail('Duplicate lines exceeded remaining');}catch(\RuntimeException $e){$this->assertSame(__('inventory.sales_handoff.exceeds_remaining'),$e->getMessage());}
        $this->assertSame($before,StockLedger::query()->count());$this->assertSame('0.0000',(string)$source->fresh()->shipped_qty);
    }
}
