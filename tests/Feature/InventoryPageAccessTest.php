<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Tenancy\LiveTenantResolver;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

class InventoryPageAccessTest extends TestCase
{
    public function createApplication() {
        $app=require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.connections.mysql'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'database.connections.tenant'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],
            'sso.shared_secret'=>str_repeat('s',32),'sso.central_app_url'=>'http://authority.test', 'inventory.sso.enabled'=>false]);
        return $app;
    }
    private function member(): void {
        $user=new User; $user->forceFill(['id'=>7,'name'=>'Member','client_id'=>10]);
        $this->actingAs($user);
        $live=\Mockery::mock(LiveTenantResolver::class);
        $live->shouldReceive('organizationId')->andReturnUsing(fn($r)=>(int)$r->session()->get('selected_central_org_id',20));
        $this->app->instance(LiveTenantResolver::class,$live);
    }
    private function authority(bool $allowed, string $reason='user_not_assigned_to_project'):void {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['authority.test/*'=>fn($r)=>Http::response(['user_id'=>7,'organization_id'=>(int)$r['organization_id'],'app_key'=>'inventory','allowed'=>$allowed,'reason'=>$reason],$allowed?200:403)]);
    }
    public function test_stale_authenticated_session_cannot_render_any_protected_html_or_read_tenant_bootstrap(): void {
        $this->member();$this->authority(false);
        foreach(['/dashboard','/items','/warehouses','/settings','/solastock'] as $url) {
            $this->get($url)->assertForbidden()->assertDontSee('id="solastock-root"',false)->assertSee('has not been granted');
        }
        foreach(['/api/v1/tenant/status','/api/v1/meta','/api/v1/dashboard','/api/v1/items'] as $url) $this->getJson($url)->assertForbidden()->assertJsonPath('code','user_not_assigned_to_project')->assertJsonMissingPath('data');
        $this->postJson('/api/v1/items',['name'=>'Forbidden'])->assertForbidden()->assertJsonMissingPath('data');
    }
    public function test_revocation_rechecks_existing_session_before_next_html_response():void {
        $this->member();$this->authority(true);$this->withoutVite();
        $this->get('/dashboard')->assertOk()->assertSee('solastock-root');
        $this->authority(false);
        $this->get('/dashboard')->assertForbidden()->assertDontSee('id="solastock-root"',false);
    }
    public function test_org_selection_is_checked_and_authority_failure_is_not_setup_incomplete():void {
        $this->member();$this->withSession(['selected_central_org_id'=>25]);
        Http::fake(['authority.test/*'=>Http::response([],503)]);
        $this->get('/dashboard')->assertStatus(503)->assertDontSee('solastock-root')->assertSee('could not be checked');
        Http::assertSent(fn($r)=>$r['organization_id']===25);
    }
    public function test_incoming_handoff_cannot_be_ignored_in_favor_of_an_old_authorized_login(): void {
        $this->member();
        config(['tenancy.workspace_handoff_secret'=>'fixture-handoff-secret']);
        Http::fake(['authority.test/*'=>fn($r)=>Http::response(['user_id'=>(int)$r['user_id'],'organization_id'=>(int)$r['organization_id'],'app_key'=>'inventory','allowed'=>false,'reason'=>'user_not_assigned_to_project'],403)]);
        $payload=json_encode(['user_id'=>8,'client_id'=>10,'organization_id'=>20,'context'=>'inventory','exp'=>now()->addMinute()->timestamp,'nonce'=>'fixture-nonce']);
        $key=hash('sha256','fixture-handoff-secret',true);$iv=random_bytes(16);
        $cipher=openssl_encrypt($payload,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);
        $token=rtrim(strtr(base64_encode($iv.hash_hmac('sha256',$iv.$cipher,$key,true).$cipher),'+/','-_'),'=');
        $this->get('/dashboard?handoff='.urlencode($token))->assertForbidden()->assertDontSee('solastock-root');
        $this->assertGuest();
        Http::assertSent(fn($r)=>$r['user_id']===8);
    }
}
