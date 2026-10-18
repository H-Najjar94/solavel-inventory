<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommittedTenantFixture;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Owner scope confirmation 2026-10-18: a linked sales return or a stock count racing a deactivation, two real
 * MariaDB sessions, both orders. The correction locks the item row like any document writer.
 *  (i)  A deactivates (item has no stock) and holds; B's linked sales return / count waits, then proceeds as the
 *       controlled correction of an inactive item and is audited (item_inactive = true);
 *  (ii) A creates the return / count draft and holds; B's deactivation waits, then is refused (open document).
 */
#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class InactiveCorrectionRaceTest extends TestCase
{
    use TenantAware;

    private const HOLD_US = 2_000_000;

    private function catalogItemUnused(): Item
    {
        return F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private static function deactivate(int $itemId): array
    {
        $fresh = Item::query()->findOrFail($itemId);
        $req = UpdateItemRequest::create('/api/v1/items/'.$itemId, 'PUT', ['is_active' => false]);
        $req->setRouteResolver(fn () => new class($fresh) {
            public function __construct(private Item $item) {}
            public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
        });
        $req->setContainer(app())->setRedirector(app('redirect'));
        try {
            $req->validateResolved();

            return [app(ItemController::class)->update($req, $fresh)->getStatusCode(), []];
        } catch (ValidationException $e) {
            return [$e->status, $e->errors()];
        }
    }

    private static function draft(int $warehouseId, int $itemId, string $number): StockAdjustment
    {
        return app(StockAdjustmentService::class)->createDraft(['adjustment_number' => $number, 'warehouse_id' => $warehouseId, 'reason_code' => 'FOUND'],
            [['item_id' => $itemId, 'direction' => 'increase', 'quantity' => '3', 'unit_cost' => '2']]);
    }

    /**
     * Fork session A: run $holder inside a transaction, signal 'locked', hold for HOLD_US, signal 'committing', commit.
     * Returns [pid, root].
     */
    private function forkHolder(callable $holder): array
    {
        $root = sys_get_temp_dir().'/draft-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        DB::disconnect('tenant');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            DB::purge('tenant');
            try {
                DB::connection('tenant')->transaction(function () use ($holder, $root) {
                    $result = $holder();
                    file_put_contents($root.'/result', json_encode($result));
                    file_put_contents($root.'/locked', (string) microtime(true));
                    usleep(self::HOLD_US);
                    file_put_contents($root.'/committing', (string) microtime(true));
                });
                file_put_contents($root.'/a', '1');
            } catch (\Throwable $e) {
                file_put_contents($root.'/a', $e::class.': '.$e->getMessage());
            }
            exit(0);
        }
        for ($i = 0; $i < 3000 && ! is_file($root.'/locked') && ! is_file($root.'/a'); $i++) {
            usleep(5000);
        }
        DB::purge('tenant');
        self::assertFileExists($root.'/locked', 'session A never took its lock: '.@file_get_contents($root.'/a'));

        return [$pid, $root];
    }

    private function finish(int $pid, string $root, float $started, float $finished): void
    {
        pcntl_waitpid($pid, $childStatus);
        self::assertSame('1', file_get_contents($root.'/a'), 'session A must commit');
        self::assertGreaterThanOrEqual((float) file_get_contents($root.'/committing'), $finished, 'B finished only after A released the item lock');
        self::assertGreaterThan(1.0, $finished - $started, 'B waited on the item row lock held by A');
        foreach (['locked', 'committing', 'a', 'result'] as $f) {
            @unlink($root.'/'.$f);
        }
        rmdir($root);
        DB::purge('tenant');
    }

    private function prepare(): array
    {
        self::assertTrue(function_exists('pcntl_fork'), 'Real session contention requires pcntl');
        $this->useTenantA();
        $fixture = new CommittedTenantFixture($this->tenantTestManager);
        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->andReturn(true));
        InventorySetting::updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['allow_negative_stock' => false]);
        $warehouse = F::warehouse();
        $item = $this->catalogItem();
        $fixture->commit();

        return [$fixture, $warehouse->id, $item->id];
    }

    /** Item fully shipped on a posted shipment: on hand 0, deactivation possible. @return array{0:int,1:int,2:int} warehouse, item, shipment line */
    private function shippedOut(): array
    {
        $warehouse = F::warehouse();
        $item = F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
        $opening = app(\App\Services\Documents\OpeningStockService::class);
        $opening->post($opening->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '5']]));
        $sales = app(\App\Services\Documents\SalesOrderService::class);
        $order = $sales->reserve($sales->confirm($sales->createDraft(['order_number' => 'Q12-SO-'.uniqid(), 'warehouse_id' => $warehouse->id, 'customer_name' => 'Race customer'], [['item_id' => $item->id, 'ordered_qty' => '2', 'unit_price' => '20']])));
        $shipments = app(\App\Services\Documents\ShipmentService::class);
        $shipment = $shipments->createDraft(['shipment_number' => 'Q12-SH-'.uniqid(), 'sales_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'ship_date' => now()->toDateString()], $shipments->fromSalesOrder($order));
        $shipments->post($shipment);

        return [$warehouse->id, $item->id, (int) $shipment->fresh('lines')->lines->sole()->id, (int) $shipment->id];
    }

    private function prepareShipped(): array
    {
        self::assertTrue(function_exists('pcntl_fork'));
        $this->useTenantA();
        $fixture = new CommittedTenantFixture($this->tenantTestManager);
        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->andReturn(true));
        $user = new \App\Models\User;
        $user->id = 323;
        \Illuminate\Support\Facades\Auth::setUser($user);
        request()->setUserResolver(fn () => $user);
        $scope = $this->createStub(\App\Services\Access\WarehouseAccessService::class);
        $scope->method('scope')->willReturnCallback(fn ($q) => $q);
        $this->app->instance(\App\Services\Access\WarehouseAccessService::class, $scope);
        InventorySetting::updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['allow_negative_stock' => false]);
        $ids = $this->shippedOut();
        $fixture->commit();

        return [$fixture, ...$ids];
    }

    private static function salesReturn(int $warehouseId, int $itemId, int $lineId, int $shipmentId, bool $post): int
    {
        $returns = app(\App\Services\Documents\SalesReturnService::class);
        $r = $returns->createDraft(['return_number' => 'Q12-RMA-'.uniqid(), 'shipment_id' => $shipmentId, 'warehouse_id' => $warehouseId, 'return_date' => now()->toDateString(), 'reason' => 'Race return'],
            [['source_line_id' => $lineId, 'item_id' => $itemId, 'returned_qty' => '1', 'condition' => 'resellable']]);
        if ($post) {
            $returns->post($r);
        }

        return (int) $r->id;
    }

    private static function stockCount(int $warehouseId, int $itemId, bool $post): int
    {
        $counts = app(\App\Services\Documents\StockCountService::class);
        $c = $counts->createDraft(['warehouse_id' => $warehouseId], [['item_id' => $itemId, 'system_qty' => '0', 'counted_qty' => '1']]);
        if ($post) {
            $counts->post($c);
        }

        return (int) $c->id;
    }

    private static function onHand(int $itemId): string
    {
        return (string) StockBalance::query()->where('item_id', $itemId)->sum('on_hand_qty');
    }

    private function correctiveAudit(int $itemId, string $kind): bool
    {
        return \App\Models\Tenant\InventoryAuditLog::query()->where('action', 'inactive_item.corrective_movement')->get()
            ->contains(fn ($a) => ($a->before['kind'] ?? null) === $kind && (int) $a->before['item_id'] === $itemId && $a->before['item_inactive'] === true);
    }

    public function testLinkedSalesReturnWaitsForALockedDeactivationThenProceedsAsCorrection(): void
    {
        [$fixture, $wh, $item, $line, $shipment] = $this->prepareShipped();
        [$pid, $root] = $this->forkHolder(fn () => self::deactivate($item)[0]);
        self::assertSame('200', file_get_contents($root.'/result'));
        $started = microtime(true);
        self::salesReturn($wh, $item, $line, $shipment, true);
        $this->finish($pid, $root, $started, microtime(true));
        self::assertFalse((bool) Item::query()->findOrFail($item)->is_active);
        self::assertSame('1.0000', self::onHand($item));
        self::assertTrue($this->correctiveAudit($item, 'sales_return'));
        $fixture->restore();
    }

    public function testDeactivationWaitsForALockedSalesReturnDraftThenIsRefused(): void
    {
        [$fixture, $wh, $item, $line, $shipment] = $this->prepareShipped();
        [$pid, $root] = $this->forkHolder(fn () => self::salesReturn($wh, $item, $line, $shipment, false));
        $started = microtime(true);
        [$status, $errors] = self::deactivate($item);
        $this->finish($pid, $root, $started, microtime(true));
        self::assertSame(422, $status);
        self::assertContains('open_sales_returns', $errors['deactivation_blockers'] ?? []);
        self::assertTrue((bool) Item::query()->findOrFail($item)->is_active);
        $fixture->restore();
    }

    public function testStockCountWaitsForALockedDeactivationThenPostsAsCorrection(): void
    {
        [$fixture, $wh, $item] = $this->prepareShipped();
        [$pid, $root] = $this->forkHolder(fn () => self::deactivate($item)[0]);
        self::assertSame('200', file_get_contents($root.'/result'));
        $started = microtime(true);
        self::stockCount($wh, $item, true);
        $this->finish($pid, $root, $started, microtime(true));
        self::assertFalse((bool) Item::query()->findOrFail($item)->is_active);
        self::assertSame('1.0000', self::onHand($item));
        self::assertTrue($this->correctiveAudit($item, 'stock_count'));
        $fixture->restore();
    }

    public function testDeactivationWaitsForALockedStockCountDraftThenIsRefused(): void
    {
        [$fixture, $wh, $item] = $this->prepareShipped();
        [$pid, $root] = $this->forkHolder(fn () => self::stockCount($wh, $item, false));
        $started = microtime(true);
        [$status, $errors] = self::deactivate($item);
        $this->finish($pid, $root, $started, microtime(true));
        self::assertSame(422, $status);
        self::assertContains('open_stock_counts', $errors['deactivation_blockers'] ?? []);
        self::assertTrue((bool) Item::query()->findOrFail($item)->is_active);
        $fixture->restore();
    }
}
