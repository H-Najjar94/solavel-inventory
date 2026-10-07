<?php
namespace Tests\Feature\Integration;
use App\Models\Tenant\{FinancialOriginRequest,FulfillmentRequest,IntegrationMasterDataMapping,SalesOrder,StockLedger,Supplier};
use App\Services\FinancialOrigins\{FinancialOrigin,OriginRequestPayload,OriginRequestService};
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SalesHandoffFixture;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Real native source/intent/mapping records; remote signed Finance authorization is the explicit seam. */
final class FinancialOriginRequestTest extends TestCase
{
    use TenantAware,SalesHandoffFixture;
    protected function setUp():void
    {
        parent::setUp();$this->useTenantA();$s=DB::connection('tenant')->getSchemaBuilder();
        (require base_path('database/migrations/tenant/2026_10_07_186000_create_financial_origin_requests.php'))->up();
        foreach(['sales_receipts','expenses']as$table)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('customer_id')->nullable();$t->unsignedBigInteger('vendor_id')->nullable();});
        foreach(['sales_receipt_lines'=>'sales_receipt_id','expense_lines'=>'expense_id']as$table=>$parent)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t)use($parent){$t->id();$t->unsignedBigInteger($parent);$t->unsignedBigInteger('inventory_item_id');$t->decimal('qty',24,4);$t->string('unit')->nullable();$t->string('item_usage')->nullable();});
        if(!$s->hasTable('finance_document_requests'))$s->create('finance_document_requests',function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        if(!$s->hasColumn('journal_entries','source'))$s->table('journal_entries',fn(Blueprint $t)=>$t->string('source')->nullable());
        $this->tenantTestManager->cleanup();$this->initializeSalesFixture();
    }
    private function typed(string $type='sales_receipt',bool $anonymous=false):array
    {
        return ['source_document_type'=>$type,'source_document_id'=>850,'source_document_number'=>'QA-TYPED-850','source_journal_id'=>95,
            'request_uuid'=>(string)Str::uuid(),'source_revision'=>str_repeat('a',64),'source_status'=>'posted','document_date'=>'2026-10-07',
            'currency_code'=>'JOD','base_currency_code'=>'JOD','pricing_mode'=>'exclusive',
            ($type==='expense'?'supplier_external_id':'customer_external_id')=>$type==='expense'?704:($anonymous?null:703),
            'lines'=>[['source_document_line_id'=>851,'item_external_id'=>701,'unit_external_id'=>702,'quantity'=>'4','unit_price'=>'7','discount_rate'=>'0']]];
    }
    private function proof(array $data,string $command='upsert',array $override=[]):void
    {
        $o=FinancialOrigin::fromPayload($data);$db=DB::connection('tenant');
        $attrs=['organization_id'=>14,$o->type==='expense'?'vendor_id':'customer_id'=>$data[$o->type==='expense'?'supplier_external_id':'customer_external_id']];
        $db->table($o->documentTable())->updateOrInsert(['id'=>850],$attrs);
        $db->table($o->lineTable())->updateOrInsert(['id'=>851],[$o->lineParent()=>850,'inventory_item_id'=>701,'qty'=>'4','unit'=>'Each','item_usage'=>'inventory']);
        $db->table('journal_entries')->updateOrInsert(['id'=>95],['organization_id'=>14,'source'=>$o->journalSource(),'source_type'=>$o->modelClass(),'source_id'=>850,'status'=>'posted']);
        $db->table('finance_document_requests')->updateOrInsert(['organization_id'=>14,'request_uuid'=>$data['request_uuid']],['organization_mapping_uuid'=>$this->mapping->mapping_uuid,
            'source_document_type'=>$o->type,'source_document_id'=>850,'source_journal_id'=>95,'source_revision'=>$data['source_revision'],'side'=>$o->domain()==='sales'?'sales':'purchase','command'=>$command,'payload'=>json_encode($data)]);
        $proof=array_replace(['allowed'=>true,'actor_id'=>323,'source_document_type'=>$o->type,'source_document_id'=>850,'source_journal_id'=>95,
            'request_uuid'=>$data['request_uuid'],'request_revision'=>$data['source_revision'],'canonical_payload'=>$data,
            'command'=>$command,'command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision']],$override);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOrigin')->andReturn($proof);
    }
    public function test_actual_cash_source_replay_creates_one_typed_request_without_legacy_invoice_or_movement():void
    {
        $d=$this->typed();$this->proof($d);$service=app(OriginRequestService::class);$one=$service->upsert($d,323);$two=$service->upsert($d,323);
        $this->assertSame($one,$two);$this->assertSame('sales_receipt',$one['source_document_type']);$this->assertSame(850,$one['source_document_id']);
        $this->assertSame(1,FinancialOriginRequest::count());$this->assertSame(0,FulfillmentRequest::count());$this->assertSame(0,SalesOrder::count());$this->assertSame(0,StockLedger::count());
        $this->assertSame('1.0000000000',$one['lines'][0]['unit_conversion_factor']);
    }
    public function test_anonymous_cash_source_keeps_native_party_null_without_synthetic_customer():void
    {
        $d=$this->typed(anonymous:true);$this->proof($d);app(OriginRequestService::class)->upsert($d,323);
        $this->assertNull(FinancialOriginRequest::sole()->party_id);$this->assertSame(1,\App\Models\Tenant\Customer::count());$this->assertSame(0,StockLedger::count());
    }
    public function test_actual_inventory_expense_uses_supplier_and_never_manufactures_a_bill_identity():void
    {
        $supplier=Supplier::create(['code'=>'QA-TYPED-SUP','name'=>'QA typed supplier','is_active'=>true]);$this->master('supplier',$supplier->id,704);
        $d=$this->typed('expense');$this->proof($d);$result=app(OriginRequestService::class)->upsert($d,323);
        $this->assertSame('expense',$result['source_document_type']);$this->assertSame($supplier->id,FinancialOriginRequest::sole()->party_id);
        $this->assertSame(0,\App\Models\Tenant\ReceivingRequest::count());$this->assertSame(0,StockLedger::count());
    }
    public function test_missing_unit_mapping_is_actionable_and_resumes_exact_original_cash_request_uuid():void
    {
        $d=$this->typed();$this->proof($d);$pair=IntegrationMasterDataMapping::where('entity_type','unit')->sole();$pair->update(['status'=>'pending']);
        try{app(OriginRequestService::class)->upsert($d,323);$this->fail('Missing unit accepted');}catch(ValidationException $e){$this->assertSame('unit',$e->response->getData(true)['dependency']['entity_type']);$this->assertSame(702,$e->response->getData(true)['dependency']['source_id']);}
        $pair->update(['status'=>'verified']);$r=app(OriginRequestService::class)->upsert($d,323);$this->assertSame($d['request_uuid'],$r['request_uuid']);$this->assertSame(1,FinancialOriginRequest::count());$this->assertSame(0,StockLedger::count());
    }
    public function test_voided_source_journal_and_forged_invoice_alias_cannot_publish_cash_demand():void
    {
        $d=$this->typed();$this->proof($d);DB::connection('tenant')->table('journal_entries')->where('id',95)->update(['status'=>'voided']);
        try{app(OriginRequestService::class)->upsert($d,323);$this->fail('Voided cash source accepted');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        try{OriginRequestPayload::fromArray($d+['source_invoice_id'=>850]);$this->fail('Cash disguised as Invoice');}catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertSame(0,FinancialOriginRequest::count());$this->assertSame(0,StockLedger::count());
    }
    public function test_cancel_before_acceptance_preserves_typed_tombstone_and_rejects_delayed_upsert():void
    {
        $d=$this->typed();$this->proof($d,'cancel');$wire=array_intersect_key($d,array_flip(['source_document_type','source_document_id','source_document_number','source_journal_id','request_uuid','source_revision']))+['expected_revision'=>$d['source_revision']];
        $service=app(OriginRequestService::class);$one=$service->cancel($wire,323);$this->assertSame($one,$service->cancel($wire,323));$this->assertSame('cancelled',$one['status']);$this->assertSame([],$one['lines']);
        $this->proof($d);try{$service->upsert($d,323);$this->fail('Old cash request resurrected');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame(1,FinancialOriginRequest::count());$this->assertSame(0,SalesOrder::count());$this->assertSame(0,StockLedger::count());
    }
}
