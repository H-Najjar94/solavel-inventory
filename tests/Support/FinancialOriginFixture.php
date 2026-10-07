<?php
namespace Tests\Support;
use App\Services\FinancialOrigins\FinancialOrigin;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
trait FinancialOriginFixture
{
    use SalesHandoffFixture;
    protected function initializeOriginFixture(bool $physical=false,string $tracking='none'):void
    {
        $this->useTenantA();$s=DB::connection('tenant')->getSchemaBuilder();
        (require base_path('database/migrations/tenant/2026_10_07_186000_create_financial_origin_requests.php'))->up();
        foreach(['sales_receipts','expenses']as$table)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('customer_id')->nullable();$t->unsignedBigInteger('vendor_id')->nullable();});
        foreach(['sales_receipt_lines'=>'sales_receipt_id','expense_lines'=>'expense_id']as$table=>$parent)if(!$s->hasTable($table))$s->create($table,function(Blueprint $t)use($parent){$t->id();$t->unsignedBigInteger($parent);$t->unsignedBigInteger('inventory_item_id');$t->decimal('qty',24,4);$t->string('unit')->nullable();$t->string('item_usage')->nullable();});
        if(!$s->hasTable('finance_document_requests'))$s->create('finance_document_requests',function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->string('side');$t->string('source_document_type');$t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);$t->string('command');$t->json('payload');});
        if(!$s->hasColumn('journal_entries','source'))$s->table('journal_entries',fn(Blueprint $t)=>$t->string('source')->nullable());
        $this->tenantTestManager->cleanup();$this->initializeSalesFixture($physical,$tracking);
    }
    protected function typed(string $type='sales_receipt',bool $anonymous=false):array
    {
        return ['source_document_type'=>$type,'source_document_id'=>850,'source_document_number'=>'QA-TYPED-850','source_journal_id'=>95,
            'request_uuid'=>(string)Str::uuid(),'source_revision'=>str_repeat('a',64),'source_status'=>'posted','document_date'=>'2026-10-07',
            'currency_code'=>'JOD','base_currency_code'=>'JOD','pricing_mode'=>'exclusive',
            ($type==='expense'?'supplier_external_id':'customer_external_id')=>$type==='expense'?704:($anonymous?null:703),
            'lines'=>[['source_document_line_id'=>851,'item_external_id'=>701,'unit_external_id'=>702,'quantity'=>'4','unit_price'=>'7','discount_rate'=>'0']]];
    }
    protected function proof(array $data,string $command='upsert',array $override=[]):void
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
}
