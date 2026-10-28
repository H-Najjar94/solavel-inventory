<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class LegacyUnitConversionReturnTest extends TestCase
{
    use TenantAware;

    /** Ships entered qty in a box-of-12 (or base) unit; returns [shipment, customer, warehouse, item, line]. */
    private function shipped(string $enteredQty, bool $box = false): array
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $warehouse = F::warehouse();
        $each = Unit::create(['code' => 'EA-L'.uniqid(), 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = F::averageItem(['sales_price' => '20.0000', 'base_unit_id' => $each->id]);
        $unit = $each;
        if ($box) {
            $unit = Unit::create(['code' => 'BX-L'.uniqid(), 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
            UnitConversion::create(['item_id' => $item->id, 'from_unit_id' => $unit->id, 'to_unit_id' => $each->id, 'factor' => '12']);
        }
        $customer = Customer::create(['code' => 'LG-C'.uniqid(), 'name' => 'Legacy', 'contact' => [], 'is_active' => true]);
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'LG-OS'.uniqid(), 'warehouse_id' => $warehouse->id],
            [['item_id' => $item->id, 'quantity' => '100.000000', 'unit_cost' => '5.0000']]));
        $so = app(SalesOrderService::class);
        $order = $so->createDraft(['order_number' => 'LG-SO'.uniqid(), 'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id],
            [['item_id' => $item->id, 'ordered_qty' => $enteredQty, 'entered_qty' => $enteredQty, 'entered_unit_id' => $unit->id, 'unit_price' => '20.0000']]);
        $order = $so->reserve($so->confirm($order));
        $ship = app(ShipmentService::class);
        $shipment = $ship->createDraft(['shipment_number' => 'LG-SH'.uniqid(), 'sales_order_id' => $order->id,
            'warehouse_id' => $warehouse->id, 'ship_date' => now()->toDateString()], $ship->fromSalesOrder($order));
        $ship->post($shipment);
        $shipment = $shipment->fresh('lines');

        return [$shipment, $customer, $warehouse, $item, $shipment->lines->first()];
    }

    /** Rewrite the stored shipment line as the older v1 contract would have frozen it (four decimals). */
    private function makeV1($line, ?string $hash = null, ?string $qty = null): void
    {
        $snap = [
            'organization_id' => (int) $line->organization_id, 'item_id' => (int) $line->item_id,
            'source_unit_id' => (int) $line->entered_unit_id, 'base_unit_id' => (int) $line->base_unit_id,
            'conversion_id' => $line->unit_conversion_id === null ? null : (int) $line->unit_conversion_id,
            'factor' => (string) $line->unit_conversion_factor, 'version' => 'solastock-unit-conversion.v1',
            'precision' => 4, 'rounding_mode' => 'HALF_UP',
        ];
        $upd = ['unit_conversion_version' => 'solastock-unit-conversion.v1', 'unit_conversion_precision' => 4,
            'unit_conversion_hash' => $hash ?? hash('sha256', json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))];
        if ($qty !== null) {
            $upd['entered_qty'] = $qty;
        }
        DB::connection('tenant')->table('shipment_lines')->where('id', $line->id)->update($upd);
    }

    /** Run the posting validator's conversion guard on a return line (it only runs for connected orgs). */
    private function assertValidator($returnLine): void
    {
        $m = new \ReflectionMethod(\App\Services\Integration\WorkflowValidationService::class, 'assertConversionSnapshot');
        $m->setAccessible(true);
        $m->invoke(app(\App\Services\Integration\WorkflowValidationService::class), (object) $returnLine->getAttributes(), (int) $returnLine->organization_id, 'sales_return.posted', true);
    }

    /** The sales_return.posted payload snapshot must equal the shipment line's (Finance compares to the original journal). */
    private function assertPayloadSnapshotEqualsShipment($returnLine, $shipLine): void
    {
        $ledger = \App\Models\Tenant\StockLedger::query()->where('source_type', \App\Models\Tenant\SalesReturn::class)
            ->where('source_id', $returnLine->sales_return_id)->where('source_line_id', $returnLine->id)->first();
        $this->assertNotNull($ledger);
        $m = new \ReflectionMethod(\App\Services\Integration\EventPayloadBuilder::class, 'conversionForLedger');
        $m->setAccessible(true);
        $snap = $m->invoke(app(\App\Services\Integration\EventPayloadBuilder::class), $ledger);
        $this->assertNotNull($snap, json_encode([$ledger->only(["source_type","source_line_id","id","source_id"]), $returnLine->only(["id","sales_return_id"])]).json_encode(DB::connection("tenant")->table("sales_return_lines")->where("id",$ledger->source_line_id)->first()));
        $ship = $shipLine->fresh();
        $this->assertSame((string) $ship->unit_conversion_version, $snap['version']);
        $this->assertSame((string) $ship->unit_conversion_hash, $snap['hash']);
        $this->assertSame((int) $ship->unit_conversion_precision, $snap['precision']);
        $this->assertEquals((float) $ship->unit_conversion_factor, (float) $snap['factor']);
    }

    private function ret(string $n, $shipment, $customer, $warehouse, $item, $line, string $qty, bool $post = true, ?string $unit = null, ?string $entered = null)
    {
        $svc = app(SalesReturnService::class);
        $l = ['source_line_id' => $line->id, 'item_id' => $item->id, 'returned_qty' => $qty, 'entered_qty' => $entered ?? $qty, 'condition' => 'resellable'];
        if ($unit) {
            $l['entered_unit_id'] = $unit;
        }
        $r = $svc->createDraft(['return_number' => $n, 'shipment_id' => $shipment->id, 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id, 'return_date' => now()->toDateString(), 'reason' => 'customer return'], $lines = [$l]);

        return $post ? $svc->post($r) : $r;
    }

    #[Test]
    public function current_v2_shipment_full_return_still_posts(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('4.000000');
        $r = $this->ret('LG-V2', $s, $c, $w, $i, $l, '4.000000');
        $this->assertSame('posted', $r->status);
        $rl = $r->fresh('lines')->lines->first();
        $this->assertSame(UnitConversionResolver::CONTRACT_VERSION, $rl->unit_conversion_version);
        $this->assertValidator($rl);
    }

    #[Test]
    public function v1_full_source_return_posts_with_v2_equivalent_values_and_source_untouched(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('4.000000');
        $this->makeV1($l);
        $before = DB::connection('tenant')->table('shipment_lines')->where('id', $l->id)->first();
        $r = $this->ret('LG-V1F', $s, $c, $w, $i, $l, '4.000000');
        $this->assertSame('posted', $r->status);
        $rl = $r->fresh('lines')->lines->first();
        // Not upgraded: identical to the shipment's frozen v1 snapshot.
        $this->assertSame('solastock-unit-conversion.v1', $rl->unit_conversion_version);
        $this->assertSame((string) $l->fresh()->unit_conversion_hash, (string) $rl->unit_conversion_hash);
        $this->assertSame(4, (int) $rl->unit_conversion_precision);
        $this->assertValidator($rl);

        $this->assertEquals($before, DB::connection('tenant')->table('shipment_lines')->where('id', $l->id)->first());
    }

    #[Test]
    public function v1_partial_and_repeated_returns_up_to_shipped_quantity(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('5.000000');
        $this->makeV1($l);
        $first = $this->ret('LG-P1', $s, $c, $w, $i, $l, '2.000000');
        $this->assertSame('posted', $first->status);
        $this->assertValidator($first->fresh('lines')->lines->first());
        $this->assertPayloadSnapshotEqualsShipment($first->fresh('lines')->lines->first(), $l);
        $this->assertSame('posted', $this->ret('LG-P2', $s, $c, $w, $i, $l, '3.000000')->status);
        $this->expectException(ValidationException::class);
        $this->ret('LG-P3', $s, $c, $w, $i, $l, '0.000001', false);
    }

    #[Test]
    public function v1_box_of_twelve_and_decimal_quantity_full_return(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('1.500000', true);
        $this->assertEquals(18, (float) $l->quantity);
        $this->makeV1($l);
        $r = $this->ret('LG-BX', $s, $c, $w, $i, $l, '18.000000', true, (string) $l->entered_unit_id, '1.500000');
        $this->assertSame('posted', $r->status);
        $this->assertValidator($r->fresh('lines')->lines->first());
    }

    #[Test]
    public function tampered_v1_hash_is_still_rejected(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('4.000000');
        $this->makeV1($l, str_repeat('a', 64));
        $r = $this->ret('LG-T1', $s, $c, $w, $i, $l, '4.000000', false);
        $rl = $r->fresh('lines')->lines->first();
        $this->assertSame('solastock-unit-conversion.v1', $rl->unit_conversion_version); // not upgraded
        $this->expectException(ValidationException::class);
        $this->assertValidator($rl);
    }

    #[Test]
    public function wrong_v1_arithmetic_is_still_rejected(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('4.000000');
        $this->makeV1($l, null, '3.000000');
        $r = $this->ret('LG-T2', $s, $c, $w, $i, $l, '4.000000', false);
        $rl = $r->fresh('lines')->lines->first();
        $this->assertSame('solastock-unit-conversion.v1', $rl->unit_conversion_version);
        $this->expectException(ValidationException::class);
        $this->assertValidator($rl);
    }

    #[Test]
    public function v1_is_not_accepted_outside_customer_returns_and_not_cross_accepted(): void
    {
        [$s, $c, $w, $i, $l] = $this->shipped('4.000000');
        $this->makeV1($l);
        $r = $this->ret('LG-OUT', $s, $c, $w, $i, $l, '4.000000', false);
        $rl = $r->fresh('lines')->lines->first();
        $m = new \ReflectionMethod(\App\Services\Integration\WorkflowValidationService::class, 'assertConversionSnapshot');
        $m->setAccessible(true);
        $svc = app(\App\Services\Integration\WorkflowValidationService::class);
        foreach (['shipment.posted', 'grn.posted'] as $event) {
            try {
                $m->invoke($svc, (object) $rl->getAttributes(), (int) $rl->organization_id, $event);
                $this->fail("v1 must stay rejected for $event");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        // v1 label with a v2-computed hash is not cross-accepted even for returns.
        DB::connection('tenant')->table('sales_return_lines')->where('id', $rl->id)
            ->update(['unit_conversion_hash' => hash('sha256', 'x')]);
        $this->expectException(ValidationException::class);
        $this->assertValidator($rl->fresh());
    }

    #[Test]
    public function so_to_shipment_and_po_to_grn_snapshot_normalisation_is_unchanged_for_v1_sources(): void
    {
        [, , , $i, $l] = $this->shipped('4.000000');
        $this->makeV1($l);
        $this->expectException(\RuntimeException::class); // same as base: v1 source is not re-frozen
        app(UnitConversionResolver::class)->normalizeLineFromSnapshot(
            ['item_id' => $i->id, 'entered_qty' => '1'], 'quantity', $l->fresh());
    }

    #[Test]
    public function report_classification_and_org_guard(): void
    {
        [, , , , $l] = $this->shipped('4.000000');
        $cmd = new \ReflectionMethod(\App\Console\Commands\ReportLegacyUnitConversion::class, 'classify');
        $cmd->setAccessible(true);
        $c = new \App\Console\Commands\ReportLegacyUnitConversion();
        $resolver = app(UnitConversionResolver::class);
        $this->assertSame('v2_ok', $cmd->invoke($c, $l->fresh(), $resolver));
        $this->makeV1($l);
        $this->assertSame('v1_verified', $cmd->invoke($c, $l->fresh(), $resolver));
        $this->makeV1($l, str_repeat('b', 64));
        $this->assertSame('v1_invalid', $cmd->invoke($c, $l->fresh(), $resolver));
        $this->assertSame(1, Artisan::call('returns:report-legacy-unit-conversion'));
    }
}
