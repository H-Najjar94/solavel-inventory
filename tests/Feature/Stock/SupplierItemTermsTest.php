<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPriceList;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Access\InventoryPermissionService;
use App\Services\Purchasing\SupplierTerms\BlockedSupplierGuard;
use App\Services\Purchasing\SupplierTerms\ItemSupplierService;
use App\Services\Purchasing\SupplierTerms\ReorderSuggestions;
use App\Services\Purchasing\SupplierTerms\SupplierPriceResolver;
use App\Services\Purchasing\SupplierTerms\SupplierPurchaseHistory;
use App\Services\Purchasing\SupplierTerms\SupplierTermsCsv;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class SupplierItemTermsTest extends TestCase
{
    use TenantAware;

    private function boot(): void
    {
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(
            ['organization_id' => \Tests\Support\TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]
        );
        $reports = \Mockery::mock(InventoryReportService::class)->makePartial();
        $reports->shouldReceive('baseCurrency')->andReturn('JOD');
        $this->app->instance(InventoryReportService::class, $reports);
    }

    private function service(): ItemSupplierService
    {
        return app(ItemSupplierService::class);
    }

    private function supplier(string $code, array $attrs = []): Supplier
    {
        return Supplier::query()->create(['code' => $code, 'name' => 'Supplier '.$code, 'is_active' => true] + $attrs);
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->firstOrCreate(['code' => $code], ['name' => $code, 'symbol' => strtolower($code), 'is_active' => true]);
    }

    private function itemWithUnit(): Item
    {
        return F::averageItem(['base_unit_id' => $this->unit('EA')->id]);
    }

    #[Test]
    public function a_relationship_with_tiers_is_saved_and_presented(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('S-A');

        $saved = $this->service()->save($item, [
            'supplier_id' => $supplier->id, 'is_preferred' => true, 'supplier_sku' => 'ACME-1',
            'supplier_item_name' => 'Acme bolt', 'currency_code' => 'usd', 'minimum_order_qty' => '10',
            'order_multiple' => '5', 'lead_time_days' => 7, 'discount_percent' => '2.5', 'price_scale' => 4,
            'tiers' => [
                ['minimum_qty' => '1', 'unit_cost' => '1.2345'],
                ['minimum_qty' => '100', 'unit_cost' => '1.1000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31'],
            ],
        ], null, null);

        $this->assertSame('USD', $saved->currency_code);
        $this->assertSame((int) $supplier->id, (int) $item->fresh()->preferred_supplier_id);
        $this->assertSame(2, SupplierPriceList::query()->where('item_supplier_id', $saved->id)->count());
        $tier = SupplierPriceList::query()->where('item_supplier_id', $saved->id)->orderBy('minimum_qty')->first();
        $this->assertSame('ACME-1', $tier->supplier_sku);
        $this->assertSame('USD', $tier->currency_code);
        $presented = $this->service()->present($saved, $item->fresh());
        $this->assertTrue($presented['is_preferred']);
        $this->assertSame('1.00000000', $presented['purchase_unit_factor']);
        $this->assertCount(2, $presented['tiers']);
    }

    #[Test]
    public function duplicates_overlaps_excess_decimals_and_bad_dates_are_rejected(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('S-B');
        $this->service()->save($item, ['supplier_id' => $supplier->id, 'tiers' => [['minimum_qty' => '1', 'unit_cost' => '2']]], null, null);

        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $supplier->id], null, null), 'supplier_id');

        $other = $this->supplier('S-C');
        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $other->id, 'tiers' => [
            ['minimum_qty' => '10', 'unit_cost' => '2', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30'],
            ['minimum_qty' => '10.000', 'unit_cost' => '1.9', 'effective_from' => '2026-06-30'],
        ]], null, null), 'tiers.1.minimum_qty');

        // Adjacent validity windows with the same minimum quantity are fine.
        $ok = $this->service()->save($item, ['supplier_id' => $other->id, 'tiers' => [
            ['minimum_qty' => '10', 'unit_cost' => '2', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-29'],
            ['minimum_qty' => '10', 'unit_cost' => '1.9', 'effective_from' => '2026-06-30'],
            ['minimum_qty' => '10', 'unit_cost' => '1.5', 'effective_from' => '2026-01-01', 'is_active' => false],
        ]], null, null);
        $this->assertSame(3, $ok->prices()->count());

        $third = $this->supplier('S-D');
        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $third->id, 'price_scale' => 3,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1.2345']]], null, null), 'tiers.0.unit_cost');
        // Trailing zeros beyond the scale are not extra precision.
        $saved = $this->service()->save($item, ['supplier_id' => $third->id, 'price_scale' => 3,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1.234000']]], null, null);
        $this->assertSame('1.234000', (string) $saved->prices->first()->unit_cost);

        $fourth = $this->supplier('S-E');
        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $fourth->id,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1.1234567']]], null, null), 'tiers.0.unit_cost');
        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $fourth->id,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1', 'effective_from' => '2026-05-01', 'effective_to' => '2026-04-30']]], null, null), 'tiers.0.effective_to');
        $this->assertFalse(ItemSupplier::query()->where('supplier_id', $fourth->id)->exists(), 'A rejected save must not leave a relationship behind.');
    }

    #[Test]
    public function only_an_approved_supplier_can_be_preferred_and_blocking_clears_it(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('S-F');
        $this->assertValidation(fn () => $this->service()->save($item, ['supplier_id' => $supplier->id, 'status' => 'blocked', 'is_preferred' => true], null, null), 'is_preferred');

        $relationship = $this->service()->save($item, ['supplier_id' => $supplier->id, 'is_preferred' => true], null, null);
        $this->assertSame((int) $supplier->id, (int) $item->fresh()->preferred_supplier_id);
        $this->service()->save($item->fresh(), ['status' => 'blocked'], $relationship, null);
        $this->assertNull($item->fresh()->preferred_supplier_id);

        $this->service()->save($item->fresh(), ['status' => 'approved', 'is_preferred' => true], $relationship->fresh(), null);
        $this->service()->delete($relationship->fresh());
        $this->assertNull($item->fresh()->preferred_supplier_id);
        $this->assertSame(0, SupplierPriceList::query()->where('item_id', $item->id)->count());
    }

    #[Test]
    public function the_price_rule_picks_the_tier_by_purchase_quantity_date_currency_and_discount(): void
    {
        $this->boot();
        $each = $this->unit('EA');
        $box = $this->unit('BOX');
        $item = F::averageItem(['base_unit_id' => $each->id]);
        UnitConversion::query()->create(['item_id' => $item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $each->id, 'factor' => '12']);
        $a = $this->supplier('P-A');
        $b = $this->supplier('P-B');
        $c = $this->supplier('P-C');
        $blocked = $this->supplier('P-X');
        // A sells boxes of 12 in JOD with a 10% discount; tier from 5 boxes is valid in 2026 only.
        $this->service()->save($item, ['supplier_id' => $a->id, 'currency_code' => 'JOD', 'purchase_unit_id' => $box->id,
            'discount_percent' => '10', 'minimum_order_qty' => '2', 'order_multiple' => '2', 'lead_time_days' => 5, 'tiers' => [
                ['minimum_qty' => '1', 'unit_cost' => '120'],
                ['minimum_qty' => '5', 'unit_cost' => '108', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31'],
            ]], null, null);
        $this->service()->save($item, ['supplier_id' => $b->id, 'currency_code' => 'JOD', 'is_preferred' => true,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '9.5']]], null, null);
        $this->service()->save($item, ['supplier_id' => $c->id, 'currency_code' => 'USD',
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1']]], null, null);
        $this->service()->save($item, ['supplier_id' => $blocked->id, 'status' => 'blocked', 'currency_code' => 'JOD',
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '0.01']]], null, null);
        $resolver = app(SupplierPriceResolver::class);

        // 60 each = 5 boxes: the 2026 tier applies; 108 x 0.9 = 97.2 per box = 8.1 per each.
        $r = $resolver->suggest($item->fresh(), $a->id, '60', null, 'JOD', '2026-03-01');
        $this->assertSame('5.000000', $r['suggestion']['tier_minimum_qty']);
        $this->assertSame('97.200000', $r['suggestion']['net_unit_price']);
        $this->assertSame('8.100000', $r['suggestion']['net_unit_price_base_unit']);
        $this->assertSame('2026-03-06', $r['suggestion']['expected_date']);
        // 5 boxes is not a multiple of 2: suggest 6 boxes (advice only, never applied by itself).
        $this->assertTrue($r['suggestion']['quantity_adjusted']);
        $this->assertSame('6.000000', $r['suggestion']['suggested_purchase_quantity']);
        // Entered in boxes: the price is per box.
        $r = $resolver->suggest($item->fresh(), $a->id, '5', $box->id, 'JOD', '2026-03-01');
        $this->assertSame('97.200000', $r['suggestion']['net_unit_price_entered_unit']);
        // Outside the validity window the base tier applies.
        $r = $resolver->suggest($item->fresh(), $a->id, '60', null, 'JOD', '2027-01-01');
        $this->assertSame('1.000000', $r['suggestion']['tier_minimum_qty']);
        // 30 each = 2.5 boxes: rounded up to the multiple of 2 boxes = 4 boxes = 48 each.
        $r = $resolver->suggest($item->fresh(), $a->id, '30', null, 'JOD', '2026-03-01');
        $this->assertTrue($r['suggestion']['quantity_adjusted']);
        $this->assertSame('4.000000', $r['suggestion']['suggested_purchase_quantity']);
        $this->assertSame('48.000000', $r['suggestion']['suggested_entered_quantity']);

        // Without a supplier the preferred supplier is suggested; best is the cheapest same-base candidate.
        $r = $resolver->suggest($item->fresh(), null, '60', null, 'JOD', '2026-03-01');
        $this->assertSame((int) $b->id, $r['suggestion']['supplier_id']);
        $this->assertSame((int) $a->id, $r['best']['supplier_id']);
        // A USD price is listed but never ranked without a Finance rate, and never matches a JOD document.
        $usd = collect($r['candidates'])->firstWhere('supplier_id', (int) $c->id);
        $this->assertNull($usd['net_unit_price_base_unit_in_base_currency']);
        $this->assertNull($resolver->suggest($item->fresh(), $c->id, '1', null, 'JOD', null)['suggestion']);
        // A blocked supplier is reported, never suggested.
        $r = $resolver->suggest($item->fresh(), $blocked->id, '1', null, 'JOD', null);
        $this->assertNull($r['suggestion']);
        $this->assertSame((int) $blocked->id, $r['blocked']['supplier_id']);
        $this->assertNotSame((int) $blocked->id, $r['best']['supplier_id']);
    }

    #[Test]
    public function an_order_below_the_minimum_is_priced_at_the_quantity_the_terms_require(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('MOQ-A');
        $this->service()->save($item, ['supplier_id' => $supplier->id, 'currency_code' => 'JOD', 'minimum_order_qty' => '10',
            'tiers' => [['minimum_qty' => '10', 'unit_cost' => '5']]], null, null);

        $r = app(SupplierPriceResolver::class)->suggest($item->fresh(), $supplier->id, '4', null, 'JOD', null);

        $this->assertNotNull($r['suggestion']);
        $this->assertTrue($r['suggestion']['priced_at_suggested_quantity']);
        $this->assertTrue($r['suggestion']['quantity_adjusted']);
        $this->assertSame('10.000000', $r['suggestion']['suggested_entered_quantity']);
        $this->assertSame('5.000000', $r['suggestion']['net_unit_price']);
    }

    #[Test]
    public function legacy_single_price_endpoints_write_tiers_of_one_relationship(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('L-A');
        $controller = app(ItemController::class);
        $first = $controller->storeSupplierPrice(Request::create('/x', 'POST', ['supplier_id' => $supplier->id, 'unit_cost' => '3', 'supplier_sku' => 'L1']), $item)->getData(true)['data'];
        $second = $controller->storeSupplierPrice(Request::create('/x', 'POST', ['supplier_id' => $supplier->id, 'unit_cost' => '2.5', 'minimum_qty' => '10']), $item)->getData(true)['data'];
        $this->assertSame($first['item_supplier_id'], $second['item_supplier_id']);
        $this->assertSame('JOD', $first['currency_code']);
        $this->assertSame(1, ItemSupplier::query()->where('item_id', $item->id)->count());

        $mismatch = $controller->storeSupplierPrice(Request::create('/x', 'POST', ['supplier_id' => $supplier->id, 'unit_cost' => '1', 'minimum_qty' => '50', 'currency_code' => 'EUR']), $item);
        $this->assertSame(422, $mismatch->getStatusCode());

        // The old update bug: a null minimum quantity no longer reaches the non-null column.
        $updated = $controller->updateSupplierPrice(Request::create('/x', 'PUT', ['supplier_id' => $supplier->id, 'unit_cost' => '2.4', 'minimum_qty' => null]), $item, SupplierPriceList::query()->findOrFail($second['id']));
        $this->assertSame(200, $updated->getStatusCode());
        $this->assertSame('10.000000', (string) SupplierPriceList::query()->findOrFail($second['id'])->minimum_qty);
        $this->assertSame(422, $controller->updateSupplierPrice(Request::create('/x', 'PUT', ['supplier_id' => $this->supplier('L-B')->id, 'unit_cost' => '1']), $item, SupplierPriceList::query()->findOrFail($second['id']))->getStatusCode());
    }

    #[Test]
    public function ordering_from_a_blocked_supplier_needs_the_po_approval_permission(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $okItem = $this->itemWithUnit();
        $supplier = $this->supplier('B-A');
        $this->service()->save($item, ['supplier_id' => $supplier->id, 'status' => 'blocked'], null, null);
        $user = new \App\Models\User();
        $user->id = 991;
        request()->setUserResolver(fn () => $user);
        $lines = [['item_id' => $okItem->id], ['item_id' => $item->id]];

        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->with($user, BlockedSupplierGuard::OVERRIDE_PERMISSION)->andReturn(false));
        try {
            app(BlockedSupplierGuard::class)->assertAllowed($supplier->id, $lines);
            $this->fail('A blocked supplier-item must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['lines.1.item_id'], array_keys($e->errors()));
        }
        app(BlockedSupplierGuard::class)->assertAllowed($supplier->id, [['item_id' => $okItem->id]]);
        app(BlockedSupplierGuard::class)->assertAllowed(null, $lines);

        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->with($user, BlockedSupplierGuard::OVERRIDE_PERMISSION)->andReturn(true));
        app(BlockedSupplierGuard::class)->assertAllowed($supplier->id, $lines);
        $this->assertTrue(true);
    }

    #[Test]
    public function purchase_history_uses_posted_unreversed_receipts_only_and_never_writes(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = $this->itemWithUnit();
        $supplier = $this->supplier('H-A');
        $receipt = fn (string $status, ?string $reversedAt, string $qty, string $cost, string $date) => $this->receipt($wh->id, $supplier->id, $item->id, $status, $reversedAt, $qty, $cost, $date);
        $receipt('posted', null, '10', '2', '2026-01-10');
        $receipt('posted', null, '30', '3', '2026-02-10');
        $receipt('draft', null, '99', '9', '2026-03-01');
        $receipt('posted', '2026-03-05 10:00:00', '50', '1', '2026-03-02');
        $before = [DB::connection('tenant')->table('stock_ledger')->count(), DB::connection('tenant')->table('stock_balances')->sum('total_value')];

        $history = app(SupplierPurchaseHistory::class)->history(['item_id' => $item->id]);

        $this->assertCount(2, $history['receipts']);
        $this->assertSame([], $history['bills']);
        $this->assertSame('standalone', $history['finance']['state']);
        $summary = $history['summary'][0];
        $this->assertSame('3.000000', $summary['last_unit_cost']);
        $this->assertSame('2.000000', $summary['lowest_base_unit_cost']);
        $this->assertSame('3.000000', $summary['highest_base_unit_cost']);
        $this->assertSame('2.750000', $summary['weighted_average_base_unit_cost']);
        $this->assertSame('40.000000', $summary['total_quantity']);
        $this->assertSame($before, [DB::connection('tenant')->table('stock_ledger')->count(), DB::connection('tenant')->table('stock_balances')->sum('total_value')]);
    }

    #[Test]
    public function csv_import_previews_without_writing_applies_atomically_and_round_trips(): void
    {
        $this->boot();
        $item = F::averageItem(['sku' => 'CSV-1', 'base_unit_id' => $this->unit('EA')->id]);
        $supplier = $this->supplier('CSV-S');
        $csv = new SupplierTermsCsv($this->service());
        $content = "item_sku,supplier_code,status,preferred,supplier_sku,currency_code,lead_time_days,min_qty,unit_price,valid_from,valid_to\n"
            ."CSV-1,CSV-S,approved,yes,V-1,JOD,4,1,5.50,,\n"
            ."CSV-1,CSV-S,approved,yes,V-1,JOD,4,10,5.00,2026-01-01,2026-12-31\n";

        $preview = $csv->import($content, false, null);
        $this->assertFalse($preview['applied']);
        $this->assertSame(2, $preview['summary']['created']);
        $this->assertFalse(ItemSupplier::query()->where('item_id', $item->id)->exists());

        $applied = $csv->import($content, true, null);
        $this->assertTrue($applied['applied']);
        $relationship = ItemSupplier::query()->where('item_id', $item->id)->sole();
        $this->assertSame(2, $relationship->prices()->count());
        $this->assertSame((int) $supplier->id, (int) $item->fresh()->preferred_supplier_id);

        // Exported then re-imported unchanged: nothing changes.
        $again = $csv->import($csv->export($item->id, null), true, null);
        $this->assertSame(0, $again['summary']['errors']);
        $this->assertSame(2, $again['summary']['unchanged']);

        // Duplicates in one file and unknown keys fail the whole file; existing tiers are kept.
        $bad = $csv->import("item_sku,supplier_code,min_qty,unit_price\nCSV-1,CSV-S,20,4\nCSV-1,CSV-S,20,4.1\nNOPE,CSV-S,1,1\n", true, null);
        $this->assertFalse($bad['applied']);
        $this->assertSame(['updated', 'error', 'error'], array_column($bad['rows'], 'status'));
        $this->assertSame(2, $relationship->prices()->count());
    }

    #[Test]
    public function reorder_suggestions_net_open_orders_and_round_to_supplier_terms(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $item = F::averageItem(['base_unit_id' => $this->unit('EA')->id, 'reorder_point' => '20', 'reorder_qty' => '15']);
        app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $wh->id, '8', 'Test', 1, unitCost: '2')], 'supplier-terms-test:'.$item->id);
        $supplier = $this->supplier('R-A');
        $this->service()->save($item, ['supplier_id' => $supplier->id, 'is_preferred' => true, 'currency_code' => 'JOD',
            'minimum_order_qty' => '20', 'order_multiple' => '10', 'lead_time_days' => 3,
            'tiers' => [['minimum_qty' => '1', 'unit_cost' => '2.5']]], null, null);

        $rows = collect(app(ReorderSuggestions::class)->suggestions(null, null))->where('item_id', $item->id)->values();

        $this->assertCount(1, $rows);
        $this->assertSame('15.000000', $rows[0]['needed_qty']);
        $this->assertSame('20.000000', $rows[0]['suggested_qty']); // minimum 20, multiple of 10
        $this->assertSame((int) $supplier->id, $rows[0]['supplier']['id']);
        $this->assertTrue($rows[0]['supplier']['is_preferred']);
        $this->assertSame('50.00', $rows[0]['supplier']['estimated_total']);
    }

    private function receipt(int $warehouseId, int $supplierId, int $itemId, string $status, ?string $reversedAt, string $qty, string $cost, string $date): void
    {
        $org = \Tests\Support\TenantTestManager::ORG_A;
        $connection = DB::connection('tenant');
        $id = $connection->table('goods_receipts')->insertGetId([
            'organization_id' => $org, 'grn_number' => 'GRN-T-'.uniqid(), 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId,
            'receipt_date' => $date, 'status' => $status, 'reversed_at' => $reversedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = $connection->table('goods_receipt_lines')->insertGetId([
            'organization_id' => $org, 'goods_receipt_id' => $id, 'item_id' => $itemId, 'received_qty' => $qty,
            'accepted_qty' => $qty, 'unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status === 'posted') {
            app(StockLedgerService::class)->post([new StockMovement('in', $itemId, $warehouseId, $qty, GoodsReceipt::class, $id, sourceLineId: $lineId, unitCost: $cost)], 'supplier-terms-test:grn:'.$id);
        }
    }

    private function assertValidation(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'Errors: '.json_encode($e->errors()));

            return;
        }
        $this->fail("Expected a validation error on {$field}.");
    }
}
