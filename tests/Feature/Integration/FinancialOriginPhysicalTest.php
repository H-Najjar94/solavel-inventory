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
        $native=StockLedger::query()->where('source_type',GoodsReceipt::class)->where('source_id',GoodsReceipt::sole()->id)->sole();
        $this->assertSame($native->id,$event['physical']['lines'][0]['stock_ledger_id']);
        $this->assertSame((string)$native->total_cost,$event['physical']['lines'][0]['stock_value_base']);
        $this->assertSame(\App\Services\Stock\Support\Decimal::MONEY_SCALE,$event['physical']['lines'][0]['stock_money_scale']);
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

    /** Explicit Finance closure projection seam; Stock GRN, inverse ledger and immutable events are native. */
    private function reversedExpenseFixture():GoodsReceipt
    {
        $this->useTenantA();$db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        if(!$schema->hasTable('expenses'))$schema->create('expenses',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('customer_id')->nullable();$t->unsignedBigInteger('vendor_id')->nullable();});
        if(!$schema->hasTable('finance_document_requests'))$schema->create('finance_document_requests',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        $add=function($table,$name,$callback)use($schema){if(!$schema->hasColumn($table,$name))$schema->table($table,fn($t)=>$callback($t,$name));};
        $add('expenses','status',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','state',fn($t,$n)=>$t->string($n)->nullable());
        foreach(['source_key']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->string($n)->nullable());
        foreach(['posted_at','voided_at','deleted_at']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->timestamp($n)->nullable());
        foreach(['voided_by','reverses_entry_id']as$n)$add('journal_entries',$n,fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        if(!$schema->hasTable('users'))$schema->create('users',function($t){$t->id();$t->unsignedBigInteger('central_user_id');});
        if(!$schema->hasTable('action_logs'))$schema->create('action_logs',function($t){$t->id();$t->string('controller');$t->string('method');$t->unsignedBigInteger('user_id');$t->json('data');});
        if(!$schema->hasTable('finance_document_positions'))$schema->create('finance_document_positions',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('position_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->unsignedBigInteger('source_document_line_id');});
        if(!$schema->hasTable('finance_document_matches'))$schema->create('finance_document_matches',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('request_uuid');$t->uuid('operation_uuid');$t->uuid('position_uuid');$t->uuid('reversal_operation_uuid');$t->unsignedBigInteger('source_document_line_id');$t->unsignedBigInteger('journal_entry_id');$t->unsignedBigInteger('reversal_journal_id');$t->string('state');$t->string('reverse_state');$t->string('closure_state');$t->json('closure_snapshot');});
        $this->tenantTestManager->cleanup();
        [,,$op]=$this->admitted('expense');
        // Tenant re-entry replaces the PDO; never write closure facts through the retired DDL connection.
        $db=DB::connection('tenant');
        $setting=\App\Models\Tenant\IntegrationSetting::sole();$meta=$setting->meta;
        $meta['transport_enabled_workflows']=array_merge($meta['transport_enabled_workflows'],['grn.posted','grn.reversed']);$setting->meta=$meta;$setting->save();
        app(OriginDispatchService::class)->executeNative($op,336);$this->actor(323,true);
        $r=FinancialOriginRequest::sole();$r->update(['status'=>'cancelled']);
        $db->table('finance_document_requests')->where('request_uuid',$r->request_uuid)->update(['command'=>'cancel','state'=>'cancelled']);
        $db->table('expenses')->where('id',850)->update(['status'=>'draft']);
        $at='2026-10-07 12:00:00';$db->table('journal_entries')->where('id',95)->update(['status'=>'voided','voided_at'=>$at,'voided_by'=>701]);
        $db->table('users')->insert(['id'=>701,'central_user_id'=>323]);
        $db->table('action_logs')->insert(['id'=>701,'controller'=>'ReversalEngine','method'=>'unpostExpense','user_id'=>701,'data'=>json_encode(['document_type'=>'App\\Models\\Expense','document_id'=>850,'voided_journal_entry_id'=>95])]);
        $position=(string)Str::uuid();$match=(string)Str::uuid();$reverse=(string)Str::uuid();
        $db->table('finance_document_positions')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'position_uuid'=>$position,'side'=>'purchase','source_document_type'=>'expense','source_document_id'=>850,'source_journal_id'=>95,'source_document_line_id'=>851]);
        foreach([96,97]as$id)$db->table('journal_entries')->insert(['id'=>$id,'organization_id'=>14,'source'=>'FINANCIAL-ORIGIN','source_type'=>'App\\Models\\Expense','source_id'=>850,'source_key'=>($id===96?'financial-origin-match:':'financial-origin-match-reversal:').$match,'status'=>'posted','posted_at'=>$at,'reverses_entry_id'=>$id===97?96:null]);
        $snapshot=['phase'=>'expense_unposted_after_value_ack','request_uuid'=>$r->request_uuid,'source_revision'=>$r->source_revision,'reversal_operation_uuid'=>$reverse,
            'original_source_journal_id'=>95,'original_match_journal_id'=>96,'match_inverse_journal_id'=>97,'unpost_audit_id'=>701,'unpost_actor_id'=>701,
            'unpost_central_actor_id'=>323,'unpost_audit_controller'=>'ReversalEngine','unpost_audit_method'=>'unpostExpense','source_document_type'=>'App\\Models\\Expense',
            'source_document_id'=>850,'source_status'=>'draft','source_voided_by'=>701,'source_voided_at'=>$at];
        $db->table('finance_document_matches')->insert(['organization_id'=>14,'request_uuid'=>$r->request_uuid,'operation_uuid'=>$match,'position_uuid'=>$position,
            'reversal_operation_uuid'=>$reverse,'source_document_line_id'=>851,'journal_entry_id'=>96,'reversal_journal_id'=>97,'state'=>'reversed','reverse_state'=>'committed','closure_state'=>'completed','closure_snapshot'=>json_encode($snapshot)]);
        $this->mock(\App\Services\Integration\SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOriginPhysicalReversal')->andReturnUsing(fn($facts)=>$facts+[
            'allowed'=>true,'actor_id'=>0,'authority_kind'=>'posted_financial_origin_physical_reversal','finance_organization_id'=>14,'central_organization_id'=>$this->mapping->central_organization_id,
            'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'matches'=>[['operation_uuid'=>$match,'position_uuid'=>$position,'closure_state'=>'completed',
                'closure_snapshot_hash'=>\App\Services\Integration\SolaStockJournalContract::payloadHash($snapshot)]]]);
        $grn=GoodsReceipt::sole();
        // This case runs alone in a fresh disposable schema: commit the native fixture baseline
        // so the actual remote-proof phase is genuinely outside every SQL transaction.
        while($db->transactionLevel()>0)$db->commit();
        return $grn;
    }

    public function test_typed_expense_native_inverse_and_repeat_preserve_gross_history_and_emit_no_bill():void
    {
        $grn=$this->reversedExpenseFixture();$before=StockLedger::count();$service=app(\App\Services\Documents\InventoryReversalService::class);
        $inverse=$service->reverseGoodsReceipt($grn,'QA exact typed audited reversal');
        $this->assertSame($inverse->id,$service->reverseGoodsReceipt($grn->fresh(),'QA repeat')->id);
        $this->assertSame($before+1,StockLedger::count());$this->assertSame('20.0000',StockBalance::sole()->on_hand_qty);
        $this->assertSame('cancelled',FinancialOriginRequest::sole()->status);$this->assertSame('2.0000',FinancialOriginRequest::sole()->lines()->sole()->fulfilled_quantity);
        $this->assertSame(2,FinancialOriginOutbox::count());$this->assertSame(0,\App\Models\Tenant\PurchasingDocumentOutbox::count());
        $event=FinancialOriginOutbox::query()->where('event_type','financial-origin.receipt.reversed')->sole()->payload;
        $this->assertSame(850,$event['source_document_id']);$this->assertSame(95,$event['source_journal_id']);$this->assertSame($inverse->id,$event['reversal']['id']);
        $this->assertNotEmpty($event['original_payload_hash']);$this->assertNotEmpty($event['reversal']['journal_key']);
        $original=FinancialOriginOutbox::query()->where('event_type','financial-origin.receipt.confirmed')->sole()->payload;
        $this->assertSame($original['physical']['lines'][0]['stock_ledger_id'],$event['physical']['lines'][0]['stock_ledger_id']);
        $this->assertSame($original['physical']['lines'][0]['stock_value_base'],$event['physical']['lines'][0]['stock_value_base']);
    }

    public function test_typed_expense_inverse_rejects_uncertain_cost_ack_and_foreign_audit_without_movement():void
    {
        $grn=$this->reversedExpenseFixture();$db=DB::connection('tenant');$before=StockLedger::count();
        $db->table('finance_document_matches')->update(['reverse_state'=>'pending']);
        try{app(\App\Services\Documents\InventoryReversalService::class)->reverseGoodsReceipt($grn,'QA pending');$this->fail('Uncertain valuation allowed reversal');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $db->table('finance_document_matches')->update(['reverse_state'=>'committed']);
        $db->table('action_logs')->where('id',701)->update(['data'=>json_encode(['document_type'=>'App\\Models\\Expense','document_id'=>999,'voided_journal_entry_id'=>95])]);
        try{app(\App\Services\Documents\InventoryReversalService::class)->reverseGoodsReceipt($grn,'QA foreign audit');$this->fail('Foreign audit allowed reversal');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame($before,StockLedger::count());$this->assertNull($grn->fresh()->reversal_id);$this->assertSame(1,FinancialOriginOutbox::count());
    }
}
