<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CustomRoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TeamAccessController;
use App\Http\Middleware\VerifySolavelSyncSignature;
use App\Models\User;
use App\Services\Access\AppAuthority;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\WarehouseAccessService;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * SolaStock Administrator (Central app role `inventory_administrator`) holds
 * the same full SolaStock authority as the organization owner, and the Team
 * access list endpoint.
 *
 * Users (org 101): 700 owner, 800 administrator, 801 administrator,
 * 900 member with a custom role holding manage_settings (warehouse 11),
 * 901 warehouse operator (warehouse 11), 902 warehouse operator (no
 * warehouses), 903 member Central does not admit to SolaStock, 9 other org.
 */
class InventoryAdministratorTeamAccessTest extends TestCase
{
    /** @var array<int, array> */
    private array $decisions = [];

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
        Schema::connection('registry')->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
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
            $t->string('status')->nullable();
            $t->string('role');
        });
        foreach ([700 => 'Owner', 800 => 'Admin One', 801 => 'Admin Two', 900 => 'Settings Manager', 901 => 'Operator', 902 => 'Unassigned Operator', 903 => 'No Stock Access', 9 => 'Other Org'] as $id => $name) {
            DB::connection('registry')->table('users')->insert(['id' => $id, 'name' => $name, 'email' => "u{$id}@example.test"]);
        }
        DB::connection('registry')->table('organizations')->insert([['id' => 101, 'client_id' => 10, 'is_active' => true], ['id' => 102, 'client_id' => 10, 'is_active' => true]]);
        foreach ([700 => 'client_owner', 800 => 'client_member', 801 => 'client_member', 900 => 'client_member', 901 => 'client_member', 902 => 'client_member', 903 => 'client_member'] as $id => $role) {
            DB::connection('registry')->table('user_organizations')->insert(['user_id' => $id, 'organization_id' => 101, 'status' => 'active', 'role' => $role]);
        }
        DB::connection('registry')->table('user_organizations')->insert(['user_id' => 9, 'organization_id' => 102, 'status' => 'active', 'role' => 'client_member']);

        Schema::connection('tenant')->create('inventory_custom_roles', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->string('key');
            $t->string('name');
            $t->json('permissions');
            $t->boolean('is_active');
            $t->timestamps();
        });
        Schema::connection('tenant')->create('inventory_user_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('role_id');
            $t->integer('assigned_by')->nullable();
            $t->timestamps();
        });
        Schema::connection('tenant')->create('inventory_user_warehouses', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->integer('user_id');
            $t->integer('warehouse_id');
            $t->integer('assigned_by')->nullable();
            $t->timestamps();
        });
        Schema::connection('tenant')->create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->integer('organization_id');
            $t->string('code')->nullable();
            $t->string('name');
            $t->softDeletes();
        });
        DB::connection('tenant')->table('warehouses')->insert([
            ['id' => 11, 'organization_id' => 101, 'code' => 'MAIN', 'name' => 'Main Warehouse'],
            ['id' => 12, 'organization_id' => 101, 'code' => 'NORTH', 'name' => 'North Depot'],
            ['id' => 21, 'organization_id' => 102, 'code' => 'OTHER', 'name' => 'Other Org Warehouse'],
        ]);
        DB::connection('tenant')->table('inventory_custom_roles')->insert([
            ['id' => 1, 'organization_id' => 101, 'key' => 'settings_lead', 'name' => 'Settings Lead', 'permissions' => json_encode(['inventory.view_stock', 'inventory.manage_settings']), 'is_active' => true],
            ['id' => 2, 'organization_id' => 101, 'key' => 'counter', 'name' => 'Counter', 'permissions' => json_encode(['inventory.view_stock']), 'is_active' => true],
        ]);
        DB::connection('tenant')->table('inventory_user_role_assignments')->insert([
            ['organization_id' => 101, 'user_id' => 900, 'role_id' => 1],
            // A leftover assignment must not downgrade an administrator.
            ['organization_id' => 101, 'user_id' => 800, 'role_id' => 2],
        ]);
        DB::connection('tenant')->table('inventory_user_warehouses')->insert([
            ['organization_id' => 101, 'user_id' => 900, 'warehouse_id' => 11],
            ['organization_id' => 101, 'user_id' => 901, 'warehouse_id' => 11],
        ]);

        $this->decisions = [
            700 => ['allowed' => true, 'owner' => true, 'roles' => []],
            800 => ['allowed' => true, 'owner' => false, 'roles' => ['inventory_administrator']],
            801 => ['allowed' => true, 'owner' => false, 'roles' => ['inventory_administrator']],
            900 => ['allowed' => true, 'owner' => false, 'roles' => ['warehouse_manager']],
            901 => ['allowed' => true, 'owner' => false, 'roles' => ['warehouse_operator']],
            902 => ['allowed' => true, 'owner' => false, 'roles' => ['warehouse_operator']],
            903 => ['allowed' => false, 'reason' => 'access_required'],
            9 => ['allowed' => true, 'owner' => false, 'roles' => ['warehouse_operator']],
        ];
        $context = new OrganizationContext;
        $context->set(101);
        $this->app->instance(OrganizationContext::class, $context);
        $access = \Mockery::mock(CentralAppAccess::class);
        $access->shouldReceive('decision')->andReturnUsing(fn ($user, $org) => $org === 101 || $user === 9 ? ($this->decisions[$user] ?? ['allowed' => false]) : ['allowed' => false]);
        $this->app->instance(CentralAppAccess::class, $access);
        $tenant = \Mockery::mock(TenantManager::class)->makePartial();
        $tenant->shouldReceive('switchToDatabase')->andReturnNull();
        $this->app->instance(TenantManager::class, $tenant);

        $this->app['router']->setRoutes(new RouteCollection);
        Route::get('/api/v1/team-access', [TeamAccessController::class, 'index'])->middleware('perm:inventory.manage_settings');
        Route::put('/api/v1/settings/warehouse-assignments/{userId}', [SettingsController::class, 'syncWarehouseAssignments'])->middleware('perm:inventory.manage_settings');
        Route::post('/api/v1/settings/custom-role-assignments', [CustomRoleController::class, 'assign'])->middleware('perm:inventory.manage_settings');
        Route::post('/api/tenancy/member-management', \App\Http\Controllers\Api\Tenancy\MemberManagementController::class)->middleware(VerifySolavelSyncSignature::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        config(['solavel_sync.secret' => str_repeat('s', 40), 'solavel_sync.use_signed_sync' => true, 'cache.default' => 'array', 'inventory_entitlements.feature_enforcement' => false]);
    }

    private function actAs(int $id): void
    {
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->actingAs(User::find($id));
    }

    public function test_helper_requires_admission_and_matches_owner_or_administrator_only(): void
    {
        $this->assertTrue(AppAuthority::full(['allowed' => true, 'owner' => true]));
        $this->assertTrue(AppAuthority::full(['allowed' => true, 'roles' => ['warehouse_operator', 'inventory_administrator']]));
        $this->assertFalse(AppAuthority::full(['allowed' => false, 'owner' => true, 'roles' => ['inventory_administrator']]));
        $this->assertFalse(AppAuthority::full(['allowed' => true, 'roles' => ['stock_manager', 'scoped_inventory_manager']]));
    }

    public function test_administrator_gets_full_permissions_and_unrestricted_warehouses_despite_leftover_custom_role(): void
    {
        $this->actAs(800);
        $permissions = app(InventoryPermissionService::class);
        $admin = User::find(800);
        foreach (['inventory.manage_settings', 'inventory.manage_items', 'inventory.export_reports', 'inventory.approve_purchase_orders'] as $permission) {
            $this->assertTrue($permissions->can($admin, $permission), $permission);
        }
        $all = array_diff($permissions->all(), ['inventory.integration.setup', 'inventory.integration.connection_manage', 'inventory.integration.accounting_review']);
        $this->assertSame([], array_values(array_diff($all, $permissions->permissionsFor($admin))));
        $this->assertNull(app(WarehouseAccessService::class)->allowedIds(800));

        // Same as the owner.
        $this->assertNull(app(WarehouseAccessService::class)->allowedIds(700));
        // An operator stays restricted to explicit rows.
        $this->assertSame([11], app(WarehouseAccessService::class)->allowedIds(901));
        $this->assertSame([], app(WarehouseAccessService::class)->allowedIds(902));
    }

    public function test_central_explicit_denial_still_binds_an_administrator(): void
    {
        $this->decisions[800]['grants'] = [['effect' => 'deny', 'permission_key' => 'inventory.manage_items', 'scope_type' => 'organization']];
        $this->actAs(800);
        $this->assertFalse(app(InventoryPermissionService::class)->can(User::find(800), 'inventory.manage_items'));
        $this->assertTrue(app(InventoryPermissionService::class)->can(User::find(800), 'inventory.manage_settings'));
    }

    public function test_revoked_administrator_role_falls_back_to_scoped_access(): void
    {
        $this->decisions[800] = ['allowed' => true, 'owner' => false, 'roles' => ['warehouse_operator']];
        $this->actAs(800);
        $this->assertFalse(app(InventoryPermissionService::class)->can(User::find(800), 'inventory.manage_settings'));
        $this->assertSame([], app(WarehouseAccessService::class)->allowedIds(800));
    }

    public function test_administrator_cannot_be_managed_by_a_non_owner_but_can_manage_members(): void
    {
        // 900 holds manage_settings through a custom role but is not the owner.
        $this->actAs(900);
        $this->putJson('/api/v1/settings/warehouse-assignments/800', ['warehouse_ids' => [11]])->assertForbidden();
        $this->postJson('/api/v1/settings/custom-role-assignments', ['user_id' => 800, 'role_id' => 2])->assertForbidden();
        $this->assertSame(0, DB::connection('tenant')->table('inventory_user_warehouses')->where('user_id', 800)->count());

        // Another administrator cannot edit an administrator either.
        $this->actAs(801);
        $this->putJson('/api/v1/settings/warehouse-assignments/800', ['warehouse_ids' => [11]])->assertForbidden();
        // Nor the owner.
        $this->putJson('/api/v1/settings/warehouse-assignments/700', ['warehouse_ids' => [11]])->assertForbidden();

        // An administrator manages ordinary members across all warehouses.
        $this->putJson('/api/v1/settings/warehouse-assignments/902', ['warehouse_ids' => [11, 12]])->assertOk();
        $this->assertSame([11, 12], DB::connection('tenant')->table('inventory_user_warehouses')->where('user_id', 902)->orderBy('warehouse_id')->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_tenancy_member_management_lists_the_administrator_role_and_protects_administrators(): void
    {
        $response = $this->inspect(900, [800, 901])->assertOk();
        $this->assertContains('inventory_administrator', $response->json('roles'));
        $this->assertContains('stock_manager', $response->json('roles'));
        $response->assertJsonPath('targets.800.allowed', false)->assertJsonPath('targets.901.allowed', true);

        $this->inspect(800, [901, 700])->assertJsonPath('targets.901.allowed', true)->assertJsonPath('targets.700.allowed', false);
    }

    public function test_team_list_returns_admitted_members_with_warehouse_names_and_edit_rules(): void
    {
        $this->actAs(800);
        $response = $this->getJson('/api/v1/team-access')->assertOk();
        $members = collect($response->json('data.members'))->keyBy('id');

        $this->assertEqualsCanonicalizing([700, 800, 801, 900, 901, 902], $members->keys()->all());
        $this->assertFalse($members->has(903)); // not admitted by Central
        $this->assertFalse($members->has(9));   // other organization

        $this->assertTrue($members[700]['full_access']);
        $this->assertTrue($members[700]['is_owner']);
        $this->assertFalse($members[700]['editable']);
        $this->assertTrue($members[801]['full_access']);
        $this->assertFalse($members[801]['editable']);
        $this->assertTrue($members[800]['is_self']);
        $this->assertFalse($members[800]['editable']);

        $this->assertSame(['Main Warehouse'], array_column($members[901]['warehouses'], 'name'));
        $this->assertSame(['warehouse_operator'], $members[901]['roles']);
        $this->assertTrue($members[901]['editable']);
        $this->assertSame([], $members[902]['warehouses']);
        $this->assertSame(0, $members[902]['hidden_warehouse_count']);
        $this->assertSame('Settings Lead', $members[900]['custom_role']['name']);
        $this->assertSame('u901@example.test', $members[901]['email']);

        $this->assertEqualsCanonicalizing(['Main Warehouse', 'North Depot'], array_column($response->json('data.warehouses'), 'name'));
        $this->assertEqualsCanonicalizing(['Settings Lead', 'Counter'], array_column($response->json('data.custom_roles'), 'name'));
    }

    public function test_team_list_follows_a_scoped_actor_warehouse_scope(): void
    {
        DB::connection('tenant')->table('inventory_user_warehouses')->insert(['organization_id' => 101, 'user_id' => 902, 'warehouse_id' => 12]);
        $this->actAs(900); // scoped to warehouse 11
        $response = $this->getJson('/api/v1/team-access')->assertOk();
        $members = collect($response->json('data.members'))->keyBy('id');

        $this->assertSame(['Main Warehouse'], array_column($response->json('data.warehouses'), 'name'));
        $this->assertTrue($members[901]['editable']);
        // 902 works in a warehouse outside 900's scope: no name leaks, not editable.
        $this->assertSame([], $members[902]['warehouses']);
        $this->assertSame(1, $members[902]['hidden_warehouse_count']);
        $this->assertFalse($members[902]['editable']);
        $this->assertFalse($members[800]['editable']);
    }

    public function test_team_list_is_forbidden_without_manage_settings(): void
    {
        $this->actAs(901);
        $this->getJson('/api/v1/team-access')->assertForbidden();
    }

    private function inspect(int $actor, array $targets)
    {
        $body = json_encode(['client_id' => 10, 'organization_id' => 101, 'actor_id' => $actor, 'target_ids' => $targets, 'nonce' => bin2hex(random_bytes(8))]);
        $time = (string) time();
        $this->app->forgetInstance(InventoryPermissionService::class);

        return $this->call('POST', '/api/tenancy/member-management', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SOLAVEL_TIMESTAMP' => $time, 'HTTP_X_SOLAVEL_SIGNATURE' => 'sha256='.hash_hmac('sha256', $time.'.'.$body, str_repeat('s', 40))], $body);
    }
}
