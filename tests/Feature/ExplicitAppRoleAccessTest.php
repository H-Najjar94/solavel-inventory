<?php
namespace Tests\Feature;
use App\Services\Access\{CentralAppAccess,InventoryPermissionService};
use App\Tenancy\OrganizationContext;
use Illuminate\Foundation\Testing\TestCase;

class ExplicitAppRoleAccessTest extends TestCase
{
    public function createApplication() {
        $app=require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.connections.tenant'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''], 'inventory.demo_tenant.enabled'=>false]);
        return $app;
    }
    private function permission(array $decision, string $permission): bool {
        $context=new OrganizationContext; $context->set(10);
        $authority=\Mockery::mock(CentralAppAccess::class);
        $authority->shouldReceive('decision')->with(7,10,'inventory')->andReturn($decision);
        $this->app->instance(CentralAppAccess::class,$authority);
        $service=new class($context) extends InventoryPermissionService {
            protected function fetchCentralRole(int $userId,int $orgId): ?string {return 'client_member';}
        };
        return $service->can((object)['id'=>70,'central_user_id'=>7],$permission);
    }
    public function test_connected_member_without_application_access_cannot_read_or_write_stock():void {
        $this->assertFalse($this->permission(['allowed'=>false],'inventory.view_items'));
        $this->assertFalse($this->permission(['allowed'=>false],'inventory.manage_warehouses'));
    }
    public function test_warehouse_user_cannot_inherit_membership_manager_permissions():void {
        $decision=['allowed'=>true,'roles'=>['warehouse_user'],'owner'=>false];
        $this->assertTrue($this->permission($decision,'inventory.view_stock'));
        $this->assertFalse($this->permission($decision,'inventory.manage_warehouses'));
        $this->assertFalse($this->permission($decision,'inventory.manage_settings'));
    }
    public function test_stock_manager_can_operate_stock_but_not_administrative_settings():void {
        $decision=['allowed'=>true,'roles'=>['stock_manager'],'owner'=>false];
        $this->assertTrue($this->permission($decision,'inventory.manage_warehouses'));
        $this->assertFalse($this->permission($decision,'inventory.manage_settings'));
    }
    public function test_role_removal_denies_further_activity():void {
        $this->assertTrue($this->permission(['allowed'=>true,'roles'=>['stock_manager']],'inventory.manage_items'));
        $this->assertFalse($this->permission(['allowed'=>true,'roles'=>[]],'inventory.manage_items'));
    }
}
