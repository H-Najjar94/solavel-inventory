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
        // App access is Central's decision over HTTP; this organization owner holds SolaStock.
        config()->set('sso.shared_secret', str_repeat('c', 40));
        config()->set('sso.central_app_url', 'https://central.test');
        \Illuminate\Support\Facades\Http::fake(['https://central.test/api/sso/session-access' => fn ($request) => \Illuminate\Support\Facades\Http::response([
            'user_id' => $request['user_id'], 'organization_id' => $request['organization_id'], 'app_key' => $request['app_key'],
            'allowed' => $request['user_id'] === self::ACTOR, 'owner' => true, 'roles' => [],
        ], $request['user_id'] === self::ACTOR ? 200 : 403)]);
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
                'integration_capabilities' => ['connection_setup_readiness'=>true, 'connection_activation_delivery_entitled' => true],
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
        DB::connection('tenant')->table('integration_transport_worker_heartbeats')->insert(['worker_id'=>'isolated-ready-worker','queue_name'=>'test','state'=>'running','started_at'=>now(),'last_seen_at'=>now()]);
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

    public function test_both_apps_see_the_same_summary_from_ready_to_connect_to_connected(): void
    {
        // Before anything runs: SolaCount's context carries the one action, "Connect".
        $this->send(['action'=>'workspace.context'])->assertOk()
            ->assertJsonPath('data.connection_summary.state','ready_to_connect')
            ->assertJsonPath('data.connection_summary.action.kind','connect')
            ->assertJsonPath('data.connection_summary.organization','Isolated workspace');
        $response=$this->send(['action'=>'workspace.initialize'])->assertOk();
        $this->assertContains($response->json('data.summary.state'),['connected','needs_attention']);
        // SolaStock's own page reads the same summary.
        $this->assertSame($response->json('data.summary.state'),
            app(\App\Services\Integration\ConnectionSummary::class)->forOrganization(TenantTestManager::ORG_A)['state']);
        $this->assertNotContains($response->json('data.summary.state'),['ready_to_connect','preparing','needs_input']);
    }

    public function test_existing_records_ask_for_decisions_instead_of_preparing_forever(): void
    {
        DB::connection('tenant')->table('items')->insert(['organization_id'=>TenantTestManager::ORG_A,'sku'=>'SUM-1','name'=>'Existing item','is_active'=>1]);
        $this->send(['action'=>'workspace.context'])->assertOk()
            ->assertJsonPath('data.connection_summary.state','needs_input')
            ->assertJsonPath('data.connection_summary.action.kind','continue')
            ->assertJsonPath('data.connection_summary.reason','existing_activity_requires_review:items');
    }

    public function test_summary_states_follow_stored_evidence_and_offer_one_action(): void
    {
        $summaries = app(\App\Services\Integration\ConnectionSummary::class);
        $ready = ['readiness' => ['state' => 'CONNECTION_SETUP_INCOMPLETE', 'can_manage' => true], 'plan_requirement' => null, 'connection_wizard' => null];

        // Preparation running right now → progress with auto refresh; silent for too long → stopped, retry.
        $setting = IntegrationSetting::query()->create(['organization_id'=>TenantTestManager::ORG_A,'integration'=>'solabooks','solabooks_organization_id'=>14,
            'mode'=>'connected_pending_mapping','meta'=>['default_connection'=>['version'=>\App\Services\Integration\DefaultStockConnection::VERSION,'state'=>'preparing']]]);
        $running = $summaries->forOrganization(TenantTestManager::ORG_A, $ready);
        $this->assertSame(['preparing', 'none', true], [$running['state'], $running['action']['kind'], $running['action']['auto_refresh']]);
        $provisioning = $summaries->forOrganization(TenantTestManager::ORG_A, array_replace_recursive($ready, [
            'readiness' => ['state' => 'PROVISIONING_PENDING', 'can_manage' => true],
        ]));
        $this->assertSame(['finance_provisioning', 'finish_finance_setup', false], [
            $provisioning['state'], $provisioning['action']['kind'], $provisioning['action']['auto_refresh'],
        ]);
        DB::connection('tenant')->table('integration_settings')->where('id', $setting->id)->update(['updated_at' => now()->subMinutes(10)]);
        $stalled = $summaries->forOrganization(TenantTestManager::ORG_A, $ready);
        $this->assertSame(['failed', 'preparation_stalled', 'retry'], [$stalled['state'], $stalled['reason'], $stalled['action']['kind']]);
        $setting->delete();

        // An open guided setup resumes at its unfinished task; a finished one goes to the final review.
        $open = $summaries->forOrganization(TenantTestManager::ORG_A, array_replace($ready, ['connection_wizard' => [
            'run_uuid' => 'run-1', 'state' => 'draft_decisions', 'decisions_remaining' => 3, 'current_step' => 'required_decisions']]));
        $this->assertSame(['needs_input', 'continue', 'required_decisions', 3], [$open['state'], $open['action']['kind'], $open['task'], $open['progress']['decisions_remaining']]);
        $final = $summaries->forOrganization(TenantTestManager::ORG_A, array_replace($ready, ['connection_wizard' => ['run_uuid' => 'run-1', 'state' => 'activation_ready']]));
        $this->assertSame(['ready_to_activate', 'result_preview'], [$final['state'], $final['task']]);

        // Missing entitlement names the plan; people who may not manage are told to ask an admin.
        $plan = $summaries->forOrganization(TenantTestManager::ORG_A, array_replace($ready, ['plan_requirement' => ['missing' => 'inventory', 'bundled_with_finance_plans' => ['advanced']]]));
        $this->assertSame(['plan_required', 'manage_plans'], [$plan['state'], $plan['action']['kind']]);
        $viewer = $summaries->forOrganization(TenantTestManager::ORG_A, array_replace_recursive($ready, ['readiness' => ['can_manage' => false]]));
        $this->assertSame(['ready_to_connect', 'ask_admin'], [$viewer['state'], $viewer['action']['kind']]);

        // Connected → open the other app; a safety hold offers no action.
        $this->assertSame('open', $summaries->forOrganization(TenantTestManager::ORG_A, array_replace($ready, ['readiness' => ['state' => 'CONNECTED_READY', 'can_manage' => true]]))['action']['kind']);
        $this->assertSame('none', $summaries->forOrganization(TenantTestManager::ORG_A, array_replace($ready, ['readiness' => ['state' => 'MAINTENANCE_HOLD', 'can_manage' => true]]))['action']['kind']);
        $this->assertStringContainsString('/sso/finance/redirect?organization_id='.TenantTestManager::ORG_A, (string) $running['links']['solacount']);
    }

    public function test_connect_endpoint_requires_the_same_setup_authority_as_the_guided_setup(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.integration.connect');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('perm:inventory.integration.setup', $route->gatherMiddleware());
        $this->assertContains('integration.setup', $route->gatherMiddleware());
        $this->assertSame(app('router')->getRoutes()->getByName('api.v1.integration.wizard.start')->gatherMiddleware(), $route->gatherMiddleware());
    }

    public function test_failed_prepare_can_retry_without_duplicate_configuration(): void
    {
        $this->failPrepare=true;
        $failed=$this->send(['action'=>'workspace.initialize'])->assertConflict();
        // The stop is recorded, so both apps show "Connection stopped — Try again", not "preparing".
        $failed->assertJsonPath('summary.state','failed')->assertJsonPath('summary.action.kind','retry');
        $this->assertSame('failed',data_get(IntegrationSetting::query()->sole()->meta,'default_connection.state'));
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
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://finance.test'));
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
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://finance.test'));
    }

    public function test_finance_must_finish_before_automatic_mapping(): void
    {
        DB::connection('tenant')->table('organizations')->where('id',14)->update(['finance_setup_completed_at'=>null]);
        $this->send(['action'=>'workspace.initialize'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://finance.test'));
    }

    public function test_existing_stock_data_requires_manual_review(): void
    {
        \Tests\Support\StockTestFactory::item();
        $this->send(['action'=>'workspace.initialize'])->assertConflict()->assertJsonPath('message','existing_activity_requires_review:items');
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://finance.test'));
    }

    public function test_viewer_and_cross_organization_requests_cannot_initialize(): void
    {
        $this->send(['action'=>'workspace.initialize','finance_organization_id'=>999])->assertForbidden();
        DB::connection('mysql')->table('user_organizations')->where('user_id',self::ACTOR)->update(['role'=>'viewer']);
        $this->app->forgetInstance(\App\Services\Access\InventoryPermissionService::class);
        $this->send(['action'=>'workspace.initialize'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://finance.test'));
    }

    public function test_missing_required_role_never_becomes_ready(): void
    {
        unset($this->planAccounts['cogs']);
        $this->send(['action'=>'workspace.initialize'])->assertConflict()->assertJsonPath('message','default_required_role_missing:cogs');
        $this->assertSame(0,IntegrationSetting::query()->count());
    }

    public function test_setup_cta_path_resolves_to_the_real_stock_spa(): void
    {
        $route=app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/integrations/solacount','GET'));
        $this->assertSame('integrations/{any?}',$route->uri());
        $this->assertStringContainsString("path: 'integrations/solacount'",file_get_contents(resource_path('js/solastock/router/router.jsx')));
    }

    private function counts(): array
    {
        $result=[];
        foreach(['accounts','integration_settings','integration_organization_mappings','integration_account_mappings',
            'integration_master_data_mappings','stock_ledger','integration_outbox_events'] as $table) $result[$table]=DB::connection('tenant')->table($table)->count();
        return $result;
    }

}
