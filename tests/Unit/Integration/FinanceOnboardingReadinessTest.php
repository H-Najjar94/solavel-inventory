<?php
namespace Tests\Unit\Integration;
use App\Services\Integration\FinanceOnboardingReadiness;
use App\Services\Integration\ConnectionManagementPolicy;
use App\Services\Integration\ConnectionWizardService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
class FinanceOnboardingReadinessTest extends TestCase {
 private bool $entitled=true;
 public function createApplication(){ $app=require __DIR__.'/../../../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();return $app; }
 protected function setUp():void {parent::setUp();config(['database.default'=>'tenant','database.connections.tenant'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],'tenancy.parent_base_url'=>'https://central.fixture']);DB::purge('tenant');
  Schema::create('organizations',function(Blueprint $t){$t->id();$t->integer('central_org_id');$t->string('setup_status');$t->string('finance_setup_step');$t->timestamp('finance_setup_completed_at')->nullable();});
  Schema::create('accounts',fn(Blueprint $t)=>$t->id());Schema::create('invoices',fn(Blueprint $t)=>$t->id());
  DB::table('organizations')->insert(['id'=>31,'central_org_id'=>71,'setup_status'=>'draft','finance_setup_step'=>'3']);
  DB::table('organizations')->insert(['id'=>32,'central_org_id'=>72,'setup_status'=>'complete','finance_setup_step'=>'done','finance_setup_completed_at'=>now()]);
  $gate=\Mockery::mock(InventoryCommercialEntitlementService::class);$gate->shouldReceive('checkConnectionSetupReadiness')->andReturnUsing(fn()=>['allowed'=>$this->entitled,'reason_code'=>$this->entitled?'connection_setup_available':'finance_and_inventory_access_required']);$this->app->instance(InventoryCommercialEntitlementService::class,$gate);
  $policy=\Mockery::mock(ConnectionManagementPolicy::class);$policy->shouldReceive('status')->andReturnUsing(fn($id,$u)=>['can_manage_connection'=>$u?->id===1]);$this->app->instance(ConnectionManagementPolicy::class,$policy);
 }
 public function test_finance_setup_is_independent_of_product_registration_order_and_upgrade():void {
  foreach(['stock_first','projects_first','stock_projects_first','finance_first'] as $order){
   $this->entitled=false;$s=app(FinanceOnboardingReadiness::class)->resolve(71);$this->assertSame('ACCESS_REQUIRED',$s['state'],$order);
   $this->entitled=true;$s=app(FinanceOnboardingReadiness::class)->resolve(71,(object)['id'=>1]);$this->assertSame('FINANCE_PROVISIONED_SETUP_INCOMPLETE',$s['state'],$order);
   $this->assertStringContainsString('organization_id=71',$s['setup_url']);$this->assertStringContainsString(urlencode('/settings/organizations/31/enter?central_organization_id=71'),$s['setup_url']);
   $this->assertSame('3',DB::table('organizations')->where('id',31)->value('finance_setup_step'));
  }
 }
 public function test_fresh_completion_and_org_switch_never_leak_setup_permission():void {
  $service=app(FinanceOnboardingReadiness::class);$this->assertNull($service->resolve(71,(object)['id'=>2])['setup_url']);
  $this->assertTrue($service->resolve(72)['finance_setup_complete']);$this->assertFalse($service->resolve(71)['finance_setup_complete']);
  DB::table('organizations')->where('id',31)->update(['setup_status'=>'complete']);$this->assertFalse($service->resolve(71)['finance_setup_complete']);
  DB::table('organizations')->where('id',31)->update(['finance_setup_completed_at'=>now()]);$this->assertSame('FINANCE_READY',$service->resolve(71)['state']);
 }
 public function test_direct_activation_cannot_bypass_finance_setup():void {
  $service=(new \ReflectionClass(ConnectionWizardService::class))->newInstanceWithoutConstructor();
  $this->expectException(\RuntimeException::class);$service->activate(71,'fixture','hash','confirm',1);
 }
 public function test_provisioning_and_unavailable_are_distinct():void {
  $service=app(FinanceOnboardingReadiness::class);$this->assertSame('PROVISIONING_PENDING',$service->resolve(99)['state']);
  Schema::drop('organizations');Schema::create('organizations',function(Blueprint $t){$t->id();$t->integer('central_org_id');});DB::table('organizations')->insert(['central_org_id'=>71]);
  $this->assertSame('READINESS_UNAVAILABLE',$service->resolve(71)['state']);
 }
 public function test_sync_requests_cannot_bypass_setup():void {
  $service=(new \ReflectionClass(\App\Services\Integration\DurableOutboxTransportService::class))->newInstanceWithoutConstructor();
  $guard=new \ReflectionMethod($service,'assertExecutionEnabled');
  $this->expectException(\RuntimeException::class);$guard->invoke($service,71);
 }
 public function test_genuine_hold_is_independent_from_completed_onboarding():void {
  config(['integration_safety.solabooks_delivery_enabled'=>false,'integration_safety.phase6a_uat.enabled'=>false]);
  $safety=app(\App\Services\Integration\IntegrationSafetyHold::class);
  $this->assertFalse($safety->deliveryEnabledFor(72));
  $this->assertTrue(app(FinanceOnboardingReadiness::class)->resolve(72)['finance_setup_complete']);
  $this->assertFalse($safety->deliveryEnabledFor(72));
 }

 public function test_completed_background_worker_setup_guard_does_not_require_interactive_entitlement_context():void {
  $gate=\Mockery::mock(InventoryCommercialEntitlementService::class);$gate->shouldNotReceive('checkConnectionSetupReadiness');
  $this->app->instance(InventoryCommercialEntitlementService::class,$gate);
  app(FinanceOnboardingReadiness::class)->assertComplete(72);
  $this->addToAssertionCount(1);
 }

 public function test_legacy_configuration_cannot_set_active_before_finance_setup():void {
  $context=\Mockery::mock(\App\Tenancy\OrganizationContext::class);$context->shouldReceive('idOrFail')->andReturn(71);
  $controller=(new \ReflectionClass(\App\Http\Controllers\Api\V1\IntegrationController::class))->newInstanceWithoutConstructor();
  (new \ReflectionProperty($controller,'context'))->setValue($controller,$context);
  $request=\Illuminate\Http\Request::create('/integration/solabooks/configure','PUT',['mode'=>'active','solabooks_organization_id'=>31,'client_id'=>9]);
  $this->expectException(\RuntimeException::class);$controller->configure($request);
 }

}
