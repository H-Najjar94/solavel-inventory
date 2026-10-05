<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Requests\Api\StoreStockCountRequest;
use App\Models\Tenant\StockCount;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class StockCountDraftEditTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function typed_count_numbers_are_ignored_on_draft_edit(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'CNT-NUM']);
        $item = F::averageItem(['sku' => 'CNT-NUM-ITEM']);
        $controller = app(StockCountController::class);

        $created = $controller->store($this->request('POST', [
            'count_number' => 'USER-TYPED-1', 'count_type' => 'cycle', 'warehouse_id' => $warehouse->id,
            'lines' => [['item_id' => $item->id, 'system_qty' => '3', 'counted_qty' => '3']],
        ]));
        $count = StockCount::query()->findOrFail($created->getData(true)['data']['id']);
        $assigned = $count->count_number;
        $this->assertStringStartsWith('CNT', $assigned);

        $controller->update($this->request('PUT', [
            'count_number' => 'USER-TYPED-2', 'count_type' => 'cycle', 'warehouse_id' => $warehouse->id,
            'lines' => [['item_id' => $item->id, 'system_qty' => '3', 'counted_qty' => '4']],
        ]), $count);

        $this->assertSame($assigned, $count->fresh()->count_number);
    }

    #[Test]
    public function blind_draft_hides_expected_quantities_and_keeps_them_on_edit(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse(['code' => 'CNT-BLIND']);
        $item = F::averageItem(['sku' => 'CNT-BLIND-ITEM']);
        $controller = app(StockCountController::class);

        $created = $controller->store($this->request('POST', [
            'count_type' => 'cycle', 'blind_count' => true, 'freeze_snapshot' => true, 'warehouse_id' => $warehouse->id,
            'lines' => [['item_id' => $item->id, 'system_qty' => '7', 'counted_qty' => null]],
        ]));
        $count = StockCount::query()->findOrFail($created->getData(true)['data']['id']);

        $line = $controller->show($count)->getData(true)['data']['count']['lines'][0];
        $this->assertArrayNotHasKey('system_qty', $line);
        $this->assertArrayNotHasKey('snapshot_qty', $line);
        $this->assertArrayNotHasKey('variance_qty', $line);

        // The blind form sends no system_qty back; the stored expectation survives.
        $controller->update($this->request('PUT', [
            'count_type' => 'cycle', 'blind_count' => true, 'freeze_snapshot' => true, 'warehouse_id' => $warehouse->id,
            'lines' => [['item_id' => $item->id, 'counted_qty' => '5']],
        ]), $count);
        $stored = $count->fresh('lines')->lines->first();
        $this->assertSame('7.0000', (string) $stored->system_qty);
        $this->assertSame('7.0000', (string) $stored->snapshot_qty);
        $this->assertSame('-2.0000', (string) $stored->variance_qty);

        // A non-blind draft still returns its expected quantity.
        StockCount::query()->whereKey($count->id)->update(['blind_count' => false]);
        $this->assertArrayHasKey('system_qty', $controller->show($count->fresh())->getData(true)['data']['count']['lines'][0]);
    }

    private function request(string $method, array $data): StoreStockCountRequest
    {
        $request = StoreStockCountRequest::create('/counts', $method, $data);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }
}
