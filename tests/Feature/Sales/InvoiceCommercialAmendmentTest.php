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

    private function fixture(string $fulfilled = '0',bool $returnPending=false,bool $accepted=true,int $version=1,string $quantity='4',bool $rounding=false): array
    {
        $this->initializeSalesFixture(true);$original=$this->data();
        if($rounding){$original['lines'][0]['quantity']='2';$original['lines'][0]['unit_price']='1.005';$extra=$original['lines'][0];$extra['source_line_id']='802';$original['lines'][]=$extra;}
        $this->authority($original);
        app(FulfillmentRequestService::class)->upsert($original,323);
        $actor=new User;$actor->id=323;Auth::setUser($actor);request()->setUserResolver(fn()=>$actor);
        config(['inventory.demo_tenant.enabled'=>false]);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>true,'roles'=>[]]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        if($accepted)app(FulfillmentRequestService::class)->approve(FulfillmentRequest::sole(),$this->warehouse->id);
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
        $proposal=$original;$proposal['lines'][0]['unit_price']='9';if($version>=2)$proposal['lines'][0]['quantity']=$quantity;if($rounding)foreach($proposal['lines'] as &$line)$line['quantity']=$quantity;unset($line);
        $uuid=(string)Str::uuid();$approved=['actor_id'=>323,'original_request'=>$original,'proposed_request'=>$proposal];if($version>=2)$approved['review']=['inventory'=>['stock'=>['version'=>$version,'amendment_mode'=>$version===3?'accepted_demand':'pending_demand']]];
        $row=['version'=>1,'invoice_id'=>800,'operation_uuid'=>$uuid,'state'=>'queued','approved'=>$approved,'execution'=>[]];
        $id=DB::connection('tenant')->table('finance_sales_events')->insertGetId(['organization_id'=>14,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,
            'source_key'=>'invoice-amendment:'.$uuid,'source_hash'=>hash('sha256',json_encode($approved,JSON_THROW_ON_ERROR)),
            'event_type'=>'sales.invoice_amendment.v1','result'=>json_encode($row),'created_at'=>now(),'updated_at'=>now()]);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->withArgs(fn($actor,$invoice,$permission,$facts)=>$actor===323&&$invoice===800&&$permission==='post'&&$facts['command']==='invoice-amendment'&&$facts['operation_uuid']===$uuid)
            ->andReturnUsing(function($actor,$invoice,$permission,$facts)use($id,$uuid,$version){
                $saved=DB::connection('tenant')->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($saved->result,true);
                $replacement=$value['approved']['proposed_request'];$replacement['source_revision']=str_repeat('b',64);$replacement['posted_invoice_journal_id']=993;
                return ['amendment'=>['version'=>$version,'amendment_mode'=>$version===3?'accepted_demand':($version===2?'pending_demand':'commercial'),'invoice_id'=>800,'operation_uuid'=>$uuid,'phase'=>$facts['purpose'],'approved_hash'=>$saved->source_hash,'state'=>$value['state'],
                    'original_request'=>$value['approved']['original_request'],'proposed_request'=>$value['approved']['proposed_request'],'replacement_request'=>$replacement]];
            });
        $fixture=new CommittedTenantFixture($this->tenantTestManager);$fixture->commit();
        return [['version'=>$version,'source_invoice_id'=>800,'operation_uuid'=>$uuid],$id,$fixture];
    }

    private function state(int $id,string $state,array $execution=[]): void
    {
        $db=DB::connection('tenant');$row=$db->table('finance_sales_events')->where('id',$id)->first();$value=json_decode($row->result,true);
        $value['state']=$state;$value['execution']=array_merge($value['execution'],$execution);$value['version']++;
        $db->table('finance_sales_events')->where('id',$id)->update(['result'=>json_encode($value)]);
    }

    public static function pendingDemandQuantities(): array { return ['increase'=>['6'],'decrease'=>['2']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('pendingDemandQuantities')]
    public function test_pending_demand_quantity_amendment_preserves_stock_and_waits_for_real_warehouse_acceptance(string $quantity): void
    {
        [$input,$id,$fixture]=$this->fixture('0',false,false,2,$quantity);
        try{
            $before=StockLedger::orderBy('id')->get()->toArray();$service=app(InvoiceCommercialAmendmentService::class);
            $prepared=$service->dispatch($input,323,'prepare');self::assertSame(2,$prepared['version']);self::assertSame('prepared',$prepared['status']);
            self::assertEquals(4,FulfillmentRequest::sole()->lines()->sole()->requested_qty);
            $this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\Models\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $done=$service->dispatch($input,323,'commit');self::assertSame('committed',$done['status']);self::assertSame(2,$done['version']);self::assertSame($done,$service->dispatch($input,323,'commit'));
            self::assertSame(0,$done['physical_effects']);self::assertSame($before,StockLedger::orderBy('id')->get()->toArray());self::assertSame(0,SalesOrder::count());self::assertSame(0,\App\Models\Tenant\Shipment::count());
            self::assertSame(0,bccomp($quantity,(string)FulfillmentRequest::sole()->lines()->sole()->requested_qty,4));self::assertSame('pending',$done['receipt']['fulfillment']['status']);
            self::assertSame(0,bccomp($quantity,$done['receipt']['fulfillment']['lines'][0]['requested_quantity'],4));
            $this->state($id,'completed');$request=FulfillmentRequest::sole();
            DB::connection('tenant')->table('finance_sales_requests')->where('invoice_id',800)->update(['source_revision'=>$request->source_revision,'invoice_journal_id'=>993]);
            $accepted=app(FulfillmentRequestService::class)->approve($request,$this->warehouse->id);
            self::assertSame(1,SalesOrder::count());self::assertSame(0,bccomp($quantity,(string)SalesOrder::sole()->lines()->sole()->ordered_qty,4));
            self::assertSame(0,\App\Models\Tenant\Shipment::count());self::assertSame($before,StockLedger::orderBy('id')->get()->toArray());
            self::assertSame($done,$service->dispatch($input,323,'commit'),'Original pending-demand receipt remains immutable after later warehouse acceptance.');
        }finally{$fixture->restore();}
    }

    public static function acceptedAmendments(): array
    {
        return ['accepted increase'=>['0','6','pending'],'accepted decrease'=>['0','2','pending'],
            'partial increase'=>['2','6','partial'],'partial decrease to shipped'=>['2','2','complete'],
            'shipped additional demand'=>['4','6','partial']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedAmendments')]
    public function test_v3_amends_only_outstanding_accepted_demand_and_keeps_physical_evidence(string $fulfilled,string $quantity,string $status): void
    {
        [$input,$id,$fixture]=$this->fixture($fulfilled,false,true,3,$quantity);
        try{
            $service=app(InvoiceCommercialAmendmentService::class);
            $ledger=StockLedger::orderBy('id')->get()->toArray();
            $shipments=\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray();
            $old=SalesOrder::sole()->lines()->sole()->getRawOriginal();
            $prepared=$service->dispatch($input,323,'prepare');self::assertSame(3,$prepared['version']);self::assertSame('prepared',$prepared['status']);
            self::assertEquals(4,SalesOrder::sole()->lines()->sole()->ordered_qty);
            self::assertEquals(4,FulfillmentRequest::sole()->lines()->sole()->requested_qty);
            $this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $done=$service->dispatch($input,323,'commit');self::assertSame('committed',$done['status']);self::assertSame(3,$done['version']);
            self::assertSame($done,$service->dispatch($input,323,'commit'));self::assertSame($done,$service->dispatch($input,323,'prepare'));
            self::assertSame(0,$done['physical_effects']);self::assertSame($status,$done['receipt']['fulfillment']['status']);
            self::assertSame(0,bccomp($quantity,(string)SalesOrder::sole()->lines()->sole()->ordered_qty,4));
            self::assertSame(0,bccomp($quantity,(string)FulfillmentRequest::sole()->lines()->sole()->requested_qty,4));
            self::assertSame(0,bccomp($fulfilled,(string)FulfillmentRequest::sole()->lines()->sole()->fulfilled_qty,4));
            self::assertSame($old['unit_price'],SalesOrder::sole()->lines()->sole()->getRawOriginal('unit_price'));
            self::assertSame($old['shipped_qty'],SalesOrder::sole()->lines()->sole()->getRawOriginal('shipped_qty'));
            self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
            self::assertSame($shipments,\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray());
            $command=FulfillmentCommand::where('operation_uuid',$input['operation_uuid'])->sole();
            self::assertSame($old,$command->payload['accepted_plan']['lines'][0]['before']);
            self::assertSame($status==='complete'?'fulfilled':'pending_fulfillment',$done['receipt']['demand_amendment']['state']);
            if($fulfilled==='4'){
                $this->state($id,'completed');$request=FulfillmentRequest::sole();
                DB::connection('tenant')->table('finance_sales_requests')->where('invoice_id',800)->update(['source_revision'=>$request->source_revision,'invoice_journal_id'=>993]);
                $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,'view',['request_uuid'=>$request->request_uuid])
                    ->andReturn(['allowed'=>true,'request_revision'=>$request->source_revision,'source_revision'=>str_repeat('c',64)]);
                $dispatch=app(\App\Services\Sales\FinanceDispatchService::class)->execute(['source_invoice_id'=>800,'request_uuid'=>$request->request_uuid,'invoice_revision'=>str_repeat('c',64),
                    'operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'ship_date'=>'2026-10-08',
                    'lines'=>[['source_invoice_line_id'=>'801','request_line_id'=>$request->lines()->sole()->id,'quantity'=>'2','unit_id'=>$this->unit->id]]]);
                self::assertSame('posted',$dispatch['status']);self::assertSame(count($shipments)+1,\App\Models\Tenant\Shipment::count());
                self::assertSame('complete',FulfillmentRequest::sole()->status);self::assertSame(0,bccomp('6',(string)FulfillmentRequest::sole()->lines()->sole()->fulfilled_qty,4));
                foreach($ledger as $row)self::assertSame($row,StockLedger::findOrFail($row['id'])->toArray());
            }

        }finally{$fixture->restore();}
    }

    public function test_v3_rejects_reduction_below_shipped_quantity_without_inventing_a_return(): void
    {
        [$input,$id,$fixture]=$this->fixture('2',false,true,3,'1');
        try{
            $before=SalesOrder::with('lines')->get()->toArray();$ledger=StockLedger::orderBy('id')->get()->toArray();$commands=FulfillmentCommand::count();
            try{app(InvoiceCommercialAmendmentService::class)->dispatch($input,323,'prepare');self::fail('A real return is required.');}
            catch(HttpExceptionInterface $failure){self::assertSame(409,$failure->getStatusCode());}
            self::assertSame($before,SalesOrder::with('lines')->get()->toArray());self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
            self::assertSame($commands,FulfillmentCommand::count());self::assertSame(0,\App\Models\Tenant\SalesReturn::count());
        }finally{$fixture->restore();}
    }

    public function test_v3_prefinancial_cancellation_preserves_accepted_order(): void
    {
        [$input,$id,$fixture]=$this->fixture('2',false,true,3,'6');
        try{
            $before=SalesOrder::with('lines')->get()->toArray();$ledger=StockLedger::orderBy('id')->get()->toArray();
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');$this->state($id,'cancel_requested');
            $done=$service->dispatch($input,323,'cancel');self::assertSame('abandoned',$done['status']);self::assertSame($done,$service->dispatch($input,323,'cancel'));
            self::assertSame($before,SalesOrder::with('lines')->get()->toArray());self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
        }finally{$fixture->restore();}
    }

    public function test_v3_stock_failure_rolls_back_order_and_request_and_retry_commits_once(): void
    {
        [$input,$id,$fixture]=$this->fixture('2',false,true,3,'6');
        $dispatcher=FulfillmentRequest::getEventDispatcher();
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');
            $this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $orders=SalesOrder::with('lines')->get()->toArray();$request=FulfillmentRequest::sole()->getRawOriginal();$ledger=StockLedger::orderBy('id')->get()->toArray();
            $injected=clone $dispatcher;$injected->listen('eloquent.updating: '.FulfillmentRequest::class,function($request){if($request->isDirty('source_revision'))throw new \RuntimeException('Injected Stock acknowledgement failure');});
            FulfillmentRequest::setEventDispatcher($injected);
            try{$service->dispatch($input,323,'commit');self::fail('The injected failure was lost.');}
            catch(\RuntimeException $error){self::assertSame('Injected Stock acknowledgement failure',$error->getMessage());}
            finally{FulfillmentRequest::setEventDispatcher($dispatcher);}
            self::assertSame($orders,SalesOrder::with('lines')->get()->toArray());self::assertSame($request,FulfillmentRequest::sole()->getRawOriginal());
            self::assertSame('prepared',FulfillmentCommand::where('operation_uuid',$input['operation_uuid'])->sole()->status);
            self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
            $done=$service->dispatch($input,323,'commit');self::assertSame('committed',$done['status']);self::assertSame($done,$service->dispatch($input,323,'commit'));
            self::assertSame(0,bccomp('6',(string)SalesOrder::sole()->lines()->sole()->ordered_qty,4));self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());
        }finally{FulfillmentRequest::setEventDispatcher($dispatcher);$fixture->restore();}
    }

    public function test_v3_distinct_repeated_amendments_preserve_the_first_receipt_and_original_shipments(): void
    {
        [$input,$id,$fixture]=$this->fixture('2',false,true,3,'6');
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');$this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $first=$service->dispatch($input,323,'commit');$this->state($id,'completed');
            $before=FulfillmentCommand::where('operation_uuid',$input['operation_uuid'])->sole()->getRawOriginal();
            $ledger=StockLedger::orderBy('id')->get()->toArray();$shipments=\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray();
            $request=FulfillmentRequest::sole();$original=$request->source_payload;$proposal=$original;$proposal['lines'][0]['quantity']='5';
            DB::connection('tenant')->table('finance_sales_requests')->where('invoice_id',800)->update(['source_revision'=>$request->source_revision,'invoice_journal_id'=>993]);
            $uuid=(string)Str::uuid();$approved=['actor_id'=>323,'original_request'=>$original,'proposed_request'=>$proposal,'review'=>['inventory'=>['stock'=>['version'=>3,'amendment_mode'=>'accepted_demand']]]];
            $workflow=['version'=>1,'invoice_id'=>800,'operation_uuid'=>$uuid,'state'=>'queued','approved'=>$approved,'execution'=>[]];
            $next=DB::connection('tenant')->table('finance_sales_events')->insertGetId(['organization_id'=>14,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'source_key'=>'invoice-amendment:'.$uuid,
                'source_hash'=>hash('sha256',json_encode($approved,JSON_THROW_ON_ERROR)),'event_type'=>'sales.invoice_amendment.v1','result'=>json_encode($workflow),'created_at'=>now(),'updated_at'=>now()]);
            $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->andReturnUsing(function($actor,$invoice,$permission,$facts)use($next,$uuid){
                self::assertSame(323,$actor);self::assertSame(800,$invoice);self::assertSame('post',$permission);self::assertSame($uuid,$facts['operation_uuid']);
                $row=DB::connection('tenant')->table('finance_sales_events')->where('id',$next)->sole();$value=json_decode($row->result,true);$replacement=$value['approved']['proposed_request'];$replacement['source_revision']=str_repeat('d',64);$replacement['posted_invoice_journal_id']=994;
                return ['amendment'=>['version'=>3,'amendment_mode'=>'accepted_demand','invoice_id'=>800,'operation_uuid'=>$uuid,'phase'=>$facts['purpose'],'approved_hash'=>$row->source_hash,'state'=>$value['state'],'original_request'=>$value['approved']['original_request'],'proposed_request'=>$value['approved']['proposed_request'],'replacement_request'=>$replacement]];
            });
            $second=['version'=>3,'source_invoice_id'=>800,'operation_uuid'=>$uuid];$service->dispatch($second,323,'prepare');$this->state($next,'finance_posted',['replacement_journal_id'=>994]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>994,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $done=$service->dispatch($second,323,'commit');self::assertSame('committed',$done['status']);self::assertSame($done,$service->dispatch($second,323,'commit'));
            self::assertSame(0,bccomp('5',(string)SalesOrder::sole()->lines()->sole()->ordered_qty,4));self::assertSame(0,bccomp('2',(string)FulfillmentRequest::sole()->lines()->sole()->fulfilled_qty,4));
            self::assertSame($before,FulfillmentCommand::where('operation_uuid',$input['operation_uuid'])->sole()->getRawOriginal());
            self::assertSame($first['receipt'],FulfillmentCommand::where('operation_uuid',$input['operation_uuid'])->sole()->payload['receipt']);
            self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());self::assertSame($shipments,\App\Models\Tenant\Shipment::with('lines')->orderBy('id')->get()->toArray());
        }finally{$fixture->restore();}
    }

    public function test_v3_reuses_native_multiline_quotation_rounding_without_changing_warehouse_value(): void
    {
        [$input,$id,$fixture]=$this->fixture('0',false,true,3,'1',true);
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$ledger=StockLedger::orderBy('id')->get()->toArray();
            self::assertSame(0,bccomp('4.02',(string)SalesOrder::sole()->subtotal,2));self::assertSame(0,bccomp('4.02',(string)SalesOrder::sole()->total,2));
            $service->dispatch($input,323,'prepare');$this->state($id,'finance_posted',['replacement_journal_id'=>993]);
            DB::connection('tenant')->table('journal_entries')->insert(['id'=>993,'organization_id'=>14,'source'=>'AR','source_type'=>'App\\Models\\Invoice','source_id'=>800,'status'=>'posted','posted_at'=>now()]);
            $receipt=$service->dispatch($input,323,'commit');self::assertSame('committed',$receipt['status']);
            // Native subtotal rounds gross once; total sums individually rounded lines.
            self::assertSame(0,bccomp('2.01',(string)SalesOrder::sole()->subtotal,2));self::assertSame(0,bccomp('2.02',(string)SalesOrder::sole()->total,2));
            self::assertSame(0,bccomp('2.02',(string)SalesOrder::sole()->lines()->sum('line_total'),2));
            self::assertSame($ledger,StockLedger::orderBy('id')->get()->toArray());self::assertSame(0,\App\Models\Tenant\Shipment::count());
        }finally{$fixture->restore();}
    }

    public function test_v3_requires_warehouse_authority_before_creating_a_hold(): void
    {
        [$input,$id,$fixture]=$this->fixture('0',false,true,3,'6');
        try{
            $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>false,'owner'=>false,'roles'=>[]]);
            $this->app->forgetInstance(InventoryPermissionService::class);$commands=FulfillmentCommand::count();
            try{app(InvoiceCommercialAmendmentService::class)->dispatch($input,323,'prepare');self::fail('Warehouse authority is required for accepted demand.');}
            catch(HttpExceptionInterface $failure){self::assertSame(403,$failure->getStatusCode());}
            self::assertSame($commands,FulfillmentCommand::count());self::assertSame(0,bccomp('4',(string)FulfillmentRequest::sole()->lines()->sole()->requested_qty,4));
        }finally{$fixture->restore();}
    }

    public static function acceptedDemandCases(): array { return ['accepted'=>['0'],'partial'=>['2'],'completed'=>['4']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedDemandCases')]
    public function test_quantity_protocol_rejects_any_accepted_or_dispatched_warehouse_order(string $fulfilled): void
    {
        [$input,$id,$fixture]=$this->fixture($fulfilled,false,true,2,'6');
        try{
            $before=StockLedger::orderBy('id')->get()->toArray();$orders=SalesOrder::with('lines')->get()->toArray();$commands=FulfillmentCommand::count();
            try{app(InvoiceCommercialAmendmentService::class)->dispatch($input,323,'prepare');self::fail('Accepted physical demand was amended without a warehouse workflow.');}
            catch(HttpExceptionInterface $failure){self::assertSame(409,$failure->getStatusCode());}
            self::assertSame($commands,FulfillmentCommand::count());self::assertSame($before,StockLedger::orderBy('id')->get()->toArray());self::assertSame($orders,SalesOrder::with('lines')->get()->toArray());
        }finally{$fixture->restore();}
    }

    public function test_cancelled_pending_demand_never_changes_quantities_or_warehouse_evidence(): void
    {
        [$input,$id,$fixture]=$this->fixture('0',false,false,2,'6');
        try{
            $service=app(InvoiceCommercialAmendmentService::class);$service->dispatch($input,323,'prepare');$this->state($id,'cancel_requested');
            $done=$service->dispatch($input,323,'cancel');self::assertSame('abandoned',$done['status']);self::assertSame(2,$done['version']);self::assertSame($done,$service->dispatch($input,323,'cancel'));
            self::assertEquals(4,FulfillmentRequest::sole()->lines()->sole()->requested_qty);self::assertSame(0,SalesOrder::count());
        }finally{$fixture->restore();}
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

    public static function concurrentAmendmentVersions(): array { return ['commercial'=>[1],'accepted demand'=>[3]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('concurrentAmendmentVersions')]
    public function test_concurrent_amendment_publication_serializes_native_warehouse_dispatch(int $version): void
    {
        [$input,$id,$fixture]=$this->fixture('0',false,true,$version,'6');$db=DB::connection('tenant');$other=null;
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
