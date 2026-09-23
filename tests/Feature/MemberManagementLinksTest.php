<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\MemberManagementController;
use App\Http\Middleware\VerifySolavelSyncSignature;
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class MemberManagementLinksTest extends TestCase
{
    private bool $owner = true;

    private array $revoked = [];

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config(['database.default' => 'tenant', 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.registry' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenancy.central_connection' => 'registry', 'inventory.demo_tenant.enabled' => false]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Schema::connection('registry')->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::connection('registry')->create('organizations', function (Blueprint $t) {
            $t->id();
            $t->integer('client_id');
            $t->boolean('is_active');
            $t->softDeletes();
        });
        Schema::connection('registry')->create('user_organizations', function (Blueprint $t) {
            $t->integer('user_id');
            $t->integer('organization_id');
            $t->string('status');
            $t->string('role');
        });
        DB::connection('registry')->table('users')->insert([['id' => 700, 'name' => 'Owner'], ['id' => 900, 'name' => 'Selected member'], ['id' => 9, 'name' => 'Other org member']]);
        DB::connection('registry')->table('organizations')->insert([['id' => 101, 'client_id' => 10, 'is_active' => true], ['id' => 102, 'client_id' => 10, 'is_active' => true]]);
        DB::connection('registry')->table('user_organizations')->insert([['user_id' => 700, 'organization_id' => 101, 'status' => 'active', 'role' => 'client_owner'], ['user_id' => 900, 'organization_id' => 101, 'status' => 'active', 'role' => 'client_member'], ['user_id' => 700, 'organization_id' => 102, 'status' => 'active', 'role' => 'client_member'], ['user_id' => 9, 'organization_id' => 102, 'status' => 'active', 'role' => 'client_member']]);
        Schema::connection('tenant')->create('inventory_custom_roles', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->string('key');
            $t->string('name');
            $t->json('permissions');
            $t->boolean('is_active');
        });
        Schema::connection('tenant')->create('inventory_user_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('role_id');
        });
        Schema::connection('tenant')->create('inventory_user_warehouses', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('warehouse_id');
        });
        DB::connection('tenant')->table('inventory_user_warehouses')->insert(['organization_id' => 101, 'user_id' => 900, 'warehouse_id' => 55]);
        $context = new OrganizationContext;
        $context->set(101);
        $this->app->instance(OrganizationContext::class, $context);
        $access = \Mockery::mock(CentralAppAccess::class);
        $access->shouldReceive('decision')->andReturnUsing(fn ($user, $org) => ['allowed' => ! in_array($user, $this->revoked), 'owner' => $this->owner && $user === 700 && $org === 101, 'roles' => [$user === 700 ? 'stock_manager' : 'warehouse_user']]);
        $this->app->instance(CentralAppAccess::class, $access);
        $tenant = \Mockery::mock(TenantManager::class)->makePartial();
        $tenant->shouldReceive('switchToDatabase')->andReturnNull();
        $this->app->instance(TenantManager::class, $tenant);
        $this->app['router']->setRoutes(new RouteCollection);
        Route::get('/audit/manage/{centralOrg}/{centralMember}', MemberManagementController::class);
        Route::get('/settings', [MemberManagementController::class, 'settings'])->name('inventory.settings');
        Route::get('/audit/warehouse/{userId}', [SettingsController::class, 'warehouseAssignments']);
        Route::put('/audit/warehouse/{userId}', [SettingsController::class, 'syncWarehouseAssignments']);
        Route::post('/api/tenancy/member-management', \App\Http\Controllers\Api\Tenancy\MemberManagementController::class)->middleware(VerifySolavelSyncSignature::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['url']->setRoutes($this->app['router']->getRoutes());
        config(['solavel_sync.secret' => str_repeat('s', 40), 'solavel_sync.use_signed_sync' => true, 'cache.default' => 'array']);
        $this->actingAs(User::find(700));
    }

    public function test_owner_opens_existing_settings_with_resolved_member_and_preserved_assignments(): void
    {
        $before = DB::connection('tenant')->table('inventory_user_warehouses')->get()->toJson();
        $this->getJson('/audit/manage/101/900')->assertRedirect('/settings?central_org=101&central_member=900');
        $this->get('/settings?central_org=101&central_member=900')->assertOk()->assertViewHas('memberManagement', fn ($c) => $c['id'] === 900 && $c['name'] === 'Selected member' && $c['warehouse_ids']->all() === [55]);
        $this->assertSame($before, DB::connection('tenant')->table('inventory_user_warehouses')->get()->toJson());
        $this->assertAuthenticatedAs(User::find(700));
    }

    public function test_application_mount_prefix_is_added_once_and_target_context_is_preserved(): void
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('https://solavel.com/inventory');
        \Illuminate\Support\Facades\URL::forceScheme('https');
        $this->getJson('https://solavel.com/audit/manage/101/900')
            ->assertRedirect('https://solavel.com/inventory/settings?central_org=101&central_member=900');
        $this->assertAuthenticatedAs(User::find(700));
        $this->assertSame([55], DB::connection('tenant')->table('inventory_user_warehouses')->pluck('warehouse_id')->all());
    }

    public function test_wrong_org_tampered_target_and_context_are_denied(): void
    {
        $this->getJson('/audit/manage/101/9')->assertNotFound();
        $this->getJson('/audit/manage/102/9')->assertForbidden();
        $this->getJson('/audit/warehouse/9?central_org=101&central_member=900')->assertNotFound();
        $this->getJson('/audit/warehouse/900?central_org=102&central_member=900')->assertForbidden();
    }

    public function test_inventory_manager_and_ordinary_member_cannot_manage_settings(): void
    {
        $this->owner = false;
        $this->getJson('/audit/manage/101/900')->assertForbidden();
        $this->actingAs(User::find(900))->getJson('/audit/manage/101/900')->assertForbidden();
    }

    public function test_revoked_actor_and_target_are_denied(): void
    {
        $this->revoked = [700];
        $this->getJson('/audit/manage/101/900')->assertForbidden();
        $this->revoked = [900];
        $this->getJson('/audit/manage/101/900')->assertForbidden();
    }

    public function test_signed_inspection_uses_existing_native_permissions_and_exact_target_membership(): void
    {
        $this->inspect()->assertOk()->assertJsonPath('targets.900.allowed', true)->assertJsonPath('targets.9.allowed', false);
        $this->owner = false;
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->inspect()->assertJsonPath('targets.900.allowed', false);
    }

    public function test_unsigned_inspection_is_denied(): void
    {
        $this->postJson('/api/tenancy/member-management', [])->assertForbidden();
    }

    public function test_assignment_mutation_rechecks_target_context_and_revocation(): void
    {
        $before = DB::connection('tenant')->table('inventory_user_warehouses')->get()->toJson();
        $this->putJson('/audit/warehouse/900?central_org=101&central_member=700', ['warehouse_ids' => []])->assertForbidden();
        $this->putJson('/audit/warehouse/9?central_org=101&central_member=9', ['warehouse_ids' => []])->assertNotFound();
        $this->revoked = [700];
        $this->putJson('/audit/warehouse/900?central_org=101&central_member=900', ['warehouse_ids' => []])->assertForbidden();
        $this->assertSame($before, DB::connection('tenant')->table('inventory_user_warehouses')->get()->toJson());
    }

    private function inspect()
    {
        $body = json_encode(['client_id' => 10, 'organization_id' => 101, 'actor_id' => 700, 'target_ids' => [900, 9], 'nonce' => bin2hex(random_bytes(8))]);
        $time = (string) time();

        return $this->call('POST', '/api/tenancy/member-management', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SOLAVEL_TIMESTAMP' => $time, 'HTTP_X_SOLAVEL_SIGNATURE' => 'sha256='.hash_hmac('sha256', $time.'.'.$body, str_repeat('s', 40))], $body);
    }
}
