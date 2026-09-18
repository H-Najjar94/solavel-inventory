<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Warehouse;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class DefaultStockConnectionTest extends TestCase
{
    use TenantAware { tearDown as tenantTearDown; }

    private const SECRET = 'isolated-workspace-signature-secret-0000000000';
    private const ACTOR = 980071;
    private const CLIENT = 980072;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('finance_workspace.secret', self::SECRET);
        config()->set('inventory.demo_tenant.enabled', false);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        foreach (['activation_enabled','production_phase6b_enabled','receiver_confirmed_enabled'] as $gate) config()->set('integration_connection_wizard.'.$gate,true);
        config()->set('inventory_entitlements.feature_enforcement', true);
        $this->centralFixtureSchema();
        // The disposable shared-Finance projection must satisfy the actual
        // onboarding contract; an application grant alone is not readiness.
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
            'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $central = DB::connection('mysql');
        $central->beginTransaction();
        $central->table('clients')->insert(['id' => self::CLIENT, 'is_active' => true]);
        $central->table('organizations')->updateOrInsert(['id' => TenantTestManager::ORG_A], ['client_id' => self::CLIENT,
            'name' => 'Isolated workspace', 'database_name' => 'workspace-fixture', 'is_active' => true]);
        $central->table('users')->insert(['id' => self::ACTOR, 'client_id' => self::CLIENT,
            'name' => 'Synthetic owner', 'email' => 'workspace-owner@example.invalid', 'password' => 'not-a-login', 'status' => 'active']);
        $central->table('user_organizations')->insert(['user_id' => self::ACTOR, 'organization_id' => TenantTestManager::ORG_A, 'role' => 'client_owner', 'status' => 'active']);
        foreach (['finance' => 980073, 'inventory' => 980074] as $slug => $id) {
            $central->table('projects')->insert(['id' => $id, 'slug' => $slug, 'is_active' => true]);
            $central->table('organization_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'is_active' => true]);
            $central->table('user_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'user_id' => self::ACTOR, 'is_active' => true]);
        }
        $central->table('entitlement_state_snapshots')->updateOrInsert(['organization_id' => TenantTestManager::ORG_A], [ 'underlying_subscription_state' => 'paid_active',
            'effective_access_state' => 'paid_active', 'state_hash' => str_repeat('a', 64),
            'state_payload' => json_encode([
                'client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
                'integration_capabilities' => ['connection_activation_delivery_entitled' => true],
                'applications' => ['finance' => ['accessible' => true, 'commercially_entitled' => true],
                    'inventory' => ['accessible' => true, 'commercially_entitled' => true]],
            ]),
        ]);
        $cache = $this->createStub(EntitlementsCache::class);
        $cache->method('currentClientId')->willReturn(self::CLIENT);
        $cache->method('getProjectSnapshot')->willReturn(['accessible' => true, 'commercially_entitled' => true,
            'tier' => 'enterprise', 'access_until' => now()->addMonth()->toIso8601String(),
            'allowed_features' => ['stock.locations_bins', 'stock.transfers', 'stock.counts']]);
        $this->app->instance(EntitlementsCache::class, $cache);
        // Keep the rollback transaction and resolve only the fixed disposable database.
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('resolveDatabaseName')->with(self::CLIENT)->willReturn('solastock_test_a');
        $tenants->method('useTenant')->with(TenantTestManager::ORG_A, 'solastock_test_a');
        $this->app->instance(TenantManager::class, $tenants);
        config(['services.solabooks.api_base_url'=>'https://finance.test', 'finance_workspace.secret'=>self::SECRET]);
        foreach (\App\Services\Integration\AccountRolePolicy::ROLE_TYPES as $role=>$types) {
            $id=800+count($this->planAccounts);
            DB::connection('tenant')->table('accounts')->insert(['id'=>$id,'organization_id'=>14,'code'=>(string)$id,
                'name'=>json_encode(['en'=>$role]),'type'=>$types[0],'system_key'=>$role,'account_role'=>$role]);
            $this->planAccounts[$role]=['id'=>$id,'code'=>(string)$id,'name'=>json_encode(['en'=>$role]),
                'type'=>$types[0],'system_key'=>$role,'account_role'=>$role];
        }
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->fakeFinance();
    }
    protected function tearDown(): void
    {
        DB::connection('mysql')->rollBack();
        $this->tenantTearDown();
    }
    private function send(array $input, ?string $nonce = null)
    {
        $payload = array_replace(['client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
            'finance_organization_id' => 14, 'actor_id' => self::ACTOR], $input);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce ??= bin2hex(random_bytes(24));
        return $this->call('POST', WorkspaceSignature::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WORKSPACE_TIMESTAMP' => $timestamp, 'HTTP_X_WORKSPACE_NONCE' => $nonce,
            'HTTP_X_WORKSPACE_SIGNATURE' => WorkspaceSignature::sign($body, $timestamp, $nonce, self::SECRET),
        ], $body);
    }
    private function centralFixtureSchema(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasTable('app_permission_grants')) $schema->create('app_permission_grants',function($t){
            $t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('user_id')->nullable();
            $t->string('app_key');$t->string('permission_key');$t->string('effect');$t->timestamp('expires_at')->nullable();
        });
        if (! $schema->hasColumn('users', 'deleted_at')) {
            $schema->table('users', fn ($t) => $t->softDeletes());
        }
        foreach (['clients', 'projects', 'organization_projects', 'user_projects'] as $name) {
            if (! $schema->hasTable($name)) {
                $schema->create($name, function ($t) use ($name) {
                    $t->id(); $t->boolean('is_active')->default(true); $t->softDeletes();
                    if ($name === 'projects') { $t->string('slug'); }
                    if (in_array($name, ['organization_projects', 'user_projects'], true)) {
                        $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('organization_id');
                    }
                    if ($name === 'user_projects') { $t->unsignedBigInteger('user_id'); }
                });
            }
        }
    }

    private array $planAccounts = [];
    private bool $failPrepare = false;

    private function fakeFinance(): void
    {
        \Illuminate\Support\Facades\Http::fake(['https://finance.test/api/internal/stock-connection'=>function ($request) {
            if ($request['action'] === 'connection.default-plan') {
                return \Illuminate\Support\Facades\Http::response(['data'=>['version'=>'default-stock-connection.v1',
                    'organization_id'=>TenantTestManager::ORG_A,'finance_organization_id'=>14,'accounts'=>$this->planAccounts,
                    'currency'=>['base_currency_code'=>'JOD','enabled_currency_codes'=>['JOD'],'money_scale'=>2,'rate_scale'=>8,'inventory_valuation_basis'=>'finance_base.v1']]]);
            }
            if ($this->failPrepare) return \Illuminate\Support\Facades\Http::response(['message'=>'synthetic_retry'],503);
            IntegrationOrganizationMapping::query()->firstOrCreate(['central_organization_id'=>TenantTestManager::ORG_A], [
                'mapping_uuid'=>(string)Str::uuid(),'central_client_id'=>self::CLIENT,'tenant_database_identity'=>'solastock_test_a',
                'finance_organization_id'=>14,'solastock_organization_id'=>TenantTestManager::ORG_A,'contract_version'=>'solastock-journal.v2',
                'status'=>'verified_hold','activation_state'=>'maintenance_hold','base_currency_code'=>'JOD',
                'currency_verified_at'=>now(),'verified_at'=>now(),'v2_key_scope_status'=>'provisioned_held','current_v2_signing_key_id'=>1]);
            return \Illuminate\Support\Facades\Http::response(['data'=>['api_key'=>'test-api','signing_key_id'=>1,'signing_secret'=>'test-signing-secret']]);
        }]);
    }

    public function test_new_advanced_customer_solastock_ready_and_duplicate_execution_creates_no_financial_activity(): void
    {
        $response=$this->send(['action'=>'workspace.initialize']);
        $this->assertSame(200,$response->status(),$response->getContent());
        $response->assertJsonPath('data.status','ready')->assertJsonPath('data.changed',true);
        $this->send(['action'=>'workspace.context'])->assertOk()->assertJsonPath('data.ready',true)->assertJsonPath('data.writable',true);
        $report=$this->send(['action'=>'workspace.connection'])->assertOk()->assertJsonPath('data.connection_state','connected')
            ->assertJsonPath('data.accounting.complete',true)->assertJsonPath('data.configured_automatically',true)->json('data.accounting.accounts');
        $this->assertCount(count($this->planAccounts),$report);
        foreach($report as $row){$this->assertNotNull($row['account_id']);$this->assertTrue($row['valid']);}
        $native=app(\App\Services\Integration\IntegrationStatusService::class)->status(TenantTestManager::ORG_A);
        $this->assertSame('connected',$native['connection_state']);
        $this->assertTrue($native['configured_automatically']);
        $counts=$this->counts();
        $this->send(['action'=>'workspace.initialize'])->assertOk()->assertJsonPath('data.changed',false);
        $this->assertSame($counts,$this->counts());
        $this->assertSame(0,DB::connection('tenant')->table('stock_ledger')->count());
        $this->assertSame(0,DB::connection('tenant')->table('integration_outbox_events')->count());
        $this->assertSame(0,DB::connection('tenant')->table('opening_stock_entries')->count());
    }

    public function test_failed_prepare_can_retry_without_duplicate_configuration(): void
    {
        $this->failPrepare=true;
        $this->send(['action'=>'workspace.initialize'])->assertConflict();
        $this->assertSame('connected_pending_mapping',IntegrationSetting::query()->sole()->mode);
        $this->assertSame(0,DB::connection('tenant')->table('integration_account_mappings')->count());
        $this->failPrepare=false;
        $this->send(['action'=>'workspace.initialize'])->assertOk()->assertJsonPath('data.status','ready');
        $this->assertSame(1,IntegrationSetting::query()->count());
        $this->assertSame(1,IntegrationOrganizationMapping::query()->count());
    }

    public function test_custom_connection_is_preserved(): void
    {
        IntegrationSetting::query()->create(['organization_id'=>TenantTestManager::ORG_A,'integration'=>'solabooks',
            'mode'=>'paused','solabooks_organization_id'=>14,'meta'=>['custom'=>'retained']]);
        $this->send(['action'=>'workspace.initialize'])->assertOk()->assertJsonPath('data.status','manual_review');
        $this->assertSame('paused',IntegrationSetting::query()->sole()->mode);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_missing_or_cross_organization_account_fails_before_activation(): void
    {
        DB::connection('tenant')->table('accounts')->where('id',$this->planAccounts['inventory_asset']['id'])->update(['organization_id'=>999999]);
        $this->send(['action'=>'workspace.initialize'])->assertConflict()->assertJsonPath('message','default_account_invalid:inventory_asset');
        $this->assertSame(0,IntegrationSetting::query()->count());
    }

    public function test_inactive_seeded_account_fails_validation(): void
    {
        DB::connection('tenant')->table('accounts')->where('id',$this->planAccounts['grni']['id'])->update(['is_active'=>false]);
        $this->send(['action'=>'workspace.initialize'])->assertConflict();
        $this->assertSame(0,IntegrationSetting::query()->count());
    }

    public function test_non_entitled_organization_cannot_initialize(): void
    {
        DB::connection('mysql')->table('organization_projects')->where('project_id',980074)->update(['is_active'=>false]);
        $this->send(['action'=>'workspace.initialize'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_finance_must_finish_before_automatic_mapping(): void
    {
        DB::connection('tenant')->table('organizations')->where('id',14)->update(['finance_setup_completed_at'=>null]);
        $this->send(['action'=>'workspace.initialize'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_existing_stock_data_requires_manual_review(): void
    {
        \Tests\Support\StockTestFactory::item();
        $this->send(['action'=>'workspace.initialize'])->assertConflict()->assertJsonPath('message','existing_activity_requires_review:items');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_viewer_and_cross_organization_requests_cannot_initialize(): void
    {
        $this->send(['action'=>'workspace.initialize','finance_organization_id'=>999])->assertForbidden();
        DB::connection('mysql')->table('user_organizations')->where('user_id',self::ACTOR)->update(['role'=>'viewer']);
        $this->app->forgetInstance(\App\Services\Access\InventoryPermissionService::class);
        $this->send(['action'=>'workspace.initialize'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_missing_required_role_never_becomes_ready(): void
    {
        unset($this->planAccounts['cogs']);
        $this->send(['action'=>'workspace.initialize'])->assertConflict()->assertJsonPath('message','default_required_role_missing:cogs');
        $this->assertSame(0,IntegrationSetting::query()->count());
    }

    public function test_setup_cta_path_resolves_to_the_real_stock_spa(): void
    {
        $route=app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/integrations/solabooks','GET'));
        $this->assertSame('integrations/{any?}',$route->uri());
        $this->assertStringContainsString("path: 'integrations/solabooks'",file_get_contents(resource_path('js/solastock/router/router.jsx')));
    }

    private function counts(): array
    {
        $result=[];
        foreach(['accounts','integration_settings','integration_organization_mappings','integration_account_mappings',
            'integration_master_data_mappings','stock_ledger','integration_outbox_events'] as $table) $result[$table]=DB::connection('tenant')->table($table)->count();
        return $result;
    }

}
