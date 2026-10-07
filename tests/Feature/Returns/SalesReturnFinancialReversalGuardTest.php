<?php
namespace Tests\Feature\Returns;
use App\Models\Tenant\{IntegrationOrganizationMapping,SalesReturn};
use App\Services\Sales\SalesReturnFinancialReversalGuard;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\TenantAware;
/** Real native Stock authority query/locks; Finance source/JE projection is an explicitly isolated boundary. */
final class SalesReturnFinancialReversalGuardTest extends TestCase {
 use TenantAware;
 private function fixture():array {
  $this->useTenantA();$db=DB::connection('tenant');$org=app(OrganizationContext::class)->idOrFail();
  foreach(['invoices'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');},'credit_notes'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');},
   'journal_entries'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->string('source');$t->string('source_type');$t->unsignedBigInteger('source_id');$t->string('status');$t->timestamp('posted_at')->nullable();$t->timestamp('voided_at')->nullable();$t->timestamp('deleted_at')->nullable();},
   'finance_sales_returns'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('return_mapping_uuid');$t->unsignedBigInteger('stock_return_id');$t->unsignedBigInteger('invoice_id')->nullable();$t->unsignedBigInteger('credit_note_id')->nullable();},
   'finance_sales_credit_allocations'=>function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('return_mapping_uuid');$t->unsignedBigInteger('credit_note_id');$t->unsignedBigInteger('journal_entry_id');$t->string('state');}]
   as$name=>$build)if(!Schema::connection('tenant')->hasTable($name))Schema::connection('tenant')->create($name,$build);
  foreach(['source','source_type','source_id']as$field)if(!Schema::connection('tenant')->hasColumn('journal_entries',$field))Schema::connection('tenant')->table('journal_entries',function(Blueprint$t)use($field){if($field==='source_id')$t->unsignedBigInteger($field)->nullable();else$t->string($field)->nullable();});
  // MySQL DDL implicitly commits. Re-enter the native reserved-tenant transaction before fixture writes.
  $this->tenantTestManager->cleanup();$this->setUpTenantAware();$this->useTenantA();$db=DB::connection('tenant');
  $mapping=IntegrationOrganizationMapping::create(['mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>7,'central_organization_id'=>$org,'solastock_organization_id'=>$org,'finance_organization_id'=>14,
   'tenant_database_identity'=>$db->getDatabaseName(),'integration'=>'solabooks','contract_version'=>'solastock-journal.v2','status'=>'verified','activation_state'=>'active','base_currency_code'=>'JOD']);
  $db->table('invoices')->insert(['id'=>21,'organization_id'=>14]);$db->table('credit_notes')->insert(['id'=>22,'organization_id'=>14]);
  $uuid=(string)Str::uuid();$db->table('finance_sales_returns')->insert(['id'=>23,'organization_id'=>14,'organization_mapping_uuid'=>$mapping->mapping_uuid,'return_mapping_uuid'=>$uuid,'stock_return_id'=>24,'invoice_id'=>21,'credit_note_id'=>22]);
  $db->table('journal_entries')->insert(['id'=>25,'organization_id'=>14,'source'=>'NOTE','source_type'=>'App\\Models\\CreditNote','source_id'=>22,'status'=>'posted','posted_at'=>now()]);
  $return=new SalesReturn;$return->forceFill(['id'=>24,'organization_id'=>$org]);return compact('db','org','mapping','return','uuid');
 }
 public function test_actual_active_credit_journal_blocks_physical_return_reversal():void {
  extract($this->fixture());$before=$db->table('journal_entries')->count();
  try{$db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));$this->fail('Posted credit allowed physical source reversal.');}
  catch(ValidationException$e){$this->assertArrayHasKey('sales_return_id',$e->errors());}
  $this->assertSame($before,$db->table('journal_entries')->count());
 }
 public function test_voided_credit_allows_reversal_but_active_native_allocation_still_blocks():void {
  extract($this->fixture());$db->table('journal_entries')->where('id',25)->update(['voided_at'=>now()]);
  $db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));$this->assertTrue(true);
  $db->table('journal_entries')->insert(['id'=>26,'organization_id'=>14,'source'=>'AR','source_type'=>'Synthetic allocation','source_id'=>22,'status'=>'posted','posted_at'=>now()]);
  $db->table('finance_sales_credit_allocations')->insert(['organization_id'=>14,'return_mapping_uuid'=>$uuid,'credit_note_id'=>22,'journal_entry_id'=>26,'state'=>'posted']);
  $this->expectException(ValidationException::class);$db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));
 }
 public function test_foreign_immutable_mapping_cannot_claim_this_return_credit_ownership():void {
  extract($this->fixture());$db->table('finance_sales_returns')->where('id',23)->update(['organization_mapping_uuid'=>(string)Str::uuid()]);
  $db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));$this->assertTrue(true);
  $this->useTenantB();$db=DB::connection('tenant');$db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));$this->assertTrue(true);
 }
 public function test_missing_or_changed_invoice_pointer_fails_closed_before_stock_inverse():void {
  extract($this->fixture());$db->table('finance_sales_returns')->where('id',23)->update(['invoice_id'=>999]);
  try{$db->transaction(fn()=>app(SalesReturnFinancialReversalGuard::class)->lockAndAssert($return));$this->fail('Missing invoice authority accepted.');}
  catch(ValidationException$e){$this->assertArrayHasKey('sales_return_id',$e->errors());}
  $this->assertSame(1,$db->table('journal_entries')->count());
 }
}
