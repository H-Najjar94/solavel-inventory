<?php

namespace Tests\Feature\Stock;

use App\Models\Tenant\Item;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Catalog\ItemBaseUnitGuard;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class ItemBaseUnitGuardTest extends TestCase
{
    use TenantAware;

    private function setupItem(): array
    {
        $this->useTenantA();
        $a = Unit::create(['code' => 'EA-'.uniqid(), 'name' => 'Each', 'is_active' => true]);
        $b = Unit::create(['code' => 'BOX-'.uniqid(), 'name' => 'Box', 'is_active' => true]);

        return [F::averageItem(['base_unit_id' => $a->id]), $a, $b];
    }

    private function refused(Item $item, Unit $target, string $reason): void
    {
        $before = $item->fresh()->getAttributes();
        $counts = DB::connection('tenant')->table('stock_ledger')->count();
        for ($i = 0; $i < 2; $i++) {
            try {
                $item->forceFill(['base_unit_id' => $target->id])->save();
                $this->fail('Used base unit must remain unchanged');
            } catch (ValidationException $e) {
                $this->assertContains($reason, $e->errors()['base_unit_blockers']);
            }
        }
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame($counts, DB::connection('tenant')->table('stock_ledger')->count());
    }

    public function test_unused_item_can_change_unit_and_repeat_unchanged_save(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $item->update(['base_unit_id' => $b->id]);
        $this->assertSame($b->id, (int) $item->fresh()->base_unit_id);
        $item->refresh()->update(['base_unit_id' => $b->id, 'name' => 'Renamed']);
        $this->assertSame('Renamed', $item->fresh()->name);
        $this->assertTrue(app(ItemBaseUnitGuard::class)->preview($item->fresh())['can_change_base_unit']);
    }

    public function test_positive_negative_and_value_only_balances_are_not_netted(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $w = F::warehouse();
        $other = F::warehouse();
        foreach ([[$w->id, '2', '0'], [$other->id, '-2', '0']] as [$wid,$q,$value]) {
            StockBalance::create(['organization_id' => $item->organization_id, 'item_id' => $item->id, 'warehouse_id' => $wid, 'on_hand_qty' => $q, 'reserved_qty' => 0, 'total_value' => $value]);
        }$this->refused($item, $b, 'stock_balance_or_value');
    }

    public function test_reservation_and_value_without_on_hand_block_change(): void
    {
        [$item,$a,$b] = $this->setupItem();
        StockBalance::create(['organization_id' => $item->organization_id, 'item_id' => $item->id, 'warehouse_id' => F::warehouse()->id, 'on_hand_qty' => 0, 'reserved_qty' => 1, 'total_value' => 0]);
        $this->refused($item, $b, 'stock_balance_or_value');
    }

    public function test_open_document_and_stale_item_cannot_bypass_model_guard(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $stale = $item->fresh();
        app(OpeningStockService::class)->createDraft(['entry_number' => 'BASE-DRAFT', 'warehouse_id' => F::warehouse()->id], [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '5']]);
        $this->refused($stale, $b, 'opening_stock_entry_lines');
        $this->assertFalse(app(ItemBaseUnitGuard::class)->preview($item->fresh())['can_change_base_unit']);
    }

    public function test_zero_remaining_stock_does_not_erase_unit_history(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $w = F::warehouse();
        $opening = app(OpeningStockService::class);
        $opening->post($opening->createDraft(['entry_number' => 'BASE-HISTORY', 'warehouse_id' => $w->id], [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '5']]));
        $adjust = app(StockAdjustmentService::class);
        $adjust->post($adjust->createDraft(['adjustment_number' => 'BASE-OUT', 'warehouse_id' => $w->id, 'reason_code' => 'DAMAGE'], [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '2']]));
        $this->assertEquals(0, StockBalance::where('item_id', $item->id)->sum('on_hand_qty'));
        $this->refused($item, $b, 'stock_history');
        $item->fresh()->update(['name' => 'Safe description update']);
        $this->assertSame('Safe description update', $item->fresh()->name);
    }

    public function test_item_conversion_preserves_base_and_frozen_historical_factor(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $c = UnitConversion::create(['item_id' => $item->id, 'from_unit_id' => $b->id, 'to_unit_id' => $a->id, 'factor' => 10]);
        $resolver = app(UnitConversionResolver::class);
        $line = $resolver->normalizeLine(['item_id' => $item->id, 'quantity' => '2', 'entered_unit_id' => $b->id], 'quantity');
        $this->assertSame('20.000000', $line['quantity']);
        $this->refused($item, $b, 'unit_conversions');
        $c->update(['factor' => 12]);
        $this->assertSame('10.00000000', $line['unit_conversion_factor']);
        $this->assertSame('24.000000', $resolver->normalizeLine(['item_id' => $item->id, 'quantity' => '2', 'entered_unit_id' => $b->id], 'quantity')['quantity']);
        $this->assertSame($a->id, (int) $item->fresh()->base_unit_id);
    }

    public function test_linked_catalog_item_requires_cross_app_review_even_with_no_stock(): void
    {
        [$item,$a,$b] = $this->setupItem();
        DB::connection('tenant')->table('integration_master_data_mappings')->insert(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => 41, 'finance_organization_id' => 14, 'solastock_organization_id' => $item->organization_id, 'entity_type' => 'item', 'solastock_record_id' => (string) $item->id, 'solabooks_record_id' => '44', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        $this->refused($item, $b, 'finance_catalog_mapping');
        $p = app(ItemBaseUnitGuard::class)->preview($item->fresh());
        $this->assertFalse($p['history_conversion_supported']);
        $this->assertStringContainsString('purchase_unit_item='.$item->id, $p['alternate_unit_url']);
    }

    public function test_direct_model_update_rejects_foreign_org_unit(): void
    {
        [$item,$a,$b] = $this->setupItem();
        $id = DB::connection('tenant')->table('units')->insertGetId(['organization_id' => TenantTestManager::ORG_B, 'code' => 'FOREIGN', 'name' => 'Foreign', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $item->forceFill(['base_unit_id' => $id])->save();
            $this->fail('Cross-org unit must not be accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_unit_id', $e->errors());
        }$this->assertSame($a->id,(int) $item->fresh()->base_unit_id);
    }
}
