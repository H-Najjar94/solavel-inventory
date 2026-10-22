<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemSupplierController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemSupplier;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\SupplierPriceList;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Models\Tenant\WarehouseReorderRule;
use App\Services\Access\InventoryPermissionService;
use App\Services\Entitlements\EntitlementAccessDecision;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceInventoryCapability;
use App\Services\Purchasing\SupplierTerms\BlockedSupplierGuard;
use App\Services\Purchasing\SupplierTerms\ItemSupplierService;
use App\Services\Purchasing\SupplierTerms\ReorderSuggestions;
use App\Services\Purchasing\SupplierTerms\SupplierPriceResolver;
use App\Services\Purchasing\SupplierTerms\SupplierPurchaseHistory;
use App\Services\Purchasing\SupplierTerms\SupplierTermsCsv;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Stock\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        // The row list is capped; the stats still cover every matching line.
        $capped = app(SupplierPurchaseHistory::class)->history(['item_id' => $item->id], 1);
        $this->assertCount(1, $capped['receipts']);
        $this->assertSame($history['summary'], $capped['summary']);
        $this->assertSame(2, $capped['summary'][0]['documents']);
        $this->assertSame([], $history['bills']);
        $this->assertSame('standalone', $history['finance']['state']);
        $summary = $history['summary'][0];
        $this->assertSame('3.000000', $summary['last_unit_cost']);
        $this->assertSame('2.000000', $summary['lowest_base_unit_cost']);
        $this->assertSame('3.000000', $summary['highest_base_unit_cost']);
        $this->assertSame('2.750000', $summary['weighted_average_base_unit_cost']);
        $this->assertSame('40.000000', $summary['total_quantity']);
        $this->assertSame('110.000000', $summary['total_base_value']);
        $this->assertSame('receipts', $summary['basis']);
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

    #[Test]
    public function the_summary_prefers_posted_bills_per_supplier_and_keeps_receipt_only_suppliers(): void
    {
        $this->boot();
        $columns = [
            'bills' => ['id', 'organization_id', 'supplier_id', 'supplier_name', 'status', 'journal_entry_id', 'number', 'reference', 'date', 'currency_code', 'exchange_rate'],
            'bill_lines' => ['id', 'bill_id', 'inventory_item_id', 'item_name', 'qty', 'unit_price', 'line_subtotal', 'line_tax', 'line_discount_amount', 'document_discount_allocated', 'line_discount'],
            'journal_entries' => ['id', 'organization_id', 'number', 'entry_date', 'status', 'posted_at', 'deleted_at', 'reverses_entry_id'],
        ];
        foreach ($columns as $table => $required) {
            if (! Schema::connection('tenant')->hasTable($table) || ! Schema::connection('tenant')->hasColumns($table, $required)) {
                $this->markTestSkipped("The test tenant has no SolaCount {$table} schema.");
            }
        }
        $wh = F::warehouse();
        $item = $this->itemWithUnit();
        $billed = $this->supplier('HB-A');
        $receiptOnly = $this->supplier('HB-B');
        $this->receipt($wh->id, $billed->id, $item->id, 'posted', null, '10', '2', '2026-01-10');
        $this->receipt($wh->id, $receiptOnly->id, $item->id, 'posted', null, '5', '4', '2026-01-12');
        $mapping = $this->connectFinance('JOD', true);
        $this->financeMaster($mapping, 'item', $item->id, 7401);
        $this->financeMaster($mapping, 'supplier', $billed->id, 7301);
        $db = DB::connection('tenant');
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $db->table('journal_entries')->insert(['id' => 99001, 'organization_id' => 14, 'number' => 'ST-JE-1', 'entry_date' => '2026-02-01', 'status' => 'posted', 'posted_at' => now()]);
            // 4 units for 10.00 net: 2.5 each in JOD at rate 1.
            $db->table('bills')->insert(['id' => 99001, 'organization_id' => 14, 'supplier_id' => 7301, 'supplier_name' => 'Supplier HB-A', 'status' => 'paid',
                'journal_entry_id' => 99001, 'number' => 'ST-BILL-1', 'reference' => 'INV-9', 'date' => '2026-02-01', 'currency_code' => 'JOD', 'exchange_rate' => '1']);
            $db->table('bill_lines')->insert(['id' => 99001, 'bill_id' => 99001, 'inventory_item_id' => 7401, 'item_name' => 'Billed item', 'qty' => '4',
                'unit_price' => '2.5', 'line_subtotal' => '10', 'line_tax' => '0', 'line_discount_amount' => '0', 'document_discount_allocated' => '0', 'line_discount' => '0']);
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $history = app(SupplierPurchaseHistory::class)->history(['item_id' => $item->id], 1);

        $this->assertCount(1, $history['bills']);
        $this->assertCount(1, $history['receipts']);
        $summary = collect($history['summary'])->keyBy('supplier_id');
        $this->assertCount(2, $summary);
        $this->assertSame('bills', $summary[$billed->id]['basis']);
        $this->assertSame('2.500000', $summary[$billed->id]['last_unit_cost']);
        $this->assertSame('2.500000', $summary[$billed->id]['last_base_unit_cost']);
        $this->assertSame('4.000000', $summary[$billed->id]['total_quantity']);
        $this->assertSame('receipts', $summary[$receiptOnly->id]['basis']);
        $this->assertSame('4.000000', $summary[$receiptOnly->id]['last_unit_cost']);
        $this->assertSame('5.000000', $summary[$receiptOnly->id]['total_quantity']);
    }

    #[Test]
    public function the_vendor_comparison_rejects_a_bad_quantity_or_date_instead_of_failing(): void
    {
        $this->boot();
        $item = $this->itemWithUnit();
        $controller = app(ItemSupplierController::class);

        $this->assertValidation(fn () => $controller->comparison(Request::create('/x', 'GET', ['quantity' => 'abc']), $item), 'quantity');
        $this->assertValidation(fn () => $controller->comparison(Request::create('/x', 'GET', ['quantity' => '0']), $item), 'quantity');
        $this->assertValidation(fn () => $controller->comparison(Request::create('/x', 'GET', ['date' => 'not-a-date']), $item), 'date');
        $this->assertValidation(fn () => $controller->suggest(Request::create('/x', 'GET', ['item_id' => $item->id, 'date' => 'not-a-date'])), 'date');
        $ok = $controller->comparison(Request::create('/x', 'GET', ['quantity' => '2.5', 'date' => '2026-03-01']), $item)->getData(true)['data'];
        $this->assertSame('2026-03-01', $ok['date']);
    }

    #[Test]
    public function a_malformed_csv_number_is_a_row_error_not_an_exception(): void
    {
        $this->boot();
        F::averageItem(['sku' => 'CSV-N', 'base_unit_id' => $this->unit('EA')->id]);
        $this->supplier('CSV-N-S');
        $csv = new SupplierTermsCsv($this->service());

        $result = $csv->import("item_sku,supplier_code,min_qty,unit_price\nCSV-N,CSV-N-S,1,abc\nCSV-N,CSV-N-S,1x,2\nCSV-N,CSV-N-S,5,1e3\n", true, null);

        $this->assertFalse($result['applied']);
        $this->assertSame(['error', 'error', 'error'], array_column($result['rows'], 'status'));
        $this->assertSame(__('inventory.supplier_terms.import_number_invalid'), $result['rows'][0]['message']);
        $this->assertFalse(ItemSupplier::query()->whereHas('item', fn ($q) => $q->where('sku', 'CSV-N'))->exists());
    }

    #[Test]
    public function reorder_sums_bins_per_warehouse_and_includes_rule_only_stockless_items(): void
    {
        $this->boot();
        $wh = F::warehouse();
        $bins = [$this->bin($wh->id), $this->bin($wh->id)];
        $covered = F::averageItem(['base_unit_id' => $this->unit('EA')->id, 'reorder_point' => '60']);
        $short = F::averageItem(['base_unit_id' => $this->unit('EA')->id, 'reorder_point' => '60']);
        $stockless = F::averageItem(['base_unit_id' => $this->unit('EA')->id]);
        $this->balance($covered, $wh->id, '50', $bins[0]);
        $this->balance($covered, $wh->id, '50', $bins[1]);
        $this->balance($short, $wh->id, '30', $bins[0]);
        $this->balance($short, $wh->id, '20', $bins[1]);
        WarehouseReorderRule::query()->create(['organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'item_id' => $stockless->id,
            'warehouse_id' => $wh->id, 'reorder_point' => '10', 'reorder_qty' => '25', 'is_active' => true]);

        $rows = collect(app(ReorderSuggestions::class)->suggestions($wh->id, null));

        // 50 + 50 = 100 available is above the reorder point of 60, whatever each bin holds.
        $this->assertCount(0, $rows->where('item_id', $covered->id));
        $shortRows = $rows->where('item_id', $short->id)->values();
        $this->assertCount(1, $shortRows);
        $this->assertSame('50.000000', $shortRows[0]['available_qty']);
        $this->assertSame('10.000000', $shortRows[0]['needed_qty']);
        // No balance row at all: the warehouse rule still applies to zero stock.
        $stocklessRows = $rows->where('item_id', $stockless->id)->values();
        $this->assertCount(1, $stocklessRows);
        $this->assertSame('0.000000', $stocklessRows[0]['available_qty']);
        $this->assertSame('10.000000', $stocklessRows[0]['reorder_point']);
        $this->assertSame('25.000000', $stocklessRows[0]['needed_qty']);
        $this->assertSame((int) $wh->id, $stocklessRows[0]['warehouse_id']);
    }

    #[Test]
    public function a_connected_org_normalizes_against_the_finance_base_currency(): void
    {
        $this->boot(); // the inventory base currency is mocked as JOD
        $this->connectFinance('USD', false);
        $item = $this->itemWithUnit();
        $usd = $this->supplier('FX-U');
        $jod = $this->supplier('FX-J');
        $this->service()->save($item, ['supplier_id' => $usd->id, 'currency_code' => 'USD', 'tiers' => [['minimum_qty' => '1', 'unit_cost' => '1']]], null, null);
        $this->service()->save($item, ['supplier_id' => $jod->id, 'currency_code' => 'JOD', 'tiers' => [['minimum_qty' => '1', 'unit_cost' => '0.7']]], null, null);
        $rated = Schema::connection('tenant')->hasTable('exchange_rates')
            && Schema::connection('tenant')->hasColumns('exchange_rates', ['organization_id', 'base_currency_code', 'quote_currency_code', 'rate', 'rate_date', 'source']);
        if ($rated) {
            DB::connection('tenant')->table('exchange_rates')->insert(['organization_id' => 14, 'base_currency_code' => 'USD', 'quote_currency_code' => 'JOD',
                'rate' => '0.709', 'rate_date' => '2026-01-01', 'source' => 'manual']);
        }

        $r = app(SupplierPriceResolver::class)->suggest($item->fresh(), null, '1', null, null, '2026-03-01');

        $this->assertSame('USD', $r['base_currency_code']);
        $candidates = collect($r['candidates'])->keyBy('supplier_id');
        // USD is the Finance base: identity rate, no lookup.
        $this->assertSame('1', $candidates[$usd->id]['exchange_rate']);
        $this->assertSame('1.000000', $candidates[$usd->id]['net_unit_price_base_unit_in_base_currency']);
        // JOD is NOT the Finance base, even though it is the inventory base: it needs a Finance rate.
        if ($rated) {
            $this->assertSame(0, Decimal::cmp($candidates[$jod->id]['exchange_rate'], '0.709'));
            $this->assertSame('0.987306', $candidates[$jod->id]['net_unit_price_base_unit_in_base_currency']);
        } else {
            $this->assertNull($candidates[$jod->id]['exchange_rate']);
            $this->assertNull($candidates[$jod->id]['net_unit_price_base_unit_in_base_currency']);
        }
    }

    /** A verified SolaCount mapping (finance org 14); $entitled also makes integrated reads readable. */
    private function connectFinance(string $baseCurrency, bool $entitled): IntegrationOrganizationMapping
    {
        $mapping = IntegrationOrganizationMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => $baseCurrency, 'verified_at' => now()]);
        IntegrationSetting::query()->create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14,
            'meta' => ['client_id' => 7, 'central_organization_id' => \Tests\Support\TenantTestManager::ORG_A]]);
        $capability = \Mockery::mock(FinanceInventoryCapability::class);
        $capability->shouldReceive('allows')->andReturn($entitled);
        $cache = \Mockery::mock(EntitlementsCache::class);
        $cache->shouldReceive('getProjectSnapshot')->andReturn(['accessible' => true, 'commercially_entitled' => true,
            'subscription_status' => 'active', 'entitlement_source' => 'separate_subscription', 'access_until' => now()->addMonth()->toIso8601String()]);
        $this->app->instance(ApprovedFinanceIntegrationEntitlement::class, new ApprovedFinanceIntegrationEntitlement($cache, $capability, new EntitlementAccessDecision));

        return $mapping;
    }

    private function financeMaster(IntegrationOrganizationMapping $mapping, string $type, int $stockId, int $financeId): void
    {
        IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'central_client_id' => 7, 'central_organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'finance_organization_id' => 14,
            'solastock_organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'entity_type' => $type,
            'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified']);
    }

    private function bin(int $warehouseId): int
    {
        $db = DB::connection('tenant');
        $org = \Tests\Support\TenantTestManager::ORG_A;
        $zone = $db->table('warehouse_zones')->insertGetId(['organization_id' => $org, 'warehouse_id' => $warehouseId, 'code' => 'Z'.uniqid(), 'name' => 'Zone', 'created_at' => now(), 'updated_at' => now()]);

        return $db->table('warehouse_bins')->insertGetId(['organization_id' => $org, 'warehouse_id' => $warehouseId, 'zone_id' => $zone, 'code' => 'B'.uniqid(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function balance(Item $item, int $warehouseId, string $onHand, ?int $binId): void
    {
        StockBalance::query()->create(['organization_id' => \Tests\Support\TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
            'bin_id' => $binId, 'on_hand_qty' => $onHand, 'reserved_qty' => '0', 'total_value' => '0']);
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
