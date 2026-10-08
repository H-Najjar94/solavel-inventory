<?php
namespace Tests\Feature;

use App\Http\Controllers\Api\FinanceWorkspaceController;
use App\Services\InventoryWorkspace\WorkspaceDispatcher;
use App\Services\Tenancy\TenantManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceWorkspaceAssignmentScopeTest extends TestCase
{
    private array $access = ['finance'=>['allowed'=>true], 'inventory'=>['allowed'=>false]];
    public function createApplication() {
        $app=require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $memory=['driver'=>'sqlite','database'=>':memory:','prefix'=>''];
        config(['database.connections.tenant'=>$memory,'database.connections.central_test'=>$memory,'database.default'=>'central_test',
            'tenancy.central_connection'=>'central_test','inventory.demo_tenant.enabled'=>false]);
        return $app;
    }
    protected function setUp():void {
        parent::setUp();
        $authority=\Mockery::mock(\App\Services\Access\CentralAppAccess::class);
        $authority->shouldReceive('decision')->with(7,100,\Mockery::type('string'))->andReturnUsing(fn($user,$org,$slug)=>$this->access[$slug]);
        $this->app->instance(\App\Services\Access\CentralAppAccess::class,$authority);
        $schema=Schema::connection('central_test');
        $schema->create('clients',function(Blueprint $t){$t->id();$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('organizations',function(Blueprint $t){$t->id();$t->integer('client_id');$t->boolean('is_active');$t->timestamp('deleted_at')->nullable();});
        $schema->create('users',function(Blueprint $t){$t->id();$t->string('name');$t->integer('client_id');$t->string('status')->nullable();$t->timestamp('deleted_at')->nullable();});
        $schema->create('user_organizations',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('organization_id');$t->string('status')->nullable();$t->string('role')->default('client_owner');});
        $schema->create('app_permission_grants',function(Blueprint $t){$t->id();$t->integer('organization_id');$t->integer('user_id')->nullable();$t->string('app_key');$t->string('permission_key');$t->string('effect');$t->timestamp('expires_at')->nullable();});
        $schema->create('projects',function(Blueprint $t){$t->id();$t->string('slug');$t->boolean('is_active');});
        foreach(['organization_projects','user_projects'] as $table)$schema->create($table,function(Blueprint $t){$t->id();$t->integer('organization_id');$t->integer('user_id')->nullable();$t->integer('project_id');$t->boolean('is_active');});
        $db=DB::connection('central_test');
        $db->table('clients')->insert(['id'=>5,'is_active'=>true]);$db->table('organizations')->insert(['id'=>100,'client_id'=>5,'is_active'=>true]);
        $db->table('users')->insert(['id'=>7,'name'=>'Accountant','client_id'=>5,'status'=>'active']);
        $db->table('user_organizations')->insert(['user_id'=>7,'organization_id'=>100,'status'=>'active']);
        $db->table('projects')->insert([['id'=>1,'slug'=>'finance','is_active'=>true],['id'=>2,'slug'=>'inventory','is_active'=>true]]);
        $db->table('organization_projects')->insert([['organization_id'=>100,'project_id'=>1,'is_active'=>true],['organization_id'=>100,'project_id'=>2,'is_active'=>true]]);
        // The member holds SolaCount only.
        $db->table('user_projects')->insert(['organization_id'=>100,'user_id'=>7,'project_id'=>1,'is_active'=>true]);
    }
    private function invoke(string $action):string {
        $tenants=\Mockery::mock(TenantManager::class);
        $tenants->shouldReceive('resolveDatabaseName')->andThrow(new \RuntimeException('passed_assignment_gate'));
        $request=Request::create('/api/internal/finance-workspace','POST',['client_id'=>5,'organization_id'=>100,'finance_organization_id'=>1,'actor_id'=>7,'action'=>$action]);
        try { (new FinanceWorkspaceController)($request,$tenants,new WorkspaceDispatcher); }
        catch (HttpException $e) { return $e->getMessage(); }
        catch (\RuntimeException $e) { return $e->getMessage(); }
        return 'completed';
    }
    public function test_solacount_only_member_passes_assignment_only_for_document_follow_through():void {
        foreach (array_merge(\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::ACTIONS,\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::CATALOG_ACTIONS) as $action) $this->assertSame('passed_assignment_gate',$this->invoke($action),$action);
        foreach (['finance-allocations.reserve','finance-sources.receipts','items.index','items.movements.export','items.store','items.update','workspace.context','workspace.initialize','warehouses.store'] as $action) {
            $this->assertSame('workspace_application_assignment_required',$this->invoke($action),$action);
        }
    }
    public function test_follow_through_still_requires_the_members_solacount_assignment():void {
        DB::connection('central_test')->table('user_projects')->update(['is_active'=>false]);
        $this->access['finance']=['allowed'=>false];
        $this->assertSame('workspace_application_assignment_required',$this->invoke('finance-allocations.commit'));
    }
    public function test_follow_through_still_requires_the_organization_to_hold_solastock():void {
        DB::connection('central_test')->table('organization_projects')->where('project_id',2)->update(['is_active'=>false]);
        $this->assertSame('workspace_application_assignment_required',$this->invoke('finance-allocations.commit'));
    }
    /**
     * Production regression, 2026-09-21: an invited member keeps the client of the
     * account they registered with. The gate matched that to the organization's
     * client, so every invited SolaCount member was reported as a non-member and
     * could not create a stock item in a connected organization.
     */
    public function test_invited_member_with_a_different_home_client_is_still_a_member():void {
        DB::connection('central_test')->table('users')->where('id',7)->update(['client_id'=>99]);
        $this->assertSame('passed_assignment_gate',$this->invoke('items.migration-create'));
    }
    public function test_an_inactive_invited_member_is_still_rejected():void {
        DB::connection('central_test')->table('users')->where('id',7)->update(['client_id'=>99]);
        DB::connection('central_test')->table('user_organizations')->update(['status'=>'suspended']);
        $this->assertSame('workspace_membership_required',$this->invoke('items.migration-create'));
    }
    public function test_member_of_another_organization_is_rejected_before_any_scope_applies():void {
        DB::connection('central_test')->table('user_organizations')->update(['organization_id'=>999]);
        $this->assertSame('workspace_membership_required',$this->invoke('finance-allocations.commit'));
    }
    public function test_current_owner_access_overrides_an_inactive_legacy_assignment():void {
        DB::connection('central_test')->table('user_projects')->insert(['organization_id'=>100,'user_id'=>7,'project_id'=>2,'is_active'=>false]);
        $this->access['inventory']=['allowed'=>true];
        $this->assertSame('passed_assignment_gate',$this->invoke('workspace.context'));
        $policy=app(\App\Services\Integration\ConnectionManagementPolicy::class)->status(100,(object)['central_user_id'=>7]);
        $this->assertTrue($policy['can_manage_connection']);
        $this->assertTrue($policy['can_review_accounting']);
    }
    public function test_current_revocation_overrides_an_active_legacy_assignment():void {
        DB::connection('central_test')->table('user_projects')->insert(['organization_id'=>100,'user_id'=>7,'project_id'=>2,'is_active'=>true]);
        $this->assertSame('workspace_application_assignment_required',$this->invoke('workspace.initialize'));
        $this->assertFalse(app(\App\Services\Integration\ConnectionManagementPolicy::class)->status(100,(object)['central_user_id'=>7])['can_manage_connection']);
    }
    public function test_unavailable_authority_is_not_misreported_as_missing_assignment():void {
        $this->access['inventory']=['allowed'=>false,'reason'=>'temporarily_unavailable'];
        $this->assertSame('workspace_access_temporarily_unavailable',$this->invoke('workspace.context'));
    }
    public function test_invited_owner_management_uses_membership_not_home_client():void {
        DB::connection('central_test')->table('users')->where('id',7)->update(['client_id'=>99]);
        $this->access['inventory']=['allowed'=>true];
        $this->assertTrue(app(\App\Services\Integration\ConnectionManagementPolicy::class)->status(100,(object)['central_user_id'=>7])['can_manage_connection']);
        DB::connection('central_test')->table('user_organizations')->update(['status'=>'suspended']);
        $this->assertFalse(app(\App\Services\Integration\ConnectionManagementPolicy::class)->status(100,(object)['central_user_id'=>7])['can_manage_connection']);
    }
}
