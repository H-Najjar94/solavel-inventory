<?php
namespace Tests\Feature\Integration;

use App\Models\User;
use App\Models\Tenant\{FinancialOriginOutbox,GoodsReceipt,IntegrationAccountMapping,IntegrationSetting,InventoryUserWarehouse,StockLedger,Supplier};
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Services\FinancialOrigins\{OriginDispatchService,OriginReceiptCostAuthority,OriginRequestService};
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\{PurchaseCostAdjustmentPlanner,PurchaseCostAdjustmentService};
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Support\Str;
use Tests\Support\FinancialOriginFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native Stock receipt/valuation, explicit persisted Finance187/JEs and remote source authorization seams. */
final class FinancialOriginReceiptCostTest extends TestCase
{
    use TenantAware,FinancialOriginFixture;

    private function actor(int $id,bool $owner=false):void
    {
        config(['inventory.demo_tenant.enabled'=>false]);$u=new User;$u->id=$id;Auth::setUser($u);request()->setUserResolver(fn()=>$u);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>$owner,'roles'=>$owner?[]:['warehouse_operator']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
    }
    private function fixture():array
    {
        $this->useTenantA();$db=DB::connection('tenant');$schema=$db->getSchemaBuilder();
        if(!$schema->hasTable('expenses'))$schema->create('expenses',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('vendor_id')->nullable();});
        if(!$schema->hasTable('finance_document_requests'))$schema->create('finance_document_requests',function($t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        (require base_path('tests/Support/FinancialOriginCostProjectionSchema.php'))->up();
        $add=function($table,$name,$callback)use($schema){if(!$schema->hasColumn($table,$name))$schema->table($table,fn($t)=>$callback($t,$name));};
        $add('expenses','journal_entry_id',fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        $add('expenses','posted_at',fn($t,$n)=>$t->timestamp($n)->nullable());
        $add('expenses','status',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','state',fn($t,$n)=>$t->string($n)->nullable());
        $add('finance_document_requests','response',fn($t,$n)=>$t->json($n)->nullable());
        $add('journal_entries','source_key',fn($t,$n)=>$t->string($n)->nullable());
        $add('journal_entries','reverses_entry_id',fn($t,$n)=>$t->unsignedBigInteger($n)->nullable());
        // DDL commits native fixture transactions; reacquire tenant context before all business fixture rows.
        $this->tenantTestManager->cleanup();$this->initializeOriginFixture(true);$db=DB::connection('tenant');
        $s=Supplier::create(['code'=>'QA-COST-EXPENSE','name'=>'QA cost Expense','is_active'=>true]);$this->master('supplier',$s->id,704);
        $db->table('accounts')->insert(['id'=>300,'organization_id'=>14,'code'=>'300','name'=>'GRNI','type'=>'liability','is_active'=>true,'is_postable'=>true]);
        $a=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>'grni','solabooks_account_id'=>300,'status'=>'verified']);$this->master('account_role',$a->id,300);
        $d=$this->typed('expense');$this->proof($d);$r=app(OriginRequestService::class)->upsert($d,323);
        $context=array_intersect_key($d,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid']))+['request_revision'=>$d['source_revision']];
        $this->actor(323,true);app(OriginRequestService::class)->approveNative($context+['warehouse_id'=>$this->warehouse->id],323);
        InventoryUserWarehouse::create(['user_id'=>336,'warehouse_id'=>$this->warehouse->id,'assigned_by'=>323]);$this->actor(336);
        $setting=IntegrationSetting::sole();$meta=$setting->meta;$meta['transport_enabled_workflows'][]='grn.posted';$setting->meta=$meta;$setting->save();
        app(OriginDispatchService::class)->executeNative($context+['operation_uuid'=>(string)Str::uuid(),'warehouse_id'=>$this->warehouse->id,'physical_date'=>'2026-10-07','lines'=>[['request_line_id'=>$r['lines'][0]['id'],'source_document_line_id'=>851,'quantity'=>'2','unit_id'=>$this->unit->id]]],336);
        $db=DB::connection('tenant');$event=FinancialOriginOutbox::sole()->payload;$physical=$event['physical'];$line=$physical['lines'][0];$grn=GoodsReceipt::sole();$native=$grn->lines()->sole();
        $db->table('expenses')->where('id',850)->update(['journal_entry_id'=>95,'posted_at'=>now(),'status'=>'posted']);
        $db->table('journal_entries')->where('id',95)->update(['posted_at'=>now()]);
        $db->table('journal_entries')->insert(['id'=>96,'organization_id'=>14,'source_type'=>'App\\Models\\StockJournal','source_id'=>$grn->id,'source_key'=>'external-api:'.hash('sha256',$physical['journal_key']),'status'=>'posted','posted_at'=>now()]);
        $position=(string)Str::uuid();$operation=(string)Str::uuid();$ledger=StockLedger::findOrFail($line['stock_ledger_id']);
        $snapshot=['finance_money_scale'=>2,'source'=>['type'=>'expense','id'=>850,'journal_id'=>95,'line_id'=>851,'quantity'=>'4','net_amount'=>'40','nonrecoverable_tax_amount'=>'0','booked_acquisition_base'=>'40','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1'],
            'receipt'=>['mapping_uuid'=>$physical['mapping_uuid'],'id'=>$grn->id,'line_id'=>$native->id,'imported_journal_id'=>96,'journal_key'=>$physical['journal_key'],'journal_event_uuid'=>$physical['journal_event_uuid'],'journal_payload_hash'=>$physical['journal_payload_hash'],'quantity'=>'2','base_quantity'=>'2','unit_conversion_factor'=>(string)$native->unit_conversion_factor,'unit_conversion_hash'=>$native->unit_conversion_hash,'unit_cost'=>'7','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1','stock_ledger_id'=>$ledger->id,'stock_money_scale'=>2,'source_quantity_base'=>(string)$ledger->quantity,'source_value_base'=>(string)$ledger->total_cost,'original_receipt_base_amount'=>(string)$ledger->total_cost],
            'price'=>['desired_acquisition_at_receipt_base'=>'20','price_delta_base'=>'6','booked_fx_difference_base'=>'0']];
        $db->table('finance_document_positions')->insert(['organization_id'=>14,'request_uuid'=>$d['request_uuid'],'position_uuid'=>$position,'side'=>'purchase','source_document_type'=>'expense','source_document_id'=>850,'source_journal_id'=>95,'source_document_line_id'=>851,'inventory_item_id'=>701,'entered_unit_id'=>702,'quantity'=>'4','booked_net_base'=>'40','booked_acquisition_base'=>'40','holding_account_id'=>300,'destination_account_id'=>100,'snapshot'=>json_encode($snapshot['source'])]);
        $db->table('finance_document_matches')->insert(['organization_id'=>14,'request_uuid'=>$d['request_uuid'],'operation_uuid'=>$operation,'position_uuid'=>$position,'physical_mapping_uuid'=>$physical['mapping_uuid'],'physical_document_id'=>$grn->id,'physical_line_id'=>$native->id,'source_document_line_id'=>851,'physical_journal_id'=>96,'quantity'=>'2','base_quantity'=>'2','unit_conversion_factor'=>$native->unit_conversion_factor,'unit_conversion_hash'=>$native->unit_conversion_hash,'booked_base'=>'20','physical_cost_base'=>$ledger->total_cost,'state'=>'journal_pending','snapshot'=>json_encode($snapshot)]);
        $id=['source_document_id'=>850,'source_journal_id'=>95,'request_uuid'=>$d['request_uuid'],'source_revision'=>$d['source_revision'],'operation_uuid'=>$operation,'position_uuid'=>$position,'direction'=>'forward'];
        $proof=$id+['allowed'=>true,'schema_version'=>'financial-origin.v1','authority_kind'=>'posted_financial_origin_settlement','source_document_type'=>'expense','finance_organization_id'=>14,'central_organization_id'=>$this->mapping->central_organization_id,'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'operation'=>'prepare','actor_id'=>0,'match_state'=>'journal_pending','snapshot_hash'=>SolaStockJournalContract::payloadHash($snapshot),'finance_money_scale'=>2,'source_document_line_id'=>851,'value_plan_hash'=>null];
        return [$id,$proof,$snapshot];
    }
    public function test_actual_expense_receipt_quote_uses_native_whole_ledger_and_preserves_quantity_and_financial_rows():void
    {
        [$id,$proof]=$this->fixture();$db=DB::connection('tenant');$before=StockLedger::count();$journals=$db->table('journal_entries')->count();
        $authority=OriginReceiptCostAuthority::fromLockedNativeProvenance($id,$proof,$this->mapping,'prepare',0);
        $plan=app(PurchaseCostAdjustmentPlanner::class)->planFinancialOrigin($authority);
        $this->assertSame('6.00000000',$plan['exact_base_difference']);$this->assertSame('expense',$plan['source_document_type']);$this->assertSame(850,$plan['source_document_id']);
        $this->assertSame('2.00000000',$authority->sourceAllocations()[0]['quantity_base']);
        $row=app(PurchaseCostAdjustmentService::class)->prepareFinancialOrigin($authority,$plan);
        $this->assertSame('prepared',$row['state']);$this->assertSame($before,StockLedger::count());$this->assertSame($journals,$db->table('journal_entries')->count());
    }
    public function test_caller_snapshot_or_partial_native_ledger_allocation_cannot_authorize_expense_value():void
    {
        [$id,$proof,$snapshot]=$this->fixture();
        try {OriginReceiptCostAuthority::fromLockedNativeProvenance($id,array_replace($proof,['actor_id'=>335]),$this->mapping,'prepare',335);$this->fail('Human actor borrowed service settlement');}
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {$this->assertSame(403,$e->getStatusCode());}
        $snapshot['receipt']['source_quantity_base']='1';DB::connection('tenant')->table('finance_document_matches')->where('operation_uuid',$id['operation_uuid'])->update(['snapshot'=>json_encode($snapshot)]);$proof['snapshot_hash']=SolaStockJournalContract::payloadHash($snapshot);
        try {OriginReceiptCostAuthority::fromLockedNativeProvenance($id,$proof,$this->mapping,'prepare',0);$this->fail('Partial native ledger was accepted');}
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {$this->assertSame(409,$e->getStatusCode());}
    }
}
