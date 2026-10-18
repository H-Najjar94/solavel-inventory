<?php
namespace Tests\Feature\Stock;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Models\Tenant\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\TenantAware;
class SupplierValidationTest extends TestCase {
 use TenantAware;
 public function test_screenshot_payload_is_valid_and_preserves_contact_details():void {
  $this->useTenantA();$data=['code'=>'3212','name'=>'KHALED','email'=>'khaled@example.test','phone'=>'32123311313','address'=>'address','tax_number'=>'3','currency'=>'JOD','payment_terms'=>'terms','is_active'=>true,'notes'=>''];
  $result=app(SupplierController::class)->store(Request::create('/suppliers','POST',$data))->getData(true);$this->assertSame('3212',$result['data']['code']);$this->assertSame('JOD',$result['data']['contact']['currency']);
 }
 public function test_duplicate_code_has_a_field_error_before_write():void {
  $this->useTenantA();Supplier::create(['code'=>'DUP','name'=>'First','is_active'=>true]);$count=Supplier::count();
  try{app(SupplierController::class)->store(Request::create('/suppliers','POST',['code'=>'DUP','name'=>'Second']));$this->fail('Duplicate must fail');}catch(ValidationException $e){$this->assertArrayHasKey('code',$e->errors());}$this->assertSame($count,Supplier::count());
 }
 public function test_update_allows_own_code_but_rejects_another_supplier_code():void {
  $this->useTenantA();$one=Supplier::create(['code'=>'ONE','name'=>'First']);$two=Supplier::create(['code'=>'TWO','name'=>'Second']);$controller=app(SupplierController::class);
  $response=$controller->update(Request::create('/suppliers','PUT',['code'=>'ONE','name'=>'Updated']),$one);$this->assertSame('Updated',$response->getData(true)['data']['name']);
  try{$controller->update(Request::create('/suppliers','PUT',['code'=>'TWO']),$one);$this->fail('Duplicate must fail');}catch(ValidationException $e){$this->assertArrayHasKey('code',$e->errors());}$this->assertSame('ONE',$one->fresh()->code);
 }
 public function test_deleted_supplier_code_returns_validation_instead_of_database_error():void {
  $this->useTenantA();$supplier=Supplier::create(['code'=>'OLD','name'=>'Archived']);$supplier->delete();$this->expectException(ValidationException::class);app(SupplierController::class)->store(Request::create('/suppliers','POST',['code'=>'OLD','name'=>'New']));
 }
}
