<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\CustomRoleController;
use App\Models\User;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\MemberManagement;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mockery;

class CustomRoleTenantValidationTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'database.default' => 'registry',
            'database.connections.registry' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'tenancy.tenant_connection' => 'tenant',
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('tenant')->create('inventory_custom_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('key');
            $table->string('name');
            $table->json('permissions');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::connection('tenant')->create('inventory_user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();
        });
        $context = new OrganizationContext;
        $context->set(2);
        $this->app->instance(OrganizationContext::class, $context);
        $permissions = Mockery::mock(InventoryPermissionService::class);
        $permissions->shouldReceive('permissionsFor')->andReturn(['inventory.view_stock']);
        $this->app->instance(InventoryPermissionService::class, $permissions);
        $this->app['router']->setRoutes(new RouteCollection);
        Route::post('/test/custom-roles', [CustomRoleController::class, 'store']);
        Route::put('/test/custom-roles/{role}', [CustomRoleController::class, 'update'])
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
        Route::post('/test/custom-role-assignments', [CustomRoleController::class, 'assign']);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['url']->setRoutes($this->app['router']->getRoutes());
    }

    public function test_role_key_validation_uses_tenant_table_and_selected_organization(): void
    {
        $input = ['key' => 'scoped_lookup', 'name' => 'Scoped lookup', 'permissions' => ['inventory.view_stock']];
        $this->postJson('/test/custom-roles', $input)->assertCreated();
        $this->assertSame(1, DB::connection('tenant')->table('inventory_custom_roles')->where('organization_id', 2)->count());
        $this->postJson('/test/custom-roles', $input)->assertRedirect();
        $this->assertSame(1, DB::connection('tenant')->table('inventory_custom_roles')->where('organization_id', 2)->count());

        app(OrganizationContext::class)->set(3);
        $this->postJson('/test/custom-roles', $input)->assertCreated();
        $this->assertSame(1, DB::connection('tenant')->table('inventory_custom_roles')->where('organization_id', 3)->count());
        $this->assertFalse(Schema::connection('registry')->hasTable('inventory_custom_roles'));
    }

    public function test_assignment_role_lookup_uses_tenant_table(): void
    {
        $roleId = DB::connection('tenant')->table('inventory_custom_roles')->insertGetId([
            'organization_id' => 2,
            'key' => 'scoped_lookup',
            'name' => 'Scoped lookup',
            'permissions' => json_encode(['inventory.view_stock']),
            'is_active' => true,
        ]);
        $actor = new User;
        $actor->setRawAttributes(['id' => 10]);
        $target = new User;
        $target->setRawAttributes(['id' => 20]);
        $management = Mockery::mock(MemberManagement::class);
        $management->shouldReceive('member')->once()->with(2, 20)->andReturn($target);
        $management->shouldReceive('authorize')->once()->with($actor, 2, $target);
        $this->app->instance(MemberManagement::class, $management);
        $this->actingAs($actor)
            ->postJson('/test/custom-role-assignments', ['user_id' => 20, 'role_id' => $roleId])
            ->assertCreated();
        $this->assertDatabaseHas('inventory_user_role_assignments', [
            'organization_id' => 2, 'user_id' => 20, 'role_id' => $roleId,
        ], 'tenant');
        $this->assertFalse(Schema::connection('registry')->hasTable('inventory_custom_roles'));
    }

    public function test_update_reuses_its_tenant_key_without_querying_the_registry(): void
    {
        $roleId = DB::connection('tenant')->table('inventory_custom_roles')->insertGetId([
            'organization_id' => 2,
            'key' => 'scoped_lookup',
            'name' => 'Original',
            'permissions' => json_encode(['inventory.view_stock']),
            'is_active' => true,
        ]);
        $input = ['key' => 'scoped_lookup', 'name' => 'Renamed', 'permissions' => ['inventory.view_stock']];
        $this->assertTrue(Validator::make($input, ['key' => [Rule::unique('tenant.inventory_custom_roles', 'key')->where('organization_id', 2)->ignore($roleId)]])->passes());
        $this->putJson('/test/custom-roles/'.$roleId, $input)->assertOk();
        $this->assertDatabaseHas('inventory_custom_roles', [
            'id' => $roleId, 'organization_id' => 2, 'key' => 'scoped_lookup', 'name' => 'Renamed',
        ], 'tenant');
    }
}
