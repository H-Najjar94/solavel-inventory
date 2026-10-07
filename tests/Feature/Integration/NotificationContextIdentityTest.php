<?php
namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\Tenancy\{SalesNotificationContextController,PurchasingNotificationContextController};
use App\Models\Landlord\Organization;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Native active organization and request identity; tenancy switch exception is an explicit isolated seam. */
final class NotificationContextIdentityTest extends TestCase
{
    use TenantAware;
    public function test_both_signed_contexts_use_verified_identity_and_restore_existing_attributes_on_exception():void
    {
        $this->useTenantA();
        $org=Organization::findOrFail(TenantTestManager::ORG_A);
        foreach([SalesNotificationContextController::class,PurchasingNotificationContextController::class] as $controller){
            foreach([false,true] as $existing){
                $request=Request::create('/signed-context','POST',['client_id'=>$org->client_id,'organization_id'=>$org->id,'request_id'=>1,'nonce'=>(string)Str::uuid()]);
                $request->attributes->set('unrelated','preserved');
                $old=['client_id'=>999,'organization_id'=>998,'sentinel'=>'original'];
                if($existing)$request->attributes->set('tenant_state',$old);
                $originalRequest=app('request');app()->instance('request',$request);
                $tenants=\Mockery::mock(TenantManager::class);
                $tenants->shouldReceive('resolveDatabaseName')->with((int)$org->client_id)->once()->andReturn('isolated');
                $tenants->shouldReceive('switchToDatabase')->with('isolated')->once()->andReturnUsing(function()use($request,$org){
                    $this->assertSame(['client_id'=>(int)$org->client_id,'organization_id'=>(int)$org->id],$request->attributes->get('tenant_state'));
                    $this->assertSame((int)$org->client_id,app(EntitlementsCache::class)->currentClientId());
                    throw new \RuntimeException('isolated switch failure');
                });
                try{app($controller)($request,$tenants,app(OrganizationContext::class));$this->fail('Expected switch failure');}
                catch(\RuntimeException $e){$this->assertSame('isolated switch failure',$e->getMessage());}
                finally{app()->instance('request',$originalRequest);}
                $this->assertSame($existing,$request->attributes->has('tenant_state'));
                if($existing)$this->assertSame($old,$request->attributes->get('tenant_state'));
                $this->assertSame('preserved',$request->attributes->get('unrelated'));
            }
        }
    }
}
