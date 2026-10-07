<?php
namespace Tests\Feature\Returns;

use App\Models\Tenant\{StockBalance, StockLedger, Supplier, SupplierReturn, Unit};
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\{GoodsReceiptService, SupplierReturnService};
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Real native GRN and FIFO/AVG ledger persistence. Warehouse permission is the isolated boundary. */
final class SupplierReturnNativeTest extends TestCase
{
    use TenantAware;
    private $warehouse;
    private $unit;
    private $item;
    private $supplier;

    protected function setUp(): void
    {
        parent::setUp(); $this->useTenantA();
        $warehouseScope = $this->createStub(WarehouseAccessService::class);
        $warehouseScope->method('scope')->willReturnCallback(fn ($query) => $query);
        $this->app->instance(WarehouseAccessService::class, $warehouseScope);
        $this->warehouse = F::warehouse();
        $this->unit = Unit::create(['code' => 'RETURN-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $this->item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $this->supplier = Supplier::create(['code' => 'RETURN-SUP', 'name' => 'Synthetic return supplier', 'is_active' => true]);
    }

    private function receipt(string $quantity = '10', string $cost = '5')
    {
        $service = app(GoodsReceiptService::class);
        $draft = $service->createDraft(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'receipt_date' => '2026-10-07', 'currency_code' => 'JOD'], [['item_id' => $this->item->id,
            'entered_unit_id' => $this->unit->id, 'received_qty' => $quantity, 'accepted_qty' => $quantity, 'unit_cost' => $cost]]);
        return $service->post($draft)->fresh('lines');
    }

    private function draft($receipt, string $qty)
    {
        return app(SupplierReturnService::class)->createDraft(['goods_receipt_id' => $receipt->id, 'return_date' => '2026-10-07',
            'reason' => 'Synthetic supplier return'], [['goods_receipt_line_id' => $receipt->lines->sole()->id, 'entered_qty' => $qty]]);
    }

    public function test_supplier_return_uses_native_current_average_cost_and_has_no_draft_or_replay_movements(): void
    {
        $source = $this->receipt(); $this->receipt('20', '10');
        $before = StockBalance::query()->where('item_id', $this->item->id)->sole();
        $ledgerBefore = StockLedger::count(); $draft = $this->draft($source, '2');
        $this->assertSame($ledgerBefore, StockLedger::count());
        $this->assertSame(30.0, (float) $before->on_hand_qty);
        $posted = app(SupplierReturnService::class)->post($draft);
        $movement = StockLedger::query()->where('source_type', SupplierReturn::class)->where('source_id', $posted->id)->sole();
        $after = StockBalance::query()->where('item_id', $this->item->id)->sole();
        $this->assertSame('out', $movement->direction);
        $this->assertSame(2.0, (float) $movement->quantity);
        $this->assertGreaterThan(10.0, (float) $movement->total_cost, 'Return OUT follows current average, not historical receipt unit price.');
        $this->assertSame(28.0, (float) $after->on_hand_qty);
        $this->assertEqualsWithDelta((float) $before->total_value - (float) $movement->total_cost, (float) $after->total_value, 0.000001);
        $this->assertEqualsWithDelta((float) $movement->total_cost, (float) $posted->lines->sole()->actual_return_cost_base, 0.000001);
        $this->assertSame($source->lines->sole()->id, $posted->lines->sole()->goods_receipt_line_id);
        app(SupplierReturnService::class)->post($posted);
        $this->assertSame($ledgerBefore + 1, StockLedger::count());
        $this->assertSame(28.0, (float) $after->fresh()->on_hand_qty);
    }

    public function test_prior_supplier_return_bounds_reject_an_overlapping_draft_without_partial_effects(): void
    {
        $source = $this->receipt(); $first = $this->draft($source, '6'); $second = $this->draft($source, '5');
        app(SupplierReturnService::class)->post($first); $before = StockLedger::count();
        try { app(SupplierReturnService::class)->post($second); $this->fail('A quantity already returned was returned twice.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('lines', $exception->errors()); }
        $this->assertSame('draft', $second->fresh()->status); $this->assertSame($before, StockLedger::count());
        $this->assertSame(4.0, (float) StockBalance::query()->where('item_id', $this->item->id)->sole()->on_hand_qty);
    }

    public function test_supplier_return_native_reversal_restores_only_its_out_quantity_and_value_once(): void
    {
        $source=$this->receipt();$service=app(SupplierReturnService::class);
        $before=StockBalance::query()->where('item_id',$this->item->id)->sole();$value=(float)$before->total_value;
        $posted=$service->post($this->draft($source,'2'));
        $reversal=$service->reverse($posted,'Synthetic mistaken return');$count=StockLedger::count();
        $this->assertSame('reversed',$posted->fresh()->status);$this->assertSame('supplier_return',$reversal->source_type);
        $this->assertSame(10.0,(float)$before->fresh()->on_hand_qty);$this->assertEqualsWithDelta($value,(float)$before->fresh()->total_value,0.000001);
        $again=$service->reverse($posted->fresh(),'Repeated same native reversal');
        $this->assertSame($reversal->id,$again->id);$this->assertSame($count,StockLedger::count());
        $this->assertSame('posted',$source->fresh()->status);
    }

    public function test_missing_original_conversion_rejects_draft_without_invented_unit_or_stock_effects(): void
    {
        $source=$this->receipt();
        \Illuminate\Support\Facades\DB::connection('tenant')->table('goods_receipt_lines')->where('id',$source->lines->sole()->id)->update(['unit_conversion_factor'=>'0']);
        $source->load('lines');$before=StockLedger::count();$documents=SupplierReturn::count();
        try{$this->draft($source,'1');$this->fail('Missing historical conversion was invented.');}
        catch(ValidationException $error){$this->assertArrayHasKey('lines',$error->errors());}
        $this->assertSame($before,StockLedger::count());$this->assertSame($documents,SupplierReturn::count());
    }

    public function test_supplier_return_controller_requires_purchase_return_authority_not_sales_return_authority(): void
    {
        $source=$this->receipt();$permission=$this->createMock(\App\Services\Access\InventoryPermissionService::class);
        $permission->method('can')->willReturnCallback(fn($user,$key)=>in_array($key,['inventory.view_stock','inventory.manage_returns'],true));
        $request=\Illuminate\Http\Request::create('/supplier-returns','POST',['goods_receipt_id'=>$source->id,'return_date'=>'2026-10-07','reason'=>'Synthetic return','lines'=>[]]);
        $request->setUserResolver(fn()=>(object)['id'=>337]);
        $controller=new \App\Http\Controllers\Api\V1\SupplierReturnController(app(SupplierReturnService::class),app(WarehouseAccessService::class),$permission);
        $before=SupplierReturn::count();$ledger=StockLedger::count();
        try{$controller->store($request);$this->fail('Customer-return authority granted supplier OUT.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$this->assertSame(403,$error->getStatusCode());}
        $this->assertSame($before,SupplierReturn::count());$this->assertSame($ledger,StockLedger::count());
    }

    public function test_cross_tenant_receipt_cannot_create_a_supplier_return(): void
    {
        $source = $this->receipt(); $this->useTenantB();
        try { $this->draft($source, '1'); $this->fail('Another tenant receipt was accepted.'); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) { $this->assertSame(0, SupplierReturn::count()); }
        $this->assertSame(0, StockLedger::count());
    }
}
