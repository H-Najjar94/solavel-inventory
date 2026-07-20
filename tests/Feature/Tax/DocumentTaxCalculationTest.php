<?php

namespace Tests\Feature\Tax;

use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\PurchaseOrder;
use App\Services\Documents\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class DocumentTaxCalculationTest extends TestCase
{
    use TenantAware;

    private array $taxes = [
        ['code' => 'STD15', 'name' => 'Standard 15%', 'rate' => 15, 'treatment' => 'standard', 'active' => true, 'purchase' => true, 'sales' => true],
        ['code' => 'ZERO', 'name' => 'Zero rated', 'rate' => 0, 'treatment' => 'zero', 'active' => true, 'purchase' => true, 'sales' => true],
        ['code' => 'EXEMPT', 'name' => 'Exempt', 'rate' => 15, 'treatment' => 'exempt', 'active' => true, 'purchase' => true, 'sales' => true],
        ['code' => 'OFF', 'name' => 'Inactive', 'rate' => 15, 'treatment' => 'standard', 'active' => false, 'purchase' => true, 'sales' => true],
    ];

    private function bootTaxTenant(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'fifo', 'allow_negative_stock' => false, 'taxes' => $this->taxes],
        );
    }

    private function purchase(array $lines): PurchaseOrder
    {
        $request = StorePurchaseOrderRequest::create('/api/v1/purchase-orders', 'POST', [
            'warehouse_id' => F::warehouse()->id,
            'order_date' => now()->toDateString(),
            'lines' => $lines,
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $id = app(PurchaseOrderController::class)->store($request)->getData(true)['data']['id'];

        return PurchaseOrder::query()->with('lines')->findOrFail($id);
    }

    #[Test]
    public function purchase_and_sales_documents_use_canonical_tax_definitions_and_snapshot_totals(): void
    {
        $this->bootTaxTenant();
        $standard = F::item(['sku' => 'TAX-STD']);
        $zero = F::item(['sku' => 'TAX-ZERO']);
        $exempt = F::item(['sku' => 'TAX-EXEMPT']);

        $po = $this->purchase([
            ['item_id' => $standard->id, 'ordered_qty' => '2', 'unit_price' => '10', 'tax_code' => 'STD15'],
            ['item_id' => $zero->id, 'ordered_qty' => '1', 'unit_price' => '5', 'tax_code' => 'ZERO'],
            ['item_id' => $exempt->id, 'ordered_qty' => '1', 'unit_price' => '7', 'tax_code' => 'EXEMPT'],
        ]);
        $this->assertSame('32.00', (string) $po->subtotal);
        $this->assertSame('3.00', (string) $po->tax_total);
        $this->assertSame('35.00', (string) $po->total);
        $this->assertSame(['3.00', '0.00', '0.00'], $po->lines->pluck('tax_amount')->map(fn ($amount) => (string) $amount)->all());
        $this->assertSame(['standard', 'zero', 'exempt'], $po->lines->pluck('tax_treatment')->all());

        $sales = app(SalesOrderService::class)->createDraft([
            'warehouse_id' => $po->warehouse_id,
        ], [[
            'item_id' => $standard->id,
            'ordered_qty' => '3',
            'unit_price' => '10',
            'discount_rate' => '10',
            'tax_code' => 'STD15',
        ]]);
        $this->assertSame('30.00', (string) $sales->subtotal);
        $this->assertSame('3.00', (string) $sales->discount_total);
        $this->assertSame('4.05', (string) $sales->tax_total);
        $this->assertSame('31.05', (string) $sales->total);
        $this->assertSame('standard', $sales->lines->first()->tax_treatment);

        InventorySetting::query()->firstOrFail()->update([
            'taxes' => array_map(fn (array $tax) => $tax['code'] === 'STD15' ? array_merge($tax, ['rate' => 20]) : $tax, $this->taxes),
        ]);
        $this->assertSame('3.00', (string) $po->fresh()->tax_total);
        $this->assertSame('4.05', (string) $sales->fresh()->tax_total);
    }

    #[Test]
    public function inactive_or_unknown_tax_codes_are_rejected_when_definitions_exist(): void
    {
        $this->bootTaxTenant();
        $item = F::item(['sku' => 'TAX-REJECT']);

        foreach (['OFF', 'UNKNOWN'] as $code) {
            try {
                $this->purchase([['item_id' => $item->id, 'ordered_qty' => '1', 'unit_price' => '10', 'tax_code' => $code]]);
                $this->fail("Tax code {$code} should have been rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tax_code', $exception->errors());
            }
        }
    }

    #[Test]
    public function tax_definition_codes_are_normalized_and_case_insensitive_duplicates_are_rejected(): void
    {
        $this->useTenantA();
        $controller = app(SettingsController::class);

        try {
            $controller->updateTaxes(Request::create('/settings/taxes', 'PUT', ['taxes' => [
                ['code' => ' vat16 ', 'name' => 'VAT one', 'rate' => 16, 'treatment' => 'standard', 'active' => true, 'purchase' => true, 'sales' => true],
                ['code' => 'VAT16', 'name' => 'VAT two', 'rate' => 16, 'treatment' => 'standard', 'active' => true, 'purchase' => true, 'sales' => true],
            ]]));
            $this->fail('Case-insensitive duplicate tax codes must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    #[Test]
    public function tax_defaults_are_validated_and_used_historical_codes_cannot_be_removed(): void
    {
        $this->bootTaxTenant();
        $controller = app(SettingsController::class);
        $saved = $controller->updateTaxes(Request::create('/settings/taxes', 'PUT', [
            'taxes' => $this->taxes,
            'default_purchase_tax_code' => 'STD15',
            'default_sales_tax_code' => 'ZERO',
        ]))->getData(true)['data'];
        $this->assertSame('STD15', $saved['default_purchase_tax_code']);
        $this->assertSame('ZERO', $saved['default_sales_tax_code']);

        $item = F::item(['sku' => 'TAX-HISTORY']);
        $this->purchase([['item_id' => $item->id, 'ordered_qty' => '1', 'unit_price' => '10', 'tax_code' => 'STD15']]);
        try {
            $controller->updateTaxes(Request::create('/settings/taxes', 'PUT', [
                'taxes' => array_values(array_filter($this->taxes, fn (array $tax) => $tax['code'] !== 'STD15')),
            ]));
            $this->fail('A tax referenced by a historical document must not be removed.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        try {
            $controller->updateTaxes(Request::create('/settings/taxes', 'PUT', [
                'taxes' => $this->taxes,
                'default_purchase_tax_code' => 'OFF',
            ]));
            $this->fail('An inactive tax must not be accepted as a default.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }
}
