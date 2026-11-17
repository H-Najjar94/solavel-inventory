<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemAttachmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\ItemAttachment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Unit;
use App\Services\Catalog\ItemBaseUnitConversion;
use App\Services\Catalog\StockUnitProjection;
use App\Services\Documents\OpeningStockService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\ReportFilters;
use App\Services\Stock\IntegrityRepairService;
use App\Services\Stock\StockLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class ItemBaseUnitConversionTest extends TestCase
{
    use TenantAware;

    private function scenario(string $method = 'average', string $quantity = '20'): array
    {
        $this->useTenantA();
        $ea = Unit::create(['code' => 'EA-'.uniqid(), 'name' => 'Each', 'is_active' => true]);
        $box = Unit::create(['code' => 'BX-'.uniqid(), 'name' => 'Box', 'is_active' => true]);
        $item = F::averageItem(['base_unit_id' => $ea->id, 'costing_method' => $method]);
        $s = app(OpeningStockService::class);
        $entry = $s->post($s->createDraft(['entry_number' => 'CONV-'.uniqid(), 'warehouse_id' => F::warehouse()->id, 'opening_date' => now()->subDays(2)->toDateString()], [['item_id' => $item->id, 'quantity' => $quantity, 'unit_cost' => '5']]));

        return [$item, $ea, $box, $entry];
    }

    public function test_existing_positive_stock_conversion_is_value_preserving_idempotent_and_original_units_survive(): void
    {
        [$item,$ea,$box,$entry] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $old = StockLedger::where('item_id', $item->id)->first();
        $before = $old->getAttributes();
        $p = $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString());
        $this->assertTrue($p['supported'], json_encode($p['blockers']));
        $request = (string) Str::uuid();
        $r = $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], $request, 'Verified ten each per box', now()->subDay()->toDateString(), ['reference' => 'Synthetic verified packaging', 'sha256' => str_repeat('a', 64)]);
        $this->assertSame(2, count($r['ledger_ids']));
        $this->assertSame($box->id, (int) $item->fresh()->base_unit_id);
        $b = StockBalance::where('item_id', $item->id)->first();
        $this->assertSame('2.000000', $b->on_hand_qty);
        $this->assertSame('100.000000', $b->total_value);
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertSame($ea->id, $s->movementUnit($old)['quantity_unit_id']);
        $count = StockLedger::where('item_id', $item->id)->count();
        $this->assertSame($r, $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], $request, 'Verified ten each per box', now()->subDay()->toDateString(), ['reference' => 'Synthetic verified packaging', 'sha256' => str_repeat('a', 64)]));
        $this->assertSame($count, StockLedger::where('item_id', $item->id)->count());
        $signed = StockLedger::where('item_id', $item->id)->get()->sum(fn ($l) => ($l->direction === 'in' ? 1 : -1) * (float) $l->quantity);
        $this->assertEquals(2, $signed);
    }

    public function test_full_original_opening_reversal_normalizes_quantity_without_rewriting_document(): void
    {
        [$item,$ea,$box,$entry] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString());
        $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified', now()->subDay()->toDateString(), ['reference' => 'Synthetic verified packaging', 'sha256' => str_repeat('a', 64)]);
        $original = StockLedger::where('item_id', $item->id)->orderBy('id')->first();
        $normalized = $s->normalizeOriginal($original);
        $this->assertSame('2.000000', $normalized->quantity);
        $this->assertSame('50.000000', $normalized->unit_cost);
        $rows = app(StockLedgerService::class)->reverse('opening_stock:'.$entry->id.':post', 'audit-conversion-reverse');
        $this->assertSame('2.000000', $rows[0]->quantity);
        $b = StockBalance::where('item_id', $item->id)->first();
        $this->assertSame('0.000000', $b->on_hand_qty);
        $this->assertSame('0.000000', $b->total_value);
        $this->assertSame('20.0000', $entry->lines()->first()->quantity);
    }

    public function test_stale_revision_and_conflicting_retry_cannot_mutate_stock(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString());
        StockBalance::where('item_id', $item->id)->update(['reserved_qty' => 1]);
        try {
            $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified', now()->subDay()->toDateString(), ['reference' => 'Synthetic verified packaging', 'sha256' => str_repeat('a', 64)]);
            $this->fail('stale preview');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_unit_conversion', $e->errors());
        }$this->assertSame($ea->id, (int) $item->fresh()->base_unit_id);
        $this->assertSame(0, DB::connection('tenant')->table(ItemBaseUnitConversion::TABLE)->where('item_id', $item->id)->count());
    }

    public function test_unsupported_fifo_tracking_and_precision_loss_are_explicit(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '3', now()->subDay()->toDateString());
        $this->assertFalse($p['supported']);
        $this->assertContains('quantity_precision_loss', $p['blockers']);
        $item->update(['costing_method' => 'fifo']);
        $this->assertContains('mixed_costing_history', $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString())['blockers']);
    }

    public function test_unconsumed_fifo_layer_converts_without_new_layer_or_value_and_original_reversal_works(): void
    {
        [$item,$ea,$box,$entry] = $this->scenario('fifo');
        $db = DB::connection('tenant');
        $before = $db->table('cost_layers')->where('item_id', $item->id)->first();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString());
        $this->assertTrue($p['supported'], json_encode($p['blockers']));
        $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified package count', now()->subDay()->toDateString(), ['reference' => 'Synthetic packing list', 'sha256' => str_repeat('b', 64)]);
        $after = $db->table('cost_layers')->where('id', $before->id)->first();
        $this->assertSame('2.000000', $after->remaining_qty);
        $this->assertSame('50.000000', $after->unit_cost);
        $this->assertSame($before->source_ledger_id, $after->source_ledger_id);
        $this->assertSame(1, $db->table('cost_layers')->where('item_id', $item->id)->count());
        app(StockLedgerService::class)->reverse('opening_stock:'.$entry->id.':post', 'fifo-conversion-reverse');
        $this->assertSame('0.000000', StockBalance::where('item_id', $item->id)->first()->on_hand_qty);
    }

    public function test_conversion_snapshot_is_immutable_and_migration_replays_read_only(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '10', now()->subDay()->toDateString());
        $r = $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified', now()->subDay()->toDateString(), ['reference' => 'Synthetic evidence', 'sha256' => str_repeat('a', 64)]);
        $migration = require base_path('database/migrations/tenant/2026_11_17_120000_create_item_base_unit_conversions.php');
        $migration->up();
        $this->assertSame(1, DB::connection('tenant')->table(ItemBaseUnitConversion::TABLE)->where('item_id', $item->id)->count());
        try {
            DB::connection('tenant')->table(ItemBaseUnitConversion::TABLE)->where('id', $r['event_id'])->update(['factor_numerator' => 2]);
            $this->fail('Immutable economic history');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_pending_projection_blocks_stock_writes_and_old_epoch_backdating(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $date = now()->subDay()->toDateString();
        $p = $s->preview($item, $box->id, '1', '10', $date);
        $r = $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified', $date, ['reference' => 'Synthetic evidence', 'sha256' => str_repeat('a', 64)]);
        $db = DB::connection('tenant');
        $db->table(ItemBaseUnitConversion::TABLE)->where('id', $r['event_id'])->update(['state' => 'finance_pending']);
        try {
            app(StockUnitProjection::class)->assertReady($item->id);
            $this->fail('Pending projection must gate operations');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_unit_conversion', $e->errors());
        }$db->table(ItemBaseUnitConversion::TABLE)->where('id', $r['event_id'])->update(['state' => 'ready']);
        $s->assertMovementDate($item->id, $date);
        try {
            $s->assertMovementDate($item->id, now()->subDays(2)->toDateString());
            $this->fail('Old epoch');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_unit_conversion', $e->errors());
        }
    }

    public function test_exact_one_twelfth_ratio_and_catalog_quantities_prices_and_warehouse_rules(): void
    {
        [$item,$ea,$box] = $this->scenario('average', '12');
        $item->update(['purchase_price' => '5', 'sales_price' => '10', 'reorder_point' => '24', 'reorder_qty' => '12']);
        $db = DB::connection('tenant');
        $warehouse = StockBalance::where('item_id', $item->id)->first()->warehouse_id;
        $rule = $db->table('warehouse_reorder_rules')->insertGetId(['organization_id' => $item->organization_id, 'item_id' => $item->id, 'warehouse_id' => $warehouse, 'reorder_point' => 24, 'reorder_qty' => 12]);
        $s = app(ItemBaseUnitConversion::class);
        $date = now()->subDay()->toDateString();
        $p = $s->preview($item, $box->id, '1', '12', $date);
        $this->assertTrue($p['supported'], json_encode($p['blockers']));
        $s->apply($item, $box->id, '1', '12', $p['revision_sha256'], (string) Str::uuid(), 'Verified twelve each in a box', $date, ['reference' => 'Synthetic pack evidence', 'sha256' => str_repeat('d', 64)]);
        $fresh = $item->fresh();
        $this->assertSame('60.0000', $fresh->purchase_price);
        $this->assertSame('120.0000', $fresh->sales_price);
        $this->assertSame('2.000000', $fresh->reorder_point);
        $this->assertSame('1.000000', $fresh->reorder_qty);
        $this->assertSame('2.000000', $db->table('warehouse_reorder_rules')->where('id', $rule)->value('reorder_point'));
        $balance = StockBalance::where('item_id', $item->id)->first();
        $this->assertSame('1.000000', $balance->on_hand_qty);
        $this->assertSame('60.000000', $balance->total_value);
    }

    public function test_all_warehouses_and_zero_balance_cached_costs_are_converted_without_changing_value(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $second = F::warehouse();
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'SECOND-WH', 'warehouse_id' => $second->id, 'opening_date' => now()->subDays(2)->toDateString()], [['item_id' => $item->id, 'quantity' => 30, 'unit_cost' => 7]]));
        $zero = StockBalance::create(['item_id' => $item->id, 'warehouse_id' => F::warehouse()->id, 'on_hand_qty' => 0, 'reserved_qty' => 0, 'total_value' => 0, 'average_cost' => 4]);
        $s = app(ItemBaseUnitConversion::class);
        $date = now()->subDay()->toDateString();
        $p = $s->preview($item, $box->id, '1', '10', $date);
        $this->assertTrue($p['supported'], json_encode($p['blockers']));
        $r = $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified all warehouse count', $date, ['reference' => 'Synthetic count', 'sha256' => str_repeat('e', 64)]);
        $this->assertCount(4, $r['ledger_ids']);
        $this->assertSame('5.000000', bcadd((string) StockBalance::where('item_id', $item->id)->sum('on_hand_qty'), '0', 6));
        $this->assertSame('310.000000', bcadd((string) StockBalance::where('item_id', $item->id)->sum('total_value'), '0', 6));
        $this->assertSame('40.000000', $zero->fresh()->average_cost);
    }

    public function test_asof_valuation_and_item_ledger_preserve_epoch_units_without_mixed_quantity_totals(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $s = app(ItemBaseUnitConversion::class);
        $date = now()->subDay()->toDateString();
        $p = $s->preview($item, $box->id, '1', '10', $date);
        $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified units', $date, ['reference' => 'Synthetic evidence', 'sha256' => str_repeat('f', 64)]);
        $reports = app(InventoryReportService::class);
        $old = $reports->run('inventory-valuation', new ReportFilters(itemId: $item->id, asAt: now()->subDays(2)->toDateString()));
        $new = $reports->run('inventory-valuation', new ReportFilters(itemId: $item->id, asAt: $date));
        $this->assertSame('Each', $old['rows'][0]->quantity_unit);
        $this->assertSame('Box', $new['rows'][0]->quantity_unit);
        $this->assertEquals(20, $old['rows'][0]->on_hand_qty);
        $this->assertEquals(2, $new['rows'][0]->on_hand_qty);
        $ledger = $reports->run('item-ledger', new ReportFilters(itemId: $item->id));
        $this->assertSame(['Each', 'Each', 'Box'], $ledger['rows']->pluck('quantity_unit')->all());
        $movement = $reports->run('stock-movement', new ReportFilters(itemId: $item->id));
        $this->assertArrayNotHasKey('qty_in', $movement['summary']);
        $this->assertArrayHasKey('unit_notice', $movement['summary']);
    }

    public function test_stale_balance_repair_cannot_restore_preconversion_units(): void
    {
        [$item,$ea,$box] = $this->scenario();
        $b = StockBalance::where('item_id', $item->id)->first();
        $stale = ['missing_balance_rows' => [], 'balance_recalculations' => [['applyable' => true, 'balance_id' => $b->id, 'item_id' => $item->id, 'warehouse_id' => $b->warehouse_id, 'variant_id' => null, 'lot_id' => null, 'bin_id' => null, 'ledger_qty' => '20', 'ledger_value' => '100']]];
        $s = app(ItemBaseUnitConversion::class);
        $date = now()->subDay()->toDateString();
        $p = $s->preview($item, $box->id, '1', '10', $date);
        $s->apply($item, $box->id, '1', '10', $p['revision_sha256'], (string) Str::uuid(), 'Verified units', $date, ['reference' => 'Synthetic evidence', 'sha256' => str_repeat('a', 64)]);
        $r = app(IntegrityRepairService::class)->applySafeBalanceRepairs('tenant', $item->organization_id, $stale);
        $this->assertSame(1, $r['skipped_blocked_rows']);
        $this->assertSame('2.000000', $b->fresh()->on_hand_qty);
        $this->assertSame('100.000000', $b->fresh()->total_value);
    }

    public function test_owner_api_verifies_and_retains_evidence_and_hides_pending_balances(): void
    {
        [$item,$ea,$box] = $this->scenario();
        Storage::fake('local');
        $bytes = 'Synthetic verified packing evidence';
        $path = 'inventory/item-attachments/proof.txt';
        Storage::disk('local')->put($path, $bytes);
        $attachment = ItemAttachment::create(['item_id' => $item->id, 'name' => 'Proof', 'path' => $path, 'mime_type' => 'text/plain', 'size_bytes' => strlen($bytes)]);
        $date = now()->subDay()->toDateString();
        $s = app(ItemBaseUnitConversion::class);
        $p = $s->preview($item, $box->id, '1', '10', $date);
        $request = Request::create('/api/v1/items/'.$item->id.'/base-unit-conversion/apply', 'POST', ['to_unit_id' => $box->id, 'factor_numerator' => 1, 'factor_denominator' => 10, 'effective_date' => $date, 'revision_sha256' => $p['revision_sha256'], 'request_uuid' => (string) Str::uuid(), 'reason' => 'Verified owner API flow', 'evidence' => ['attachment_id' => $attachment->id, 'reference' => 'item-attachment:'.$attachment->id, 'sha256' => hash('sha256', $bytes)]]);
        $response = app(ItemController::class)->applyBaseUnitConversion($request, $item)->getData(true);
        $this->assertTrue($response['success']);
        $event = $response['data']['event_id'];
        try {
            app(ItemAttachmentController::class)->destroy($attachment);
            $this->fail('Applied evidence cannot be removed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('attachment', $e->errors());
        }Storage::disk('local')->assertExists($path);
        DB::connection('tenant')->table(ItemBaseUnitConversion::TABLE)->where('id', $event)->update(['state' => 'finance_pending']);
        $data = app(ItemController::class)->show($item->fresh())->getData(true)['data'];
        $this->assertTrue($data['base_unit_projection_pending']);
        $this->assertNull($data['stock_by_warehouse']);
    }
}
