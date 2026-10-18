<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Requirement 4 (owner 2026-10-18): outstanding purchase-order lines and open pick lists block item
 * deactivation; completed and cancelled documents do not. (Receiving requests: InactiveItemReceivingRequestTest.)
 */
class DeactivationOutstandingObligationsTest extends TestCase
{
    use TenantAware;

    private function item(): Item
    {
        return F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
    }

    /** Real deactivation request path (UpdateItemRequest + ItemController::update). @return array{0:int,1:list<string>} */
    private function deactivate(Item $item): array
    {
        $req = UpdateItemRequest::create('/api/v1/items/'.$item->id, 'PUT', ['is_active' => false]);
        $req->setRouteResolver(fn () => new class($item) {
            public function __construct(private Item $item) {}
            public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
        });
        $req->setContainer(app())->setRedirector(app('redirect'));
        try {
            $req->validateResolved();

            return [app(ItemController::class)->update($req, $item)->getStatusCode(), []];
        } catch (ValidationException $e) {
            return [$e->status, $e->errors()['deactivation_blockers'] ?? []];
        }
    }

    private function assertBlocked(Item $item, string $blocker, string $case): void
    {
        [$status, $blockers] = $this->deactivate($item);
        $this->assertSame(422, $status, $case);
        $this->assertContains($blocker, $blockers, $case);
        $this->assertTrue((bool) $item->fresh()->is_active, $case);
    }

    private function assertDeactivates(Item $item, string $case): void
    {
        $this->assertSame(200, $this->deactivate($item)[0], $case);
        $this->assertFalse((bool) $item->fresh()->is_active, $case);
    }

    private function purchaseOrder(Item $item, int $warehouseId, string $status, string $ordered = '10', string $received = '0'): void
    {
        static $n = 0;
        $db = DB::connection('tenant');
        $po = $db->table('inventory_purchase_orders')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'po_number' => 'Q12PO-'.(++$n),
            'warehouse_id' => $warehouseId, 'order_date' => '2026-10-18', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('purchase_order_lines')->insert(['organization_id' => TenantTestManager::ORG_A, 'purchase_order_id' => $po, 'item_id' => $item->id,
            'ordered_qty' => $ordered, 'received_qty' => $received, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function pickList(Item $item, int $warehouseId, string $status): void
    {
        static $n = 0;
        $db = DB::connection('tenant');
        $so = $db->table('inventory_sales_orders')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'order_number' => 'Q12SO-'.(++$n),
            'order_date' => '2026-10-18', 'warehouse_id' => $warehouseId, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        $pl = $db->table('pick_lists')->insertGetId(['organization_id' => TenantTestManager::ORG_A, 'pick_number' => 'Q12PL-'.$n,
            'sales_order_id' => $so, 'warehouse_id' => $warehouseId, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('pick_list_lines')->insert(['organization_id' => TenantTestManager::ORG_A, 'pick_list_id' => $pl, 'item_id' => $item->id,
            'warehouse_id' => $warehouseId, 'reserved_qty' => '0', 'picked_qty' => '0', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    #[Test]
    public function outstanding_purchase_order_lines_block_deactivation(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse()->id;
        foreach (['draft' => ['10', '0'], 'approved' => ['10', '0'], 'partially_received' => ['10', '4']] as $status => [$ordered, $received]) {
            $item = $this->item();
            $this->purchaseOrder($item, $warehouse, $status, $ordered, $received);
            $this->assertBlocked($item, 'open_inventory_purchase_orders', $status);
        }
    }

    #[Test]
    public function received_closed_cancelled_or_fully_received_purchase_order_lines_do_not_block(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse()->id;
        foreach (['received' => ['10', '10'], 'closed' => ['10', '6'], 'cancelled' => ['10', '0'], 'partially_received' => ['5', '5']] as $status => [$ordered, $received]) {
            $item = $this->item();
            $this->purchaseOrder($item, $warehouse, $status, $ordered, $received);
            $this->assertDeactivates($item, $status);
        }
    }

    #[Test]
    public function draft_or_picking_pick_lists_block_and_picked_or_cancelled_do_not(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse()->id;
        foreach (['draft' => true, 'picking' => true, 'picked' => false, 'cancelled' => false] as $status => $blocks) {
            $item = $this->item();
            $this->pickList($item, $warehouse, $status);
            $blocks ? $this->assertBlocked($item, 'open_pick_lists', $status) : $this->assertDeactivates($item, $status);
        }
    }
}
