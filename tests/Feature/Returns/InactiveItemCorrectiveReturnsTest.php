<?php

namespace Tests\Feature\Returns;

use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\SalesReturnLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierReturnLine;
use App\Models\Tenant\Unit;
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\WarehouseAccessService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\SupplierReturnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Owner adjustment 2026-10-18 (a)/(b): returns linked to a posted source stay allowed for inactive items through
 * their existing workflows (audited, item_inactive = true); unlinked returns stay refused.
 */
final class InactiveItemCorrectiveReturnsTest extends TestCase
{
    use TenantAware;

    private $warehouse;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $scope = $this->createStub(WarehouseAccessService::class);
        $scope->method('scope')->willReturnCallback(fn ($q) => $q);
        $this->app->instance(WarehouseAccessService::class, $scope);
        config(['inventory.demo_tenant.enabled' => false]);
        $actor = new User;
        $actor->id = 323;
        $actor->central_user_id = 323;
        Auth::setUser($actor);
        request()->setUserResolver(fn () => $actor);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed' => true, 'owner' => false, 'roles' => ['stock_manager']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->warehouse = F::warehouse();
        $this->unit = Unit::create(['code' => 'Q12-RET-EA', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
    }

    private function inactive(Item $item): void
    {
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
    }

    private function refused(callable $write, int $itemId, string $message = 'inventory.catalog.inactive_item_rejected'): void
    {
        try {
            $write();
            $this->fail('accepted');
        } catch (ValidationException $e) {
            $this->assertSame(__($message), $e->errors()['item_id'][0] ?? null, json_encode($e->errors()));
            $this->assertSame([$itemId], $e->errors()['inactive_item_ids'] ?? null);
        }
    }

    private function audit(string $kind, int $itemId): InventoryAuditLog
    {
        return InventoryAuditLog::query()->where('action', 'inactive_item.corrective_movement')->get()
            ->filter(fn ($a) => ($a->before['kind'] ?? null) === $kind && (int) $a->before['item_id'] === $itemId)->sole();
    }

    private function receipt(Item $item, string $qty, bool $post = true)
    {
        $supplier = Supplier::query()->firstOrCreate(['code' => 'Q12-RET-SUP'], ['name' => 'Synthetic supplier', 'is_active' => true]);
        $grns = app(GoodsReceiptService::class);
        $draft = $grns->createDraft(['supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => now()->toDateString()],
            [['item_id' => $item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => $qty, 'accepted_qty' => $qty, 'unit_cost' => '5']]);

        return ($post ? $grns->post($draft) : $draft)->fresh('lines');
    }

    public function test_a_supplier_return_against_a_posted_receipt_reduces_existing_stock_of_an_inactive_item(): void
    {
        $item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $receipt = $this->receipt($item, '10');
        $this->inactive($item);
        $service = app(SupplierReturnService::class);
        $return = $service->post($service->createDraft(['goods_receipt_id' => $receipt->id, 'return_date' => now()->toDateString(), 'reason' => 'Faulty batch back to supplier'],
            [['goods_receipt_line_id' => $receipt->lines->sole()->id, 'entered_qty' => '2']]));
        $this->assertSame('posted', $return->fresh()->status);
        $this->assertSame('8.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $audit = $this->audit('supplier_return', $item->id);
        $this->assertTrue($audit->before['item_inactive']);
    }

    public function test_a_supplier_return_beyond_existing_stock_or_without_a_posted_receipt_is_refused(): void
    {
        $item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $receipt = $this->receipt($item, '10');
        $adjustments = app(StockAdjustmentService::class);
        $adjustments->post($adjustments->createDraft(['warehouse_id' => $this->warehouse->id, 'reason_code' => 'DAMAGE'], [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '9']]));
        $this->inactive($item);
        $service = app(SupplierReturnService::class);
        // Only 1 left: returning 2 would go below zero.
        $this->refused(fn () => $service->createDraft(['goods_receipt_id' => $receipt->id, 'return_date' => now()->toDateString(), 'reason' => 'Return more than on hand'],
            [['goods_receipt_line_id' => $receipt->lines->sole()->id, 'entered_qty' => '2']]), $item->id, 'inventory.catalog.inactive_write_off_exceeds_stock');
        $this->assertSame('1.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        // Unlinked line, or a line pointing at a receipt that was never posted: refused.
        $draftReceipt = $this->receipt($other = F::averageItem(['base_unit_id' => $this->unit->id]), '3', false);
        $this->inactive($other);
        $this->refused(fn () => (new SupplierReturnLine(['item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => '1']))->save(), $item->id);
        $this->refused(fn () => (new SupplierReturnLine(['item_id' => $other->id, 'goods_receipt_line_id' => $draftReceipt->lines->sole()->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => '1']))->save(), $other->id);
    }

    /** @return array{0:Item,1:\App\Models\Tenant\Shipment} item with 10 opening, 2 shipped on a posted shipment */
    private function shipped(): array
    {
        $item = F::averageItem(['sales_price' => '20.0000']);
        $opening = app(\App\Services\Documents\OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $this->warehouse->id], [['item_id' => $item->id, 'quantity' => '10', 'unit_cost' => '5']]));
        $sales = app(SalesOrderService::class);
        $order = $sales->createDraft(['order_number' => 'Q12-SO-'.uniqid(), 'warehouse_id' => $this->warehouse->id, 'customer_name' => 'Synthetic customer'], [['item_id' => $item->id, 'ordered_qty' => '2', 'unit_price' => '20']]);
        $order = $sales->reserve($sales->confirm($order));
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createDraft(['shipment_number' => 'Q12-SH-'.uniqid(), 'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'ship_date' => now()->toDateString()], $shipments->fromSalesOrder($order));
        $shipments->post($shipment);

        return [$item, $shipment->fresh('lines')];
    }

    public function test_b_a_sales_return_of_a_posted_shipment_restocks_an_inactive_item_up_to_the_shipped_quantity(): void
    {
        [$item, $shipment] = $this->shipped();
        $this->assertSame('8.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->inactive($item);
        $returns = app(SalesReturnService::class);
        $line = $shipment->lines->sole();
        $return = $returns->createDraft(['return_number' => 'Q12-RMA-'.uniqid(), 'shipment_id' => $shipment->id, 'warehouse_id' => $this->warehouse->id, 'return_date' => now()->toDateString(), 'reason' => 'Customer returned one unit'],
            [['source_line_id' => $line->id, 'item_id' => $item->id, 'returned_qty' => '1', 'condition' => 'resellable']]);
        $returns->post($return);
        $this->assertSame('9.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertTrue($this->audit('sales_return', $item->id)->before['item_inactive']);

        // More than originally shipped (2) in total is refused by the inactive-item guard itself.
        $this->refused(fn () => (new SalesReturnLine(['item_id' => $item->id, 'sales_return_id' => $return->id, 'source_shipment_line_id' => $line->id,
            'warehouse_id' => $this->warehouse->id, 'returned_qty' => '1.5']))->save(), $item->id, 'inventory.catalog.inactive_return_exceeds_shipped');
        $this->assertSame('9.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }

    public function test_b_a_sales_return_without_a_posted_shipment_source_is_refused(): void
    {
        $item = F::averageItem();
        $opening = app(\App\Services\Documents\OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $this->warehouse->id], [['item_id' => $item->id, 'quantity' => '3', 'unit_cost' => '5']]));
        $this->inactive($item);
        $this->refused(fn () => app(SalesReturnService::class)->createDraft(['return_number' => 'Q12-RMA-'.uniqid(), 'warehouse_id' => $this->warehouse->id, 'customer_name' => 'Walk-in', 'return_date' => now()->toDateString(), 'reason' => 'No source'],
            [['item_id' => $item->id, 'returned_qty' => '1', 'unit_cost' => '5', 'condition' => 'resellable']]), $item->id);
        $this->assertSame(0, DB::connection('tenant')->table('sales_return_lines')->where('item_id', $item->id)->count());
        $this->assertSame('3.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
    }

    public function test_lifecycle_draft_or_cancelled_sources_do_not_authorize_returns_for_an_inactive_item(): void
    {
        // Receipt: draft and cancelled receipts are not posted sources.
        $item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $draft = $this->receipt($item, '3', false);
        $this->inactive($item);
        $grnLine = $draft->lines->sole()->id;
        $this->refused(fn () => (new SupplierReturnLine(['item_id' => $item->id, 'goods_receipt_line_id' => $grnLine, 'warehouse_id' => $this->warehouse->id, 'quantity' => '1']))->save(), $item->id);
        DB::connection('tenant')->table('goods_receipts')->where('id', $draft->id)->update(['status' => 'cancelled']);
        $this->refused(fn () => (new SupplierReturnLine(['item_id' => $item->id, 'goods_receipt_line_id' => $grnLine, 'warehouse_id' => $this->warehouse->id, 'quantity' => '1']))->save(), $item->id);

        // Shipment: draft, cancelled and already-reversed shipments are not posted sources.
        [$shippedItem, $shipment] = $this->shipped();
        $this->inactive($shippedItem);
        $line = $shipment->lines->sole();
        $make = fn () => (new SalesReturnLine(['item_id' => $shippedItem->id, 'source_shipment_line_id' => $line->id, 'warehouse_id' => $this->warehouse->id, 'returned_qty' => '1']))->save();
        foreach (['draft' => [], 'cancelled' => [], 'posted' => ['reversed_at' => now()]] as $status => $extra) {
            DB::connection('tenant')->table('shipments')->where('id', $shipment->id)->update(['status' => $status] + $extra);
            $this->refused($make, $shippedItem->id);
        }
    }

    public function test_permission_the_allowed_supplier_return_still_requires_the_returns_permission(): void
    {
        $item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $receipt = $this->receipt($item, '10');
        $this->inactive($item);
        $service = app(SupplierReturnService::class);
        $draft = $service->createDraft(['goods_receipt_id' => $receipt->id, 'return_date' => now()->toDateString(), 'reason' => 'Faulty batch back to supplier'],
            [['goods_receipt_line_id' => $receipt->lines->sole()->id, 'entered_qty' => '2']]);
        $this->mock(CentralAppAccess::class)->shouldReceive('decision')->andReturn(['allowed' => true, 'owner' => false, 'roles' => ['stock_viewer']]);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $actor = request()->user();
        $request = \Illuminate\Http\Request::create('/api/v1/supplier-returns/'.$draft->id.'/post', 'POST');
        $request->setUserResolver(fn () => $actor);
        try {
            app(\App\Http\Controllers\Api\V1\SupplierReturnController::class)->post($request, $draft);
            $this->fail('A viewer posted a supplier return.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('10.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        $this->assertSame(0, InventoryAuditLog::query()->where('action', 'inactive_item.corrective_movement')->count());
    }
}
