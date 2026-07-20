<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\StockTransferController;
use App\Services\Documents\OpeningStockService;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class TransferAvailabilityTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function availability_remains_unambiguous_when_lot_and_bin_tables_are_joined(): void
    {
        $this->useTenantA();
        $warehouse = F::warehouse();
        $item = F::lotItem();
        $lot = F::lot($item);
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(
            ['entry_number' => 'TRANSFER-AVAILABILITY', 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'lot_id' => $lot->id, 'quantity' => '3.0000', 'unit_cost' => '2.0000']]
        ));

        $response = app(StockTransferController::class)->available(Request::create('/', 'GET', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
        ]));

        $this->assertSame('3.0000', $response->getData(true)['data']['available_qty']);
    }
}
