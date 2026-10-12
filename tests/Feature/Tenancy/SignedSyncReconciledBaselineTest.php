<?php
namespace Tests\Feature\Tenancy;

use App\Http\Middleware\VerifySolavelSyncSignature;
use App\Services\SystemEvents\VerifiedProvisioningContext;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SignedSyncReconciledBaselineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['solavel_sync.use_signed_sync'=>true,'solavel_sync.secret'=>str_repeat('r',48),
            'solavel_sync.allowed_client_ids'=>['99'],'cache.default'=>'array','observability.enabled'=>false]);
    }
    private function request(int $client, bool $valid = true): Request
    {
        $body=json_encode(['client_id'=>$client,'organization_id'=>101,'nonce'=>bin2hex(random_bytes(8)),
            '_initiator'=>['user_id'=>42,'user_name'=>'Isolated actor']]);
        $timestamp=(string)time();
        $r=Request::create('/api/tenancy/bootstrap','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],$body);
        $r->headers->set('X-Solavel-Timestamp',$timestamp);
        $r->headers->set('X-Solavel-Signature',$valid ? hash_hmac('sha256',$timestamp.'.'.$body,str_repeat('r',48)) : str_repeat('0',64));
        return $r;
    }
    public function test_invalid_signature_cannot_bind_an_actor_or_enter_controller(): void
    {
        $r=$this->request(99,false);$entered=false;
        $response=(new VerifySolavelSyncSignature)->handle($r,function() use (&$entered){$entered=true;return response()->json([]);});
        $this->assertSame(403,$response->getStatusCode());$this->assertFalse($entered);
        $this->assertSame([],VerifiedProvisioningContext::get($r));
    }
    public function test_valid_signature_preserves_scope_and_replay_cannot_enter_controller_twice(): void
    {
        $r=$this->request(99);$count=0;$next=function($request) use (&$count){$count++;$this->assertSame(99,VerifiedProvisioningContext::get($request)['client_id']);return response()->json(['ok'=>true]);};
        $middleware=new VerifySolavelSyncSignature;
        $this->assertSame(200,$middleware->handle($r,$next)->getStatusCode());
        $this->assertSame('sync_replay_detected',$middleware->handle($r,$next)->getData(true)['code']);
        $this->assertSame(1,$count);
    }
    public function test_signed_but_unallowed_client_remains_denied(): void
    {
        $r=$this->request(100);$entered=false;
        $response=(new VerifySolavelSyncSignature)->handle($r,function() use (&$entered){$entered=true;return response()->json([]);});
        $this->assertSame(403,$response->getStatusCode());$this->assertFalse($entered);
        $this->assertSame('sync_client_not_allowed',$response->getData(true)['code']);
    }
}
