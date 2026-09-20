<?php
namespace Tests\Feature;

use App\Models\Tenant\{IntegrationOrganizationMapping, IntegrationSetting};
use App\Services\Access\{CentralAppAccess, InventoryPermissionService};
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\InventoryWorkspace\{FinanceDocumentLifecycleAuthority, WorkspaceActions, WorkspaceDispatcher};
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A SolaCount-only member completes an already reviewed financial document; nothing else opens. */
class FinanceDocumentLifecycleScopeTest extends TestCase
{
    public function createApplication() {
        $app=require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.connections.tenant'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''], 'inventory.demo_tenant.enabled'=>false]);
        return $app;
    }

    /** @return array{0:int,1:string} status and abort message of the first gate that stops the call */
    private function dispatch(string $action, array $financeDecision, bool $stockPermission): array {
        $central=\Mockery::mock(CentralAppAccess::class);
        $central->shouldReceive('decision')->with(7,100,'finance')->andReturn($financeDecision);
        $central->shouldReceive('decision')->with(7,100,'inventory')->andReturn(['allowed'=>false,'reason'=>'user_not_assigned_to_project']);
        $this->app->instance(CentralAppAccess::class,$central);
        $permissions=\Mockery::mock(InventoryPermissionService::class);$permissions->shouldReceive('can')->andReturn($stockPermission);
        $this->app->instance(InventoryPermissionService::class,$permissions);
        $commercial=\Mockery::mock(InventoryCommercialEntitlementService::class);
        $commercial->shouldReceive('checkPermission','checkFeature')->andReturn(['allowed'=>true,'reason_code'=>null]);
        $this->app->instance(InventoryCommercialEntitlementService::class,$commercial);
        $request=Request::create('/api/internal/finance-workspace','POST');
        $request->setUserResolver(fn()=>(object)['id'=>7]);
        // A held connection is the first gate after authorization: reaching it proves the member was authorized.
        $mapping=(new IntegrationOrganizationMapping)->forceFill(['id'=>1,'central_organization_id'=>100,'solastock_organization_id'=>100,'status'=>'verified','activation_state'=>'maintenance_hold']);
        $setting=(new IntegrationSetting)->forceFill(['mode'=>'active']);
        try {
            app(WorkspaceDispatcher::class)->dispatch($request,['action'=>$action,'idempotency_key'=>str_repeat('k',32),'data'=>[]],$mapping,$setting);
        } catch (HttpException $e) { return [$e->getStatusCode(),$e->getMessage()]; }
        $this->fail('Dispatch was expected to stop at a gate.');
    }

    public function test_scope_is_a_closed_list_of_follow_through_actions():void {
        $this->assertSame(['finance-allocations.commit','finance-allocations.release','finance-allocations.reverse',
            'finance-allocations.cost-adjustment.apply','finance-allocations.cost-adjustment.reverse'],FinanceDocumentLifecycleAuthority::ACTIONS);
        $this->assertSame([],array_diff(FinanceDocumentLifecycleAuthority::ACTIONS,WorkspaceActions::ALLOWED));
        $this->assertSame(['items.migration-requirements','items.migration-create','items.migration-link'],FinanceDocumentLifecycleAuthority::CATALOG_ACTIONS);
        $this->assertSame([],array_diff(FinanceDocumentLifecycleAuthority::CATALOG_ACTIONS,WorkspaceActions::ALLOWED));
        $scoped=array_merge(FinanceDocumentLifecycleAuthority::ACTIONS,FinanceDocumentLifecycleAuthority::CATALOG_ACTIONS);
        foreach (array_diff(WorkspaceActions::ALLOWED,$scoped) as $action) {
            $this->assertFalse(FinanceDocumentLifecycleAuthority::covers($action),$action);
            $this->assertNull(FinanceDocumentLifecycleAuthority::scopeFor($action),$action);
        }
        $this->assertSame('finance_catalog_item_creation',FinanceDocumentLifecycleAuthority::scopeFor('items.migration-create'));
        $this->assertSame('finance_document_lifecycle',FinanceDocumentLifecycleAuthority::scopeFor('finance-allocations.commit'));
    }
    public function test_finance_only_member_is_authorized_for_every_follow_through_action():void {
        foreach (FinanceDocumentLifecycleAuthority::ACTIONS as $action) {
            $this->assertSame([409,'workspace_connection_read_only'],$this->dispatch($action,['allowed'=>true,'roles'=>['accountant']],false),$action);
        }
    }
    public function test_finance_only_member_is_authorized_to_create_a_catalog_item_but_not_to_edit_or_browse_stock_items():void {
        foreach (['items.migration-create','items.migration-link'] as $action) {
            $this->assertSame([409,'workspace_connection_read_only'],$this->dispatch($action,['allowed'=>true,'roles'=>['accountant']],false),$action);
            $this->assertSame([403,'workspace_finance_access_required'],$this->dispatch($action,['allowed'=>false,'reason'=>'user_not_assigned_to_project'],true),$action);
        }
        foreach (['items.store','items.update','items.index','items.show','items.valuation'] as $action) {
            $this->assertSame(403,$this->dispatch($action,['allowed'=>true,'roles'=>['accountant']],false)[0],$action);
        }
    }
    public function test_follow_through_requires_current_solacount_access_and_fails_closed():void {
        foreach ([['allowed'=>false,'reason'=>'user_not_assigned_to_project'],['allowed'=>false,'reason'=>'temporarily_unavailable'],[]] as $decision) {
            $this->assertSame([403,'workspace_finance_access_required'],$this->dispatch('finance-allocations.commit',$decision,true));
        }
    }
    public function test_scope_never_opens_selection_review_stock_reads_or_stock_writes():void {
        foreach (['finance-allocations.reserve','finance-allocations.cost-adjustment.prepare','finance-sources.receipts','finance-sources.shipment',
            'items.index','items.store','balances.index','warehouses.store','items.movements'] as $action) {
            $this->assertSame([403,'workspace_permission_required'],$this->dispatch($action,['allowed'=>true,'roles'=>['accountant']],false),$action);
        }
    }
    public function test_follow_through_routes_keep_their_native_stock_permission_for_direct_stock_api_use():void {
        foreach (FinanceDocumentLifecycleAuthority::ACTIONS as $action) {
            $this->assertContains('perm:inventory.integration.setup',app('router')->getRoutes()->getByName('api.v1.'.$action)->gatherMiddleware(),$action);
        }
    }
}
