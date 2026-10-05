<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Models\Tenant\StockTransfer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class TransferNumberServerOwnedTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function typed_transfer_numbers_are_ignored_on_create_and_on_draft_edit(): void
    {
        $this->useTenantA();
        $from = F::warehouse(['code' => 'TRN-A']);
        $to = F::warehouse(['code' => 'TRN-B']);
        $item = F::averageItem(['sku' => 'TRN-ITEM']);
        $controller = app(StockTransferController::class);
        $payload = fn (string $number) => [
            'transfer_number' => $number,
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '1']],
        ];

        $created = $controller->store($this->request('POST', $payload('USER-TYPED-1')));
        $this->assertSame(201, $created->getStatusCode());
        $transfer = StockTransfer::query()->findOrFail($created->getData(true)['data']['id']);
        $assigned = $transfer->transfer_number;
        $this->assertNotSame('USER-TYPED-1', $assigned);
        $this->assertStringStartsWith('TRF', $assigned);

        $updated = $controller->update($this->request('PUT', $payload('USER-TYPED-2')), $transfer);
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame($assigned, $transfer->fresh()->transfer_number);
    }

    private function request(string $method, array $data): StoreStockTransferRequest
    {
        $request = StoreStockTransferRequest::create('/transfers', $method, $data);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }
}
