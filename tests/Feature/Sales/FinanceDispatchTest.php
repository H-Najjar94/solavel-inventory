<?php
namespace Tests\Feature\Sales;

use App\Models\User;
use App\Models\Tenant\{FulfillmentCommand,FulfillmentRequest,IntegrationSetting,InventoryUserWarehouse,Lot,Reservation,SalesDocumentOutbox,SalesOrder,SerialNumber,Shipment,StockBalance,StockLedger};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Sales\{FinanceDispatchService,FulfillmentRequestService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\{SalesHandoffFixture,StockTestFactory as F,TenantTestManager};
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class FinanceDispatchTest extends TestCase
{
    use TenantAware, SalesHandoffFixture;
    private function actor(bool $owner=true, bool $stock=true): void {
        $actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>$stock,'owner'=>$owner,'roles'=>$owner?[]:['warehouse_operator']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
    }
    private function fixture(string $tracking='none',bool $approve=true): array {
        $this->initializeSalesFixture(true,$tracking);$data=$this->data();$this->authority($data);$request=app(FulfillmentRequestService::class)->upsert($data,323);$this->actor();
        if($approve)app(FulfillmentRequestService::class)->approve(FulfillmentRequest::sole(),$this->warehouse->id);
        $this->viewAuthority($data);
        $context=['source_invoice_id'=>800,'request_uuid'=>$data['request_uuid'],'invoice_revision'=>str_repeat('b',64)];
        $operation=$context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-07','lines'=>[['source_invoice_line_id'=>'801','request_line_id'=>$request['lines'][0]['id'],'quantity'=>'2','unit_id'=>$this->unit->id]]];
        return [$data,$context,$operation];
    }
    private function viewAuthority(array $data): void {
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'view',['request_uuid'=>$data['request_uuid']])->andReturn(['allowed'=>true,'request_revision'=>$data['source_revision'],'source_revision'=>str_repeat('b',64)]);
    }
    public function test_prepare_dispatch_partial_final_and_unknown_outcome_replay_use_one_native_shipment_per_command(): void {
        [,,$operation]=$this->fixture();$service=app(FinanceDispatchService::class);$before=StockLedger::count();$one=$service->prepare($operation);
        $this->assertSame('prepared',$one['status']);$this->assertSame(0,Shipment::count());$this->assertSame($before,StockLedger::count());
        $partial=$service->execute($operation);$again=$service->execute($operation);$this->assertSame($partial,$again);$this->assertSame('partial',$partial['fulfillment_request']['status']);$this->assertSame('2.0000',$partial['fulfillment_request']['fulfilled_quantity']);
        $this->assertSame(1,Shipment::count());$this->assertSame($before+1,StockLedger::count());$this->assertSame(1,SalesDocumentOutbox::count());
        $event=SalesDocumentOutbox::sole()->payload;$this->assertSame(800,$event['shipment']['source_invoice_id']);$this->assertSame('801',$event['shipment']['lines'][0]['source_invoice_line_id']);$this->assertSame('partial',$event['shipment']['fulfillment_request']['status']);$this->assertNotEmpty($event['shipment']['journal_idempotency_key']);
        $final=array_replace($operation,['operation_uuid'=>(string)Str::uuid()]);$complete=$service->execute($final);$this->assertSame('complete',$complete['fulfillment_request']['status']);$this->assertCount(2,$complete['fulfillment_request']['shipment_ids']);
        $this->assertSame('16.0000',(string)StockBalance::where('item_id',$this->item->id)->sole()->on_hand_qty);$this->assertSame(2,Shipment::count());$this->assertSame(2,SalesDocumentOutbox::count());
        $tooMuch=array_replace($final,['operation_uuid'=>(string)Str::uuid()]);try{$service->execute($tooMuch);$this->fail('Completed request dispatched again');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame($before+2,StockLedger::count());
    }
    public function test_corrected_command_requires_safe_abandonment_and_the_original_uuid_cannot_execute(): void {
        [,,$operation]=$this->fixture();$service=app(FinanceDispatchService::class);$service->prepare($operation);$before=StockLedger::count();
        $changed=$operation;$changed['lines'][0]['quantity']='1';try{$service->execute($changed);$this->fail('Prepared command silently changed');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame('abandoned',$service->abandon($operation)['status']);try{$service->execute($operation);$this->fail('Abandoned command posted');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $new=$changed;$new['operation_uuid']=(string)Str::uuid();$service->execute($new);$this->assertSame($before+1,StockLedger::count());$this->assertSame(1,Shipment::count());
        $empty=$operation;$empty['operation_uuid']=(string)Str::uuid();$this->assertSame('abandoned',$service->abandon($empty)['status']);$this->assertSame(1,Shipment::count());
    }
    public function test_serial_details_reserve_only_selected_serials_and_split_native_physical_lines(): void {
        [,,$operation]=$this->fixture('serial');$serials=SerialNumber::where('item_id',$this->item->id)->orderBy('id')->get();$operation['lines'][0]['serial_ids']=$serials->take(2)->pluck('id')->all();$operation['reserve_stock']=true;
        $service=app(FinanceDispatchService::class);$before=StockLedger::count();$result=$service->execute($operation);$this->assertSame($before+2,StockLedger::count());$this->assertSame(2,Shipment::sole()->lines()->count());$this->assertSame(2,SerialNumber::where('status','shipped')->count());
        $final=$operation;$final['operation_uuid']=(string)Str::uuid();$final['lines'][0]['serial_ids']=$serials->slice(2,2)->pluck('id')->all();$service->execute($final);$this->assertSame('complete',FulfillmentRequest::sole()->status);$this->assertSame(4,SerialNumber::where('status','shipped')->count());$this->assertSame(0,Reservation::where('status','active')->count());
        $this->assertSame($result['shipment_id'],$service->status($operation)['shipment_id']);
    }
    public function test_lot_and_serial_fields_are_native_required_before_command_publication(): void {
        [$data,,$operation]=$this->fixture('lot');$service=app(FinanceDispatchService::class);$before=StockLedger::count();
        try{$service->prepare($operation);$this->fail('Mandatory lot omitted');}catch(ValidationException $e){$this->assertArrayHasKey('lines.0.lot_id',$e->errors());}$this->assertSame(0,FulfillmentCommand::count());$this->assertSame($before,StockLedger::count());
        $foreign=F::lot(F::averageItem());$operation['lines'][0]['lot_id']=$foreign->id;try{$service->prepare($operation);$this->fail('Foreign item lot accepted');}catch(ValidationException $e){$this->assertArrayHasKey('lines.0.lot_id',$e->errors());}
        $operation['lines'][0]['lot_id']=Lot::where('item_id',$this->item->id)->sole()->id;$service->execute($operation);$this->assertSame($before+1,StockLedger::count());
    }
    public function test_finance_permission_does_not_grant_stock_dispatch_and_unassigned_warehouses_are_hidden(): void {
        [$data,$context,$operation]=$this->fixture();$other=F::warehouse();$this->actor(false);InventoryUserWarehouse::create(['user_id'=>323,'warehouse_id'=>$this->warehouse->id]);
        $options=app(FinanceDispatchService::class)->options($context);$this->assertSame([$this->warehouse->id],array_column($options['warehouses'],'id'));$this->assertTrue($options['can_dispatch']);$this->assertFalse($options['can_approve']);
        $denied=$operation;$denied['warehouse_id']=$other->id;try{app(FinanceDispatchService::class)->prepare($denied);$this->fail('Unassigned warehouse accepted');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->actor(false,false);try{app(FinanceDispatchService::class)->options($context);$this->fail('Finance-only actor got Stock dispatch');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertTrue(FinanceDocumentLifecycleAuthority::covers('sales.request.upsert'));$this->assertFalse(FinanceDocumentLifecycleAuthority::covers('sales.fulfillment.execute'));$this->assertSame(0,Shipment::count());
    }
    public function test_unapproved_request_can_be_explicitly_approved_in_finance_without_reserving_or_shipping(): void {
        [,$context,$operation]=$this->fixture('none',false);$service=app(FinanceDispatchService::class);$before=StockLedger::count();$options=$service->options($context);$this->assertFalse($options['can_dispatch']);$this->assertTrue($options['can_approve']);
        $result=$service->approve($context+['warehouse_id'=>$this->warehouse->id]);$this->assertNotNull($result['sales_order_id']);$this->assertSame(0,Reservation::count());$this->assertSame(0,Shipment::count());$this->assertSame($before,StockLedger::count());$this->assertTrue($service->options($context)['can_dispatch']);
    }
    public function test_cancel_pending_request_preserves_actual_partial_shipment_and_blocks_delayed_dispatch(): void {
        [$data,,$operation]=$this->fixture();$service=app(FinanceDispatchService::class);$partial=$service->execute($operation);$before=StockLedger::count();$cancel=$data+['expected_revision'=>$data['source_revision'],'command'=>'cancel'];
        DB::connection('tenant')->table('finance_sales_requests')->where('request_uuid',$data['request_uuid'])->update(['command'=>'cancel']);
        $client=$this->mock(SolaBooksOutboxDeliveryService::class);$client->shouldReceive('authorizeSales')->with(323,800,'edit_draft',['command'=>'cancel','request_uuid'=>$data['request_uuid'],'source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision']])->andReturn(['command'=>'cancel','request_uuid'=>$data['request_uuid'],'command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision']]);
        $cancelled=app(FulfillmentRequestService::class)->cancel($cancel,323);$this->assertSame('cancelled',$cancelled['status']);$this->assertSame('2.0000',$cancelled['fulfilled_quantity']);$this->assertSame([$partial['shipment_id']],$cancelled['shipment_ids']);
        $this->viewAuthority($data);$next=$operation;$next['operation_uuid']=(string)Str::uuid();try{$service->execute($next);$this->fail('Late dispatch after cancellation accepted');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame($before,StockLedger::count());$this->assertSame(1,Shipment::count());
    }
}
