<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\FinanceWorkspaceSupportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\Unit;
use App\Models\Tenant\WarehouseReorderRule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** NF-1203-04: reorder levels must satisfy min_stock <= max_stock and reorder_point <= max_stock. */
class ReorderLevelBoundsTest extends TestCase
{
    use TenantAware;

    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    private function rule(array $levels = []): WarehouseReorderRule
    {
        return WarehouseReorderRule::query()->create(array_merge([
            'item_id' => F::averageItem()->id, 'warehouse_id' => F::warehouse()->id,
            'reorder_point' => 2, 'min_stock' => 1, 'max_stock' => 50,
        ], $levels));
    }

    #[Test]
    public function finance_workspace_rule_update_rejects_inconsistent_levels(): void
    {
        $this->useTenantA();
        $rule = $this->rule();
        $errors = $this->errorsOf(fn () => app(FinanceWorkspaceSupportController::class)->updateRule(
            Request::create('/x', 'PUT', ['min_stock' => 10, 'max_stock' => 5, 'reorder_point' => 20]), $rule));
        $this->assertArrayHasKey('max_stock', $errors);
        $this->assertSame('50.0000', (string) $rule->fresh()->max_stock);

        // Partial update merges the stored max (50): rp 60 > 50 is rejected, rp 40 saves.
        $this->assertArrayHasKey('reorder_point', $this->errorsOf(fn () => app(FinanceWorkspaceSupportController::class)
            ->updateRule(Request::create('/x', 'PUT', ['reorder_point' => 60]), $rule)));
        app(FinanceWorkspaceSupportController::class)->updateRule(Request::create('/x', 'PUT', ['reorder_point' => 40]), $rule);
        $this->assertSame('40.0000', (string) $rule->fresh()->reorder_point);
    }

    #[Test]
    public function settings_rule_store_rejects_inconsistent_levels_and_saves_consistent_ones(): void
    {
        $this->useTenantA();
        $item = F::averageItem();
        $warehouse = F::warehouse();
        $payload = ['item_id' => $item->id, 'warehouse_id' => $warehouse->id];

        $errors = $this->errorsOf(fn () => app(SettingsController::class)->storeWarehouseReorderRule(
            Request::create('/x', 'POST', $payload + ['min_stock' => 10, 'max_stock' => 5, 'reorder_point' => 20])));
        $this->assertArrayHasKey('max_stock', $errors);
        $this->assertFalse(WarehouseReorderRule::query()->where('item_id', $item->id)->exists());

        app(SettingsController::class)->storeWarehouseReorderRule(
            Request::create('/x', 'POST', $payload + ['min_stock' => 5, 'max_stock' => 20, 'reorder_point' => 20]));
        $this->assertSame('20.0000', (string) WarehouseReorderRule::query()->where('item_id', $item->id)->value('max_stock'));
    }

    private function storeRequest(array $over): StoreItemRequest
    {
        $req = StoreItemRequest::create('/api/v1/items', 'POST', array_merge([
            'name' => 'Bounded', 'sku' => 'BND-'.uniqid(), 'item_type' => 'inventory', 'costing_method' => 'average',
            'category_id' => ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id,
        ], $over));

        return $req->setContainer(app())->setRedirector(app('redirect'));
    }

    private function updateRequest(Item $item, array $data): UpdateItemRequest
    {
        $req = UpdateItemRequest::create('/api/v1/items/'.$item->id, 'PUT', $data);
        $req->setRouteResolver(fn () => new class($item) {
            public function __construct(private Item $item) {}
            public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
        });

        return $req->setContainer(app())->setRedirector(app('redirect'));
    }

    #[Test]
    public function item_store_rejects_inconsistent_levels_and_accepts_consistent_ones(): void
    {
        $this->useTenantA();
        $errors = $this->errorsOf(fn () => $this->storeRequest(['min_stock' => 10, 'max_stock' => 5, 'reorder_point' => 20])->validateResolved());
        $this->assertArrayHasKey('max_stock', $errors);
        $this->assertArrayHasKey('reorder_point', $errors);

        $this->assertSame([], $this->errorsOf(fn () => $this->storeRequest(['min_stock' => 5, 'max_stock' => 20, 'reorder_point' => 10])->validateResolved()));
        $this->assertSame([], $this->errorsOf(fn () => $this->storeRequest(['min_stock' => 10, 'max_stock' => '', 'reorder_point' => 20])->validateResolved()));
    }

    #[Test]
    public function item_partial_update_merges_stored_levels(): void
    {
        $this->useTenantA();
        $item = F::averageItem(['min_stock' => 2, 'max_stock' => 30, 'reorder_point' => 5,
            'category_id' => ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true])->id,
            'base_unit_id' => Unit::query()->firstOrCreate(['code' => 'EA'], ['name' => 'Each', 'symbol' => 'ea', 'is_active' => true])->id]);

        $this->assertArrayHasKey('reorder_point', $this->errorsOf(fn () => $this->updateRequest($item, ['reorder_point' => 40])->validateResolved()));
        $this->assertArrayHasKey('max_stock', $this->errorsOf(fn () => $this->updateRequest($item, ['max_stock' => 1])->validateResolved()));
        $this->assertArrayHasKey('max_stock', $this->errorsOf(fn () => $this->updateRequest($item, ['min_stock' => 10, 'max_stock' => 5, 'reorder_point' => 20])->validateResolved()));
        $this->assertSame([], $this->errorsOf(fn () => $this->updateRequest($item, ['reorder_point' => 25])->validateResolved()));
        $this->assertSame([], $this->errorsOf(fn () => $this->updateRequest($item, ['name' => 'Renamed'])->validateResolved()));
    }
}
