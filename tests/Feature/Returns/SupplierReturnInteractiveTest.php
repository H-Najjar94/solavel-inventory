<?php
namespace Tests\Feature\Returns;

use App\Http\Controllers\Api\V1\SupplierReturnController;
use App\Models\Tenant\{GoodsReceipt, Supplier, SupplierReturn, Unit};
use App\Models\User;
use App\Services\Access\{CentralAppAccess, InventoryPermissionService, WarehouseAccessService};
use App\Services\Documents\GoodsReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class SupplierReturnInteractiveTest extends TestCase
{
    use TenantAware;
    private GoodsReceipt $receipt;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp(); $this->useTenantA(); config(['inventory.demo_tenant.enabled'=>false]);
        $this->actor=new User; $this->actor->id=323; $this->actor->central_user_id=323;
        Auth::setUser($this->actor); request()->setUserResolver(fn()=>$this->actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>false,'roles'=>['stock_manager']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $warehouses=$this->createStub(WarehouseAccessService::class); $this->app->instance(WarehouseAccessService::class,$warehouses);
        $warehouse=F::warehouse(); $unit=Unit::create(['code'=>'UI-RETURN-EACH','name'=>'Each','kind'=>'count','is_active'=>true]);
        $item=F::averageItem(['base_unit_id'=>$unit->id]); $supplier=Supplier::create(['code'=>'UI-RETURN-SUP','name'=>'Isolated supplier','is_active'=>true]);
        $service=app(GoodsReceiptService::class);
        $this->receipt=$service->post($service->createDraft(['supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse->id,'receipt_date'=>'2026-10-07','currency_code'=>'JOD'],
            [['item_id'=>$item->id,'entered_unit_id'=>$unit->id,'received_qty'=>'10','accepted_qty'=>'10','unit_cost'=>'5']]));
    }
    private function input(string $qty='2'): array
    {
        $options=app(SupplierReturnController::class)->prepare($this->request(),$this->receipt)->getData(true)['data'];
        return ['request_uuid'=>(string)Str::uuid(),'return_date'=>'2026-10-07','reason'=>'Actual supplier return',
            'lines'=>[['goods_receipt_line_id'=>$options['lines'][0]['goods_receipt_line_id'],'source_stock_ledger_id'=>$options['lines'][0]['source_stock_ledger_id'],'entered_qty'=>$qty]]];
    }
    private function request(array $data=[]): Request
    {
        $request=Request::create('/isolated-native-return','POST',$data); $request->setUserResolver(fn()=>$this->actor); return $request;
    }
    public function test_repeat_submission_reuses_one_native_draft_without_stock_movement(): void
    {
        $controller=app(SupplierReturnController::class); $data=$this->input();
        $first=$controller->store($this->request($data),$this->receipt)->getData(true)['data'];
        $again=$controller->store($this->request($data),$this->receipt)->getData(true)['data'];
        $this->assertSame($first['id'],$again['id']); $this->assertSame('draft',$first['status']);
        $this->assertSame(1,SupplierReturn::query()->count());
        $this->assertSame(0,\App\Models\Tenant\StockLedger::query()->where('source_type',SupplierReturn::class)->count());
    }
    public function test_overreturn_is_rejected_by_native_receipt_guard(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupplierReturnController::class)->store($this->request($this->input('11')),$this->receipt);
    }
    public function test_foreign_receipt_line_is_not_accepted(): void
    {
        $data=$this->input(); $data['lines'][0]['goods_receipt_line_id']=999999;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupplierReturnController::class)->store($this->request($data),$this->receipt);
    }
    public function test_unreceived_batch_or_serial_identity_is_rejected(): void
    {
        $data=$this->input(); $data['lines'][0]['source_stock_ledger_id']=999999;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupplierReturnController::class)->store($this->request($data),$this->receipt);
    }
    public function test_unassigned_warehouse_is_denied_before_return_preparation(): void
    {
        $scope=$this->createMock(WarehouseAccessService::class);
        $scope->expects($this->once())->method('assertAllowed')->willThrowException(new \Illuminate\Auth\Access\AuthorizationException('Warehouse not assigned.'));
        $this->app->instance(WarehouseAccessService::class,$scope);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(SupplierReturnController::class)->prepare($this->request(),$this->receipt);
    }
    public function test_actor_without_return_permission_is_denied_before_document_creation(): void
    {
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed'=>true,'owner'=>false,'roles'=>['stock_viewer']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SupplierReturnController::class)->prepare($this->request(),$this->receipt);
    }
}
