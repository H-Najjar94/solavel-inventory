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
    use TenantAware,\Tests\Support\FinancialOriginFixture;
    protected function setUp():void {parent::setUp();$this->initializeOriginFixture();}
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
