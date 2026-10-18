<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockAdjustment;
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
 * Requirement 2 (owner 2026-10-18): draft creation and deactivation follow one locking contract (item row
 * FOR UPDATE). Two real MariaDB sessions (forked process A holds the lock, parent B contends), both orders:
 *  (i)  A deactivates and holds the lock; B's draft creation waits, then is refused (item inactive);
 *  (ii) A creates a draft and holds the lock; B's deactivation waits, then is refused (open draft).
 */
#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class DraftDeactivationRaceTest extends TestCase
{
    use TenantAware;

    private const HOLD_US = 2_000_000;

    private function catalogItem(): Item
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

    public function testDraftCreationWaitsForALockedDeactivationAndIsThenRefused(): void
    {
        [$fixture, $warehouseId, $itemId] = $this->prepare();
        [$pid, $root] = $this->forkHolder(fn () => self::deactivate($itemId)[0]);
        self::assertSame('200', file_get_contents($root.'/result'), 'A deactivated the clean item');

        $started = microtime(true);
        $errors = [];
        try {
            self::draft($warehouseId, $itemId, 'RACE-I');
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        $finished = microtime(true);
        $this->finish($pid, $root, $started, $finished);

        self::assertSame([$itemId], $errors['inactive_item_ids'] ?? null, 'B must be refused once A committed the deactivation');
        self::assertFalse((bool) Item::query()->findOrFail($itemId)->is_active);
        self::assertSame(0, StockAdjustment::query()->where('adjustment_number', 'RACE-I')->count(), 'no draft header or line survives');
        self::assertSame(0, DB::connection('tenant')->table('stock_adjustment_lines')->where('item_id', $itemId)->count());
        $fixture->restore();
    }

    public function testDeactivationWaitsForALockedDraftCreationAndIsThenRefused(): void
    {
        [$fixture, $warehouseId, $itemId] = $this->prepare();
        [$pid, $root] = $this->forkHolder(fn () => self::draft($warehouseId, $itemId, 'RACE-II')->id);

        $started = microtime(true);
        [$status, $errors] = self::deactivate($itemId);
        $finished = microtime(true);
        $this->finish($pid, $root, $started, $finished);

        self::assertSame(422, $status, 'B must be refused once A committed the draft');
        self::assertContains('open_stock_adjustments', $errors['deactivation_blockers'] ?? []);
        self::assertTrue((bool) Item::query()->findOrFail($itemId)->is_active);
        self::assertSame(1, StockAdjustment::query()->where('adjustment_number', 'RACE-II')->where('status', 'draft')->count());
        $fixture->restore();
        // Remove the committed draft so later classes sharing this disposable schema start clean.
        $ids = DB::connection('tenant')->table('stock_adjustments')->where('adjustment_number', 'RACE-II')->pluck('id');
        DB::connection('tenant')->table('stock_adjustment_lines')->whereIn('stock_adjustment_id', $ids)->delete();
        DB::connection('tenant')->table('stock_adjustments')->whereIn('id', $ids)->delete();
    }
}
