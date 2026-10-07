<?php
namespace Tests\Feature\Integration;
use App\Models\User;
use App\Models\Tenant\{FinancialOriginCommand,FinancialOriginOutbox,FinancialOriginRequest,GoodsReceipt,IntegrationAccountMapping,InventoryUserWarehouse,SalesDocumentOutbox,Shipment,StockBalance,StockLedger,Supplier};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\FinancialOrigins\{OriginDispatchService,OriginRequestService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinancialOriginFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native Stock orders/shipments/GRNs/ledgers, with explicit remote admission and Finance source projection seams. */
final class FinancialOriginPhysicalTest extends TestCase
{
    use TenantAware,FinancialOriginFixture;
    private function actor(int $id,bool $owner=false,bool $stock=true):void
    {
        config(['inventory.demo_tenant.enabled'=>false]);$actor=new User;$actor->id=$id;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturnUsing(fn($user,$org,$app)=>['allowed'=>$app==='inventory'&&$stock,'owner'=>$owner,'roles'=>$owner?[]:['warehouse_operator']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
    }
    private function admitted(string $type='sales_receipt',string $tracking='none',bool $anonymous=false):array
    {
        $this->initializeOriginFixture(true,$tracking);
        if($type==='expense'){
            $supplier=Supplier::create(['code'=>'QA-TYPED-PHYSICAL','name'=>'QA typed physical supplier','is_active'=>true]);$this->master('supplier',$supplier->id,704);
            DB::connection('tenant')->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
            $a=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$a->id,300);
        }
        $data=$this->typed($type,$anonymous);$this->proof($data);$r=app(OriginRequestService::class)->upsert($data,323);
        $context=array_intersect_key($data,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$data['source_revision']];
        $this->actor(323,true);app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],323);
        InventoryUserWarehouse::create(['user_id'=>336,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);$this->actor(336);
        $op=$context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07',
            'lines'=>[['request_line_id'=>$r['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]];
        return [$data,$context,$op];
    }
    public function test_stock_only_cash_dispatch_partial_final_and_replay_emit_typed_events_without_new_invoice_draft():void
    {
        [,,$op]=$this->admitted();$service=app(OriginDispatchService::class);$before=StockLedger::count();
        $this->assertTrue(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_shipments'));
        $this->assertFalse(app(InventoryPermissionService::class)->can(request()->user(),'inventory.manage_adjustments'));
        $this->assertFalse(app(CentralAppAccess::class)->decision(336,FinancialOriginRequest::sole()->organization_id,'finance')['allowed']);
        $prepared=$service->prepareNative($op,336);$this->assertSame('prepared',$prepared['status']);$this->assertSame(0,Shipment::count());$this->assertSame($before,StockLedger::count());
        $partial=$service->executeNative($op,336);$this->assertSame($partial,$service->executeNative($op,336));$this->assertSame('partial',$partial['status']);
        $this->assertSame(1,Shipment::count());$this->assertSame($before+1,StockLedger::count());$this->assertSame(0,SalesDocumentOutbox::count());
        $event=FinancialOriginOutbox::sole()->payload;$this->assertSame('sales_receipt',$event['source_document_type']);$this->assertSame(850,$event['source_document_id']);$this->assertSame(851,$event['physical']['lines'][0]['source_document_line_id']);
        $this->assertSame('2.0000',$event['physical']['lines'][0]['quantity']);$this->assertNotEmpty($event['physical']['journal_key']);
        $final=array_replace($op,['operation_uuid'=>(string)Str::uuid()]);$complete=$service->executeNative($final,336);$this->assertSame('complete',$complete['status']);
        $this->assertSame(2,FinancialOriginOutbox::count());$this->assertSame(2,Shipment::count());$this->assertSame('16.0000',StockBalance::sole()->on_hand_qty);
        $this->assertSame(0,SalesDocumentOutbox::count());
    }
    public function test_anonymous_cash_serial_dispatch_retains_null_customer_and_exact_serial_source_links():void
    {
        [,,$op]=$this->admitted(tracking:'serial',anonymous:true);$serials=\App\Models\Tenant\SerialNumber::query()->orderBy('id')->limit(2)->pluck('id')->all();$op['lines'][0]['serial_ids']=$serials;$op['reserve_stock']=true;
        $before=StockLedger::count();app(OriginDispatchService::class)->executeNative($op,336);
        $this->assertNull(\App\Models\Tenant\SalesOrder::sole()->customer_id);$this->assertSame(2,Shipment::sole()->lines()->count());$this->assertSame($before+2,StockLedger::count());
        $event=FinancialOriginOutbox::sole()->payload;$this->assertCount(2,$event['physical']['lines']);foreach($event['physical']['lines']as$line){$this->assertSame(851,$line['source_document_line_id']);$this->assertSame('1.0000',$line['quantity']);}
        $this->assertSame(0,SalesDocumentOutbox::count());
    }
    public function test_stock_only_expense_receipt_uses_native_grn_once_and_never_creates_a_bill_draft():void
    {
        [,,$op]=$this->admitted('expense');
        // A receipt requires its own reviewed native accounting workflow and real GRNI liability mapping.
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->meta=$meta;$setting->save();
        $before=StockLedger::count();$service=app(OriginDispatchService::class);$partial=$service->executeNative($op,336);
        $this->assertSame($partial,$service->executeNative($op,336));$this->assertSame('partial',$partial['status']);$this->assertSame(1,GoodsReceipt::count());$this->assertSame($before+1,StockLedger::count());
        $this->assertSame(0,\App\Models\Tenant\PurchasingDocumentOutbox::count());$event=FinancialOriginOutbox::sole()->payload;
        $this->assertSame('expense',$event['source_document_type']);$this->assertSame('financial-origin.receipt.confirmed',$event['event_type']);$this->assertSame('7.00000000',$event['physical']['lines'][0]['unit_cost']);
        $this->assertSame('22.0000',StockBalance::sole()->on_hand_qty);
    }
    public function test_finance_only_or_unassigned_actor_cannot_move_typed_stock_and_prepared_correction_requires_abandon_ack():void
    {
        [,,$op]=$this->admitted();$service=app(OriginDispatchService::class);$before=StockLedger::count();$this->actor(335,false,false);
        try{$service->executeNative($op,335);$this->fail('Finance-only actor moved stock');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->actor(337);try{$service->executeNative($op,337);$this->fail('Unassigned actor moved stock');}catch(\Illuminate\Auth\Access\AuthorizationException $e){$this->assertNotEmpty($e->getMessage());}
        $this->actor(336);$service->prepareNative($op,336);$changed=$op;$changed['lines'][0]['quantity']='1';
        try{$service->executeNative($changed,336);$this->fail('Prepared command silently changed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame('abandoned',$service->abandonNative($op,336)['status']);
        try{$service->executeNative($op,336);$this->fail('Abandoned command moved stock');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame($before,StockLedger::count());$this->assertSame(0,Shipment::count());$this->assertSame(0,FinancialOriginOutbox::count());
        $this->assertSame(1,FinancialOriginCommand::count());
    }
}
