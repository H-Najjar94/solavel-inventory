<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Models\Tenant\{FulfillmentCommand,FulfillmentRequest,SalesOrder,StockLedger};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Sales\{FulfillmentRequestService,InvoiceCommercialAmendmentService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\{CommittedTenantFixture,SalesHandoffFixture};
use Tests\Traits\TenantAware;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class InvoiceCommercialAmendmentTest extends TestCase
{
    use TenantAware,SalesHandoffFixture;

    private function fixture(string $fulfilled = '0',bool $returnPending=false): array
    {
        $this->initializeSalesFixture(true);$original=$this->data();$this->authority($original);
        app(FulfillmentRequestService::class)->upsert($original,323);
        $actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        config(['inventory.demo_tenant.enabled'=>false]);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        app(FulfillmentRequestService::class)->approve(FulfillmentRequest::sole(),$this->warehouse->id);
        $original['source_status']='posted';$original['posted_invoice_journal_id']=71;
        FulfillmentRequest::sole()->update(['source_status'=>'posted','posted_invoice_journal_id'=>71,'source_payload'=>$original]);
        DB::connection('tenant')->table('finance_sales_requests')->where('invoice_id',800)->update(['invoice_journal_id'=>71]);
        DB::connection('tenant')->table('journal_entries')->insert(['id'=>71,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
        if(bccomp($fulfilled,'0',4)>0){
            $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'view',['request_uuid'=>$original['request_uuid']])
                ->andReturn(['allowed'=>true,'request_revision'=>$original['source_revision'],'source_revision'=>str_repeat('b',64)]);
            app(\App\Services\Sales\FinanceDispatchService::class)->execute(['source_invoice_id'=>800,'request_uuid'=>$original['request_uuid'],'invoice_revision'=>str_repeat('b',64),
                'operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-07',
                'lines'=>[['source_invoice_line_id'=>'801','request_line_id'=>FulfillmentRequest::sole()->lines()->sole()->id,'quantity'=>$fulfilled,'unit_id'=>$this->unit->id]]]);
        }
        if($returnPending){
            $shipment=\App\Models\Tenant\Shipment::sole();
            app(\App\Services\Documents\SalesReturnService::class)->createDraft(['return_number'=>'SYNTHETIC-RMA','shipment_id'=>$shipment->id,
                'customer_id'=>$shipment->customer_id,'warehouse_id'=>$shipment->warehouse_id,'return_date'=>'2026-10-07','reason'=>'Review confirmed source return'],[]);
        }
        $proposal=$original;$proposal['lines'][0]['unit_price']='9';
        $uuid=(string)Str::uuid();$approved=['actor_id'=>323,'original_request'=>$original,'proposed_request'=>$proposal];
        $row=['version'=>1,'invoice_id'=>800,'operation_uuid'=>$uuid,'state'=>'queued','approved'=>$approved,'execution'=>[]];
        $id=DB::connection('tenant')->table('finance_sales_events')->insertGetId(['organization_id'=>14,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,
            'source_key'=>'invoice-amendment:'.$uuid,'source_hash'=>hash('sha256',json_encode($approved,JSON_THROW_ON_ERROR)),
            'event_type'=>'sales.invoice_amendment.v1','result'=>json_encode($row),'created_at'=>now(),'updated_at'=>now()]);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->withArgs(fn($actor,$invoice,$permission,$facts)=>$actor===323&&$invoice===800&&$permission==='post'&&$facts['command']==='invoice-amendment'&&$facts['operation_uuid']===$uuid)
            ->andReturnUsing(function($actor,$invoice,$permission,$facts)use($id,$uuid){
                $saved=DB::connection('tenant')->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($saved->result,true);
                $replacement=$value['approved']['proposed_request'];$replacement['source_revision']=str_repeat('b',64);$replacement['posted_invoice_journal_id']=993;
                return ['amendment'=>['version'=>1,'invoice_id'=>800,'operation_uuid'=>$uuid,'phase'=>$facts['purpose'],'approved_hash'=>$saved->source_hash,'state'=>$value['state'],
                    'original_request'=>$value['approved']['original_request'],'proposed_request'=>$value['approved']['proposed_request'],'replacement_request'=>$replacement]];
            });
        $fixture=new CommittedTenantFixture($this->tenantTestManager);$fixture->commit();
        return [['version'=>1,'source_invoice_id'=>800,'operation_uuid'=>$uuid],$id,$fixture];
    }

    private function state(int $id,string $state,array $execution=[]): void
    {
        $db=DB::connection('tenant');$row=$db->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($row->result,true);
        $value['state']=$state;$value['execution']=array_merge($value['execution'],$execution);$value['version']++;
        $db->table('finance_sales_events')->where('id',$id)->update(['result'=>json_encode($value)]);
    }

    public function test_prepare_commit_and_unknown_outcome_replay_preserve_stock_and_original_order_prices(): void
    {
        [$input,$id,$fixture]=$this->fixture();
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$ledger=StockLedger::count();$order=SalesOrder::sole()->fresh('lines')->toArray();
            $first=$service->dispatch($input,323,'prepare');self::assertSame('prepared',$first['status']);self::assertSame($first,$service->dispatch($input,323,'prepare'));
            $this->state($id,'stock_prepared');$this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $done=$service->dispatch($input,323,'commit');self::assertSame('committed',$done['status']);self::assertSame($done,$service->dispatch($input,323,'commit'));
            self::assertSame(0,$done['physical_effects']);self::assertSame($ledger,StockLedger::count());self::assertSame($order,SalesOrder::sole()->fresh('lines')->toArray());
            self::assertSame(str_repeat('b',64),FulfillmentRequest::sole()->source_revision);self::assertEquals(9,FulfillmentRequest::sole()->lines()->sole()->unit_price);
            self::assertSame(1,FulfillmentCommand::count());self::assertSame('committed',$service->dispatch($input,323,'status')['status']);
            $this->state($id,'completed');$db=DB::connection('tenant');$later=$db->table('finance_sales_events')->where('id',$id)->first();$next=json_decode($later->result,true);$nextUuid=(string)Str::uuid();$next['operation_uuid']=$nextUuid;$next['state']='queued';
            $db->table('finance_sales_events')->insert(['organization_id'=>14,'organization_mapping_uuid'=>$later->organization_mapping_uuid,'source_key'=>'invoice-amendment:'.$nextUuid,'source_hash'=>$later->source_hash,'event_type'=>$later->event_type,'result'=>json_encode($next),'created_at'=>now(),'updated_at'=>now()]);
            self::assertSame($done,$service->dispatch($input,323,'commit'),'A later amendment must not invalidate an immutable completed receipt.');

        }finally{$fixture->restore();}
    }

    public static function shipmentCases(): array {return ['partial'=>['2'],'completed'=>['4']];}

    #[\PHPUnit\Framework\Attributes\DataProvider('shipmentCases')]
    public function test_commercial_amendment_preserves_real_shipments_and_cogs(string $fulfilled): void
    {
        [$input,$id,$fixture]=$this->fixture($fulfilled);
        try {
            $ledger=StockLedger::orderBy('id')->get()->toArray();
            $shipments=\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray();
            $order=SalesOrder::sole()->fresh('lines')->toArray();
            self::assertCount(1,$shipments);
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');
            $this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $receipt=$service->dispatch($input,323,'commit');self::assertSame('committed',$receipt['status']);
            self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
            self::assertSame($shipments,\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray());
            self::assertSame($order,SalesOrder::sole()->fresh('lines')->toArray());
            self::assertSame(0,bccomp($fulfilled,$receipt['receipt']['fulfillment']['fulfilled_quantity'],6));
            self::assertSame($receipt,$service->dispatch($input,323,'commit'));
        } finally {$fixture->restore();}
    }

    public function test_unreconciled_native_return_blocks_amendment_before_a_hold_or_financial_posting(): void
    {
        [$input,$id,$fixture]=$this->fixture('2',true);
        try {
            $count=FulfillmentCommand::count();$ledger=StockLedger::orderBy('id')->get()->toArray();
            try{app(InvoiceCommercialAmendmentService::class)->dispatch($input,323,'prepare');self::fail('Unreconciled return was ignored.');}
            catch(HttpExceptionInterface $failure){self::assertSame(409,$failure->getStatusCode());}
            self::assertSame($count,FulfillmentCommand::count());self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
        }finally{$fixture->restore();}
    }

    public function test_concurrent_amendment_publication_serializes_native_warehouse_dispatch(): void
    {
        [$input,$id,$fixture]=$this->fixture();$db=DB::connection('tenant');$other=null;
        try {
            $saved=$db->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($saved->result,true);$value['state']='completed';
            $db->table('finance_sales_events')->where('id',$id)->update(['result'=>json_encode($value)]);
            $request=FulfillmentRequest::sole();
            $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'view',['request_uuid'=>$request->request_uuid])->andReturn(['allowed'=>true,'request_revision'=>$request->source_revision,'source_revision'=>str_repeat('b',64)]);
            $operation=['source_invoice_id'=>800,'request_uuid'=>$request->request_uuid,'invoice_revision'=>str_repeat('b',64),'operation_uuid'=>(string)Str::uuid(),
                'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-07','lines'=>[['source_invoice_line_id'=>'801','request_line_id'=>$request->lines()->sole()->id,'quantity'=>'2','unit_id'=>$this->unit->id]]];
            $config=config('database.connections.tenant');self::assertNotEmpty($config['unix_socket']);
            $other=new \PDO('mysql:unix_socket='.$config['unix_socket'].';dbname='.$config['database'],$config['username'],$config['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $before=StockLedger::count();$db->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $other->beginTransaction();$other->query('SELECT id FROM organizations WHERE id=14 FOR UPDATE')->fetch();$other->query('SELECT id FROM invoices WHERE id=800 FOR UPDATE')->fetch();
            $value['state']='queued';$update=$other->prepare('UPDATE finance_sales_events SET result=? WHERE id=?');$update->execute([json_encode($value),$id]);
            try{app(\App\Services\Sales\FinanceDispatchService::class)->execute($operation);self::fail('Dispatch skipped the concurrent invoice barrier.');}
            catch(\Throwable $failure){self::assertStringContainsString('Lock wait timeout',$failure->getMessage());}
            $other->commit();
            try{app(\App\Services\Sales\FinanceDispatchService::class)->execute($operation);self::fail('Dispatch ignored the newly committed amendment.');}
            catch(\Illuminate\Validation\ValidationException $failure){self::assertNotEmpty($failure->errors());}
            self::assertSame($before,StockLedger::count());self::assertSame(0,\App\Models\Tenant\Shipment::count());self::assertSame(0,FulfillmentCommand::count());
        } finally {if($other?->inTransaction())$other->rollBack();$db->statement('SET SESSION innodb_lock_wait_timeout = 30');$fixture->restore();}
    }

    public function test_finance_only_actor_can_acknowledge_exact_commercial_evidence_without_warehouse_permission(): void
    {
        [$input,$id,$fixture]=$this->fixture('2');
        try {
            $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>false,'owner'=>false,'roles'=>[]]);
            $this->app->forgetInstance(InventoryPermissionService::class);
            self::assertSame(0,SalesOrder::count(),'Ordinary warehouse browsing must remain denied.');
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');$this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $receipt=$service->dispatch($input,323,'commit');self::assertSame('committed',$receipt['status']);self::assertCount(1,$receipt['receipt']['fulfillment']['shipment_ids']);
            self::assertSame(0,SalesOrder::count());self::assertFalse(app(InventoryPermissionService::class)->can(Auth::user(),'inventory.manage_shipments'));
        }finally{$fixture->restore();}
    }

    public function test_cancel_is_idempotent_before_financial_posting_and_does_not_invent_a_return(): void
    {
        [$input,$id,$fixture]=$this->fixture();
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$ledger=StockLedger::count();$source=FulfillmentRequest::sole()->source_payload;
            $service->dispatch($input,323,'prepare');$this->state($id,'cancel_requested');
            $cancel=$service->dispatch($input,323,'cancel');self::assertSame('abandoned',$cancel['status']);self::assertSame($cancel,$service->dispatch($input,323,'cancel'));
            self::assertSame($ledger,StockLedger::count());self::assertSame($source,FulfillmentRequest::sole()->source_payload);
        }finally{$fixture->restore();}
    }

    public function test_physical_change_is_rejected_before_a_commercial_hold_is_created(): void
    {
        [$input,$id,$fixture]=$this->fixture();
        try{
            $db=DB::connection('tenant');$row=$db->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($row->result,true);$value['approved']['proposed_request']['lines'][0]['quantity']='5';
            $db->table('finance_sales_events')->where('id',$id)->update(['result'=>json_encode($value),'source_hash'=>hash('sha256',json_encode($value['approved'],JSON_THROW_ON_ERROR))]);
            try{app(InvoiceCommercialAmendmentService::class)->dispatch($input,323,'prepare');self::fail('Physical increase used the commercial protocol.');}
            catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
            self::assertSame(0,FulfillmentCommand::count());
        }finally{$fixture->restore();}
    }

    public function test_pending_amendment_blocks_order_mutations_before_order_state_changes(): void
    {
        [$input,$id,$fixture]=$this->fixture();
        try {
            $order=SalesOrder::sole();$before=$order->fresh('lines')->toArray();
            $service=app(\App\Services\Documents\SalesOrderService::class);
            foreach (['updateDraft','confirm','releaseReservation','cancel'] as $method) {
                try {
                    $method==='updateDraft' ? $service->updateDraft($order,[],[]) : $service->{$method}($order);
                    self::fail('Pending correction allowed '.$method);
                } catch (\Illuminate\Validation\ValidationException $failure) {
                    self::assertNotEmpty($failure->errors(),$method);
                }
                self::assertSame($before,$order->fresh('lines')->toArray(),$method);
            }
        } finally {$fixture->restore();}
    }

    public function test_commit_cannot_run_before_the_confirmed_local_financial_stage(): void
    {
        [$input,$id,$fixture]=$this->fixture();
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');
            try{$service->dispatch($input,323,'commit');self::fail('Commit skipped local financial posting.');}
            catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
            self::assertSame('prepared',FulfillmentCommand::sole()->status);self::assertSame(str_repeat('a',64),FulfillmentRequest::sole()->source_revision);
        }finally{$fixture->restore();}
    }
}
