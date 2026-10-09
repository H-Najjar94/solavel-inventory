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
        // Schema is installed once by the sealed launcher BEFORE any business transaction.
        config(['integration_safety.financial_origin_cash_handoff_enabled'=>true,
            'integration_safety.financial_origin_expense_handoff_enabled'=>true]);
        $this->assertSame(0, DB::connection('tenant')->table('units')->where('organization_id', \Tests\Support\TenantTestManager::ORG_A)->where('code','SALE-EACH')->count(), 'Previous test leaked committed source fixtures.');
        $this->initializeSalesFixture($physical,$tracking);
        // Request-only fixtures still represent a connected organization. The shared
        // sales helper creates the active connection only for physical fixtures.
        // Preserve zero physical opening stock while providing the real persisted
        // connection required by canonical FinancialOriginCapabilities admission.
        if (!$physical) \App\Models\Tenant\IntegrationSetting::create([
            'integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14,
            'meta'=>['client_id'=>7,'central_organization_id'=>\Tests\Support\TenantTestManager::ORG_A,
                'signing_key_id'=>'private-test','transport_enabled'=>false,
                'finance_currency_contract'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],
                    'money_scale'=>2,'rate_scale'=>8,'inventory_valuation_basis'=>\App\Services\Integration\FinanceBaseValuation::BASIS]],
        ]);
        $this->assertSame(1, DB::connection('tenant')->transactionLevel());
        $this->assertTrue(DB::connection('tenant')->getPdo()->inTransaction(), 'Native fixture lost its actual SQL rollback transaction.');
        $this->assertTrue(app(\App\Services\Integration\Cash219SchemaReadiness::class)->ready());
        $this->assertTrue(DB::connection('tenant')->getSchemaBuilder()->hasTable('finance_document_positions'));

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
        $db->table('journal_entries')->updateOrInsert(['id'=>95],['organization_id'=>14,'source'=>$o->journalSource(),'source_type'=>match($o->type){'expense'=>\App\Models\Expense::class,'sales_receipt'=>\App\Models\SalesReceipt::class,'invoice'=>\App\Models\Invoice::class},'source_id'=>850,'status'=>'posted']);
        $db->table('finance_document_requests')->updateOrInsert(['organization_id'=>14,'request_uuid'=>$data['request_uuid']],['organization_mapping_uuid'=>$this->mapping->mapping_uuid,
            'actor_id'=>323,'source_document_type'=>$o->type,'source_document_id'=>850,'source_journal_id'=>95,'source_revision'=>$data['source_revision'],'side'=>$o->domain()==='sales'?'sales':'purchase','command'=>$command,'payload'=>json_encode($data)]);
        $proof=array_replace(['allowed'=>true,'actor_id'=>323,'source_document_type'=>$o->type,'source_document_id'=>850,'source_journal_id'=>95,
            'request_uuid'=>$data['request_uuid'],'request_revision'=>$data['source_revision'],'canonical_payload'=>$data,
            'command'=>$command,'command_source_revision'=>$data['source_revision'],'expected_revision'=>$data['source_revision']],$override);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeOrigin')->andReturn($proof);
    }
}
