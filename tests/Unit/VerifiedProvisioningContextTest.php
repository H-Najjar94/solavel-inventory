<?php
namespace Tests\Unit;
use App\Services\SystemEvents\VerifiedProvisioningContext as C;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
final class VerifiedProvisioningContextTest extends TestCase
{
    public function test_verified_actor_is_allowlisted_and_request_local(): void
    {
$body=['client_id'=>99,'organization_id'=>101,'correlation_id'=>'operation','_initiator'=>['user_id'=>42,'user_name'=>'Requester','user_email'=>'test@example.test','request_id'=>'original','organization_id'=>999,'password'=>'secret']];
$r=Request::create('/api/tenancy/bootstrap','POST',[],[],[],[],json_encode($body));
$this->assertTrue(C::get($r)===[]); C::bind($r); $c=C::get($r);
$this->assertTrue($c['user']['id']===42 && $c['organization_id']===101 && $c['client_id']===99);
$this->assertTrue($c['request_id']==='original' && $c['correlation_id']==='operation');
$this->assertTrue(!str_contains(json_encode($c),'secret') && !str_contains(json_encode($c),'999'));
$next=Request::create('/api/tenancy/bootstrap','POST',[],[],[],[],json_encode(['client_id'=>100,'organization_id'=>102]));
$this->assertTrue(C::get($next)===[]); C::bind($next);$this->assertTrue(C::get($next)['user']['id']===null && C::get($next)['client_id']===100);
$invalid=Request::create('/api/tenancy/bootstrap','POST',[],[],[],[],json_encode(['_initiator'=>['user_id'=>-1,'user_name'=>'Owner']]));C::bind($invalid);$this->assertTrue(C::get($invalid)['user']['name']===null);
    }
}
