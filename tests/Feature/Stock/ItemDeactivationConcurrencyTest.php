<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\OpeningStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommittedTenantFixture;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Q12.3 / NF-1194-02: two real MariaDB sessions. Session A (forked process) posts a stock movement and
 * holds its item row lock; session B's deactivation must wait on that lock and, once A commits, refuse.
 */
#[\PHPUnit\Framework\Attributes\Group('committed-native-transport')]
final class ItemDeactivationConcurrencyTest extends TestCase
{
    use TenantAware;

    private static function catalogItem(): Item
    {
        return F::averageItem([
            'category_id' => \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => \App\Models\Tenant\Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ]);
    }

    public function testDeactivationWaitsForALockedMovementAndIsThenRefused(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'Real session contention requires pcntl');
        $this->useTenantA();
        $fixture = new CommittedTenantFixture($this->tenantTestManager);
        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->andReturn(true));
        InventorySetting::updateOrCreate(['organization_id' => TenantTestManager::ORG_A], ['allow_negative_stock' => false]);
        $warehouse = F::warehouse();
        $item = self::catalogItem();
        $opening = app(OpeningStockService::class);
        $draftId = $opening->createDraft(['entry_number' => 'DG-RACE-OS', 'warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'quantity' => '5', 'unit_cost' => '2']])->id;
        $fixture->commit();

        $root = sys_get_temp_dir().'/deactivation-race-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        DB::disconnect('tenant');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            DB::purge('tenant');
            try {
                DB::connection('tenant')->transaction(function () use ($draftId, $root) {
                    $service = app(OpeningStockService::class);
                    $service->post(\App\Models\Tenant\OpeningStockEntry::query()->findOrFail($draftId));
                    file_put_contents($root.'/locked', (string) microtime(true));
                    usleep(2_000_000); // hold the item row lock taken by StockLedgerService
                    file_put_contents($root.'/committing', (string) microtime(true));
                });
                file_put_contents($root.'/a', '1');
            } catch (\Throwable $e) {
                file_put_contents($root.'/a', $e::class.': '.$e->getMessage());
            }
            exit(0);
        }

        for ($i = 0; $i < 3000 && ! is_file($root.'/locked'); $i++) {
            usleep(5000);
        }
        DB::purge('tenant');
        self::assertFileExists($root.'/locked', 'session A never took its lock');

        $started = microtime(true);
        $status = 200;
        $errors = [];
        try {
            $fresh = Item::query()->findOrFail($item->id);
            $req = UpdateItemRequest::create('/api/v1/items/'.$item->id, 'PUT', ['is_active' => false]);
            $req->setRouteResolver(fn () => new class($fresh) {
                public function __construct(private Item $item) {}
                public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
            });
            $req->setContainer(app())->setRedirector(app('redirect'));
            $req->validateResolved();
            $status = app(ItemController::class)->update($req, $fresh)->getStatusCode();
        } catch (ValidationException $e) {
            $status = $e->status;
            $errors = $e->errors();
        }
        $finished = microtime(true);
        pcntl_waitpid($pid, $childStatus);

        self::assertSame('1', file_get_contents($root.'/a'), 'session A movement must commit');
        $committingAt = (float) file_get_contents($root.'/committing');
        self::assertGreaterThanOrEqual($committingAt, $finished, 'B finished only after A released its lock');
        self::assertGreaterThan(1.0, $finished - $started, 'B waited on the item row lock held by A');
        self::assertSame(422, $status, 'B must be refused once A committed stock');
        self::assertContains('stock_on_hand', $errors['deactivation_blockers'] ?? []);

        DB::purge('tenant');
        self::assertTrue((bool) Item::query()->findOrFail($item->id)->is_active);
        self::assertSame('5.0000', (string) StockBalance::query()->where('item_id', $item->id)->value('on_hand_qty'));
        foreach (['locked', 'committing', 'a'] as $f) {
            @unlink($root.'/'.$f);
        }
        rmdir($root);
        $fixture->restore();
    }
}
