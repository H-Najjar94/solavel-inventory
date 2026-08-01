<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use App\Services\Documents\StockAdjustmentService;
use App\Services\Documents\StockTransferService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class AuthoritativeUnitConversionContractTest extends TestCase
{
    use TenantAware;

    public function test_item_scoped_factor_and_rounding_are_deterministic(): void
    {
        $this->useTenantA();
        $each = Unit::create(['code' => 'EA-C', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $box = Unit::create(['code' => 'BOX-C', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $item = F::item(['base_unit_id' => $each->id]);
        $conversion = UnitConversion::create([
            'item_id' => $item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => '10',
        ]);
        $resolver = app(UnitConversionResolver::class);

        $line = $resolver->normalizeLine([
            'item_id' => $item->id, 'entered_qty' => '2', 'entered_unit_id' => $box->id, 'quantity' => '2',
        ], 'quantity');
        $this->assertSame('20.0000', $line['quantity']);
        $this->assertSame('10.00000000', $line['unit_conversion_factor']);
        $this->assertSame($conversion->id, $line['unit_conversion_id']);
        $this->assertSame(UnitConversionResolver::CONTRACT_VERSION, $line['unit_conversion_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $line['unit_conversion_hash']);

        $fractional = $resolver->normalizeLine([
            'item_id' => $item->id, 'entered_qty' => '0.12345', 'entered_unit_id' => $box->id, 'quantity' => '0.12345',
        ], 'quantity');
        $this->assertSame('0.1235', $fractional['entered_qty']);
        $this->assertSame('1.2350', $fractional['quantity']);
    }

    public function test_invalid_inactive_cross_item_and_cross_organization_conversions_fail_closed(): void
    {
        $this->useTenantA();
        $each = Unit::create(['code' => 'EA-X', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $box = Unit::create(['code' => 'BOX-X', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $item = F::item(['base_unit_id' => $each->id]);
        $otherItem = F::item(['base_unit_id' => $each->id]);
        UnitConversion::create([
            'item_id' => $otherItem->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => '10',
        ]);
        $input = ['item_id' => $item->id, 'entered_qty' => '1', 'entered_unit_id' => $box->id, 'quantity' => '1'];
        $this->assertFails(fn () => app(UnitConversionResolver::class)->normalizeLine($input, 'quantity'));

        $bad = UnitConversion::create([
            'item_id' => $item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => '0',
        ]);
        $this->assertFails(fn () => app(UnitConversionResolver::class)->normalizeLine($input, 'quantity'));
        $bad->update(['factor' => '-1']);
        $this->assertFails(fn () => app(UnitConversionResolver::class)->normalizeLine($input, 'quantity'));
        $bad->update(['factor' => '10']);
        $box->update(['is_active' => false]);
        $this->assertFails(fn () => app(UnitConversionResolver::class)->normalizeLine($input, 'quantity'));

        $foreignUnit = DB::connection('tenant')->table('units')->insertGetId([
            'organization_id' => 999999, 'code' => 'FOREIGN', 'name' => 'Foreign', 'kind' => 'count',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cross = $input;
        $cross['entered_unit_id'] = $foreignUnit;
        $this->assertFails(fn () => app(UnitConversionResolver::class)->normalizeLine($cross, 'quantity'));
    }

    public function test_alternate_units_are_persisted_for_shipment_return_transfer_and_adjustment_lines(): void
    {
        $this->useTenantA();
        $each = Unit::create(['code' => 'EA-W', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $box = Unit::create(['code' => 'BOX-W', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $item = F::item(['base_unit_id' => $each->id]);
        UnitConversion::create([
            'item_id' => $item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => '10',
        ]);
        $from = F::warehouse(['code' => 'FROM-W']);
        $to = F::warehouse(['code' => 'TO-W']);
        $order = SalesOrder::create([
            'order_number' => 'SO-UNIT', 'order_date' => now()->toDateString(),
            'warehouse_id' => $from->id, 'status' => 'draft',
        ]);
        $line = ['item_id' => $item->id, 'quantity' => '1.25', 'entered_qty' => '1.25', 'entered_unit_id' => $box->id];

        $shipment = app(ShipmentService::class)->createDraft([
            'shipment_number' => 'SHP-UNIT', 'sales_order_id' => $order->id,
            'warehouse_id' => $from->id, 'ship_date' => now()->toDateString(),
        ], [$line]);
        $return = app(SalesReturnService::class)->createDraft([
            'return_number' => 'RET-UNIT', 'warehouse_id' => $from->id, 'return_date' => now()->toDateString(),
        ], [[...$line, 'returned_qty' => $line['quantity'], 'condition' => 'resellable']]);
        $transfer = app(StockTransferService::class)->createDraft([
            'transfer_number' => 'TRF-UNIT', 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'transfer_date' => now()->toDateString(),
        ], [$line]);
        $adjustment = app(StockAdjustmentService::class)->createDraft([
            'adjustment_number' => 'ADJ-UNIT', 'warehouse_id' => $from->id, 'adjustment_date' => now()->toDateString(),
        ], [[...$line, 'direction' => 'increase', 'unit_cost' => '2']]);

        foreach ([$shipment->lines->first(), $return->lines->first(), $transfer->lines->first(), $adjustment->lines->first()] as $stored) {
            $quantity = (string) ($stored->quantity ?? $stored->returned_qty);
            $this->assertSame('12.5000', $quantity);
            $this->assertSame('1.2500', (string) $stored->entered_qty);
            $this->assertSame('10.00000000', (string) $stored->unit_conversion_factor);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $stored->unit_conversion_hash);
        }
    }

    private function assertFails(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid conversion must fail closed.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
