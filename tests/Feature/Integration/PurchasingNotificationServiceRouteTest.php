<?php
namespace Tests\Feature\Integration;
use App\Http\Middleware\VerifySolavelSyncSignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
/** Native HTTP route/middleware; invalid business payload prevents tenant/customer effects. */
final class PurchasingNotificationServiceRouteTest extends TestCase {
 protected function setUp():void {parent::setUp();config(['solavel_sync.secret'=>'private-notification-route-test','solavel_sync.use_signed_sync'=>true,'solavel_sync.allowed_client_ids'=>[],'cache.default'=>'array']);}
 public function test_only_signed_context_is_registered_outside_browser_csrf():void {
  app(\Illuminate\Contracts\Http\Kernel::class);$router=app('router');$context=Route::getRoutes()->getByName('api.tenancy.purchasing-notification-context');$this->assertNotNull($context);$middleware=$router->gatherRouteMiddleware($context);$this->assertContains(VerifySolavelSyncSignature::class,$middleware);$this->assertNotContains(PreventRequestForgery::class,$middleware);$this->assertNotContains('web',$context->gatherMiddleware());
  $browser=Route::getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/goods-receipts','POST'));$this->assertContains(PreventRequestForgery::class,$router->gatherRouteMiddleware($browser),json_encode(['uri'=>$browser->uri(),'name'=>$browser->getName(),'middleware'=>$router->gatherRouteMiddleware($browser),'raw'=>$browser->gatherMiddleware()]));
  $this->postJson('/api/tenancy/purchasing-notification-context',['client_id'=>87])->assertStatus(403)->assertJsonPath('code','sync_signature_missing');
 }
 public function test_valid_signed_service_request_reaches_native_validation_without_browser_csrf_token():void {
  $body=json_encode(['client_id'=>87,'nonce'=>(string)\Illuminate\Support\Str::uuid()],JSON_THROW_ON_ERROR);$ts=(string)time();$signature='sha256='.hash_hmac('sha256',$ts.'.'.$body,'private-notification-route-test');
  $this->call('POST','/api/tenancy/purchasing-notification-context',[],[],[],['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_SOLAVEL_TIMESTAMP'=>$ts,'HTTP_X_SOLAVEL_SIGNATURE'=>$signature],$body)->assertStatus(422)->assertJsonValidationErrors(['organization_id','request_id']);
 }
}
