<?php
namespace Tests\Support;

use App\Models\Tenant\{Customer, IntegrationAccountMapping, IntegrationMasterDataMapping, IntegrationOrganizationMapping, IntegrationSetting, Item, Unit};
use App\Services\Documents\OpeningStockService;
use App\Services\Integration\{FinanceBaseValuation, SolaBooksOutboxDeliveryService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real private Stock/Finance projection rows; signed Finance authorization is the explicit remote seam. */
trait SalesHandoffFixture
{
    private $mapping; private $item; private $unit; private $customer; private $warehouse;

    protected function initializeSalesFixture(bool $physical=false, string $tracking='none'): void
    {
        $this->useTenantA();
        $schema=DB::connection('tenant')->getSchemaBuilder();
        if(!$schema->hasTable('finance_sales_requests'))$schema->create('finance_sales_requests',function(\Illuminate\Database\Schema\Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');$t->unsignedBigInteger('invoice_id');$t->unsignedBigInteger('invoice_journal_id')->nullable();$t->string('source_revision');$t->string('command');});
        if(!$schema->hasColumn('journal_entries','source_type'))$schema->table('journal_entries',function(\Illuminate\Database\Schema\Blueprint$t){$t->string('source_type')->nullable();$t->unsignedBigInteger('source_id')->nullable();});
        // Test-only DDL is complete before any fixture facts; re-enter a genuine rollback transaction.
        $this->tenantTestManager->cleanup();$this->useTenantA();
        $this->unit=Unit::create(['code'=>'SALE-EACH','name'=>'Each','kind'=>'count','is_active'=>true]);
        $this->item=StockTestFactory::averageItem(['base_unit_id'=>$this->unit->id,'tracking_type'=>$tracking]);
        $this->customer=Customer::create(['code'=>'QA-SALE-CUST','name'=>'QA customer','is_active'=>true]);
        if($physical){
            $this->warehouse=StockTestFactory::warehouse();
            $line=['item_id'=>$this->item->id,'entered_unit_id'=>$this->unit->id,'quantity'=>'20','unit_cost'=>'3'];
            if($tracking==='serial'){unset($line['entered_unit_id']);$line['serials']=array_map(fn($n)=>'QA-DISPATCH-S-'.$n,range(1,20));}
            if($tracking==='lot')$line+=['lot_code'=>'QA-DISPATCH-LOT','expiry_date'=>now()->addYear()->toDateString()];
            $opening=app(OpeningStockService::class);$opening->post($opening->createDraft(['warehouse_id'=>$this->warehouse->id],[$line]));
        }
        DB::connection('tenant')->table('organizations')->insert(['id'=>14,'central_org_id'=>TenantTestManager::ORG_A,'setup_status'=>'complete','finance_setup_completed_at'=>now()]);
        $this->mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'tenant_database_identity'=>DB::connection('tenant')->getDatabaseName(),'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD','verified_at'=>now()]);
        foreach(['item'=>[$this->item->id,701],'unit'=>[$this->unit->id,702],'customer'=>[$this->customer->id,703]]as$type=>[$local,$remote])$this->master($type,$local,$remote);
        if($physical){
            IntegrationSetting::create(['integration'=>'solabooks','mode'=>'active','solabooks_organization_id'=>14,'meta'=>['client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'signing_key_id'=>'private-test','transport_enabled'=>false,'transport_enabled_workflows'=>['sales_order.confirmed','stock_reserved','stock_reservation_released','shipment.posted','sales_return.posted'],'finance_currency_contract'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],'money_scale'=>2,'rate_scale'=>8,'inventory_valuation_basis'=>FinanceBaseValuation::BASIS]]]);
            foreach(['inventory_asset'=>[100,'asset'],'cogs'=>[200,'expense']]as$role=>[$id,$type]){
                DB::connection('tenant')->table('accounts')->insert(['id'=>$id,'organization_id'=>14,'code'=>(string)$id,'name'=>$role,'type'=>$type,'is_active'=>true,'is_postable'=>true]);
                $a=IntegrationAccountMapping::create(['integration'=>'solabooks','mapping_type'=>$role,'solabooks_account_id'=>$id,'status'=>'verified']);$this->master('account_role',$a->id,$id);
            }
        }
    }
    private function master(string$type,int$local,int$remote):void {
        IntegrationMasterDataMapping::create(['mapping_uuid'=>(string)Str::uuid(),'organization_mapping_uuid'=>$this->mapping->mapping_uuid,'central_client_id'=>7,'central_organization_id'=>TenantTestManager::ORG_A,'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'entity_type'=>$type,'solastock_record_id'=>(string)$local,'solabooks_record_id'=>(string)$remote,'status'=>'verified']);
    }
    private function data():array {
        return ['request_uuid'=>(string)Str::uuid(),'source_invoice_id'=>800,'source_invoice_number'=>'QA-800','source_revision'=>str_repeat('a',64),'source_status'=>'draft','pricing_mode'=>'exclusive','customer_external_id'=>703,'invoice_date'=>'2026-10-07','currency_code'=>'JOD','base_currency_code'=>'JOD','exchange_rate'=>'1','exchange_rate_date'=>'2026-10-07','lines'=>[['source_line_id'=>'801','item_external_id'=>701,'unit_external_id'=>702,'quantity'=>'4','unit_price'=>'7']]];
    }
    private function authority(array$d,array$overrides=[],string$command='upsert'):void {
        DB::connection('tenant')->table('invoices')->updateOrInsert(['id'=>800],['organization_id'=>14]);
        DB::connection('tenant')->table('finance_sales_requests')->updateOrInsert(['organization_id'=>14,'invoice_id'=>800,'request_uuid'=>$d['request_uuid']],['organization_mapping_uuid'=>$this->mapping->mapping_uuid,'source_revision'=>$d['source_revision'],'command'=>$command,'invoice_journal_id'=>$d['posted_invoice_journal_id']??null]);
        $canonical=$d;unset($canonical['expected_revision']);
        $this->mock(SolaBooksOutboxDeliveryService::class)->shouldReceive('authorizeSales')->with(323,800,$d['source_status']==='posted'?'post':'edit_draft')->andReturn(array_replace(['allowed'=>true,'request_revision'=>$d['source_revision'],'fulfillment_payload'=>$canonical],$overrides));
    }
}
