<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{Customer, IntegrationOrganizationMapping, IntegrationSetting, Supplier};
use App\Services\Integration\{ContinuousPartySync, LinkedPartyMerge};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class LinkedPartyMergeTest extends TestCase
{
    use TenantAware;

    private int $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $this->org = TenantTestManager::ORG_A;
    }

    private function supplier(string $code): Supplier
    {
        return Supplier::create(['code' => $code, 'name' => $code, 'is_active' => true]);
    }

    private function item(string $sku, ?int $preferred = null): int
    {
        return DB::connection('tenant')->table('items')->insertGetId(['organization_id' => $this->org, 'sku' => $sku, 'name' => $sku, 'preferred_supplier_id' => $preferred, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function po(string $no, int $supplier): int
    {
        return DB::connection('tenant')->table('inventory_purchase_orders')->insertGetId(['organization_id' => $this->org, 'po_number' => $no, 'supplier_id' => $supplier, 'warehouse_id' => \App\Models\Tenant\Warehouse::query()->firstOrCreate(['code' => 'PM-WH'], ['name' => 'PM', 'type' => 'warehouse'])->id, 'order_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function link(int $item, int $supplier): int
    {
        return DB::connection('tenant')->table('item_suppliers')->insertGetId(['organization_id' => $this->org, 'item_id' => $item, 'supplier_id' => $supplier, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_supplier_references_move_and_source_is_archived_with_marker(): void
    {
        $s = $this->supplier('S1');
        $t = $this->supplier('T1');
        $po = $this->po('PO-1', $s->id);
        $item = $this->item('A', $s->id);
        $r = app(LinkedPartyMerge::class)->merge($this->org, 'supplier', $s->id, $t->id);
        $db = DB::connection('tenant');
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['already_merged']);
        $this->assertSame(1, $r['counts']['inventory_purchase_orders']);
        $this->assertSame(1, $r['counts']['items']);
        $this->assertSame($t->id, (int) $db->table('inventory_purchase_orders')->where('id', $po)->value('supplier_id'));
        $this->assertSame($t->id, (int) $db->table('items')->where('id', $item)->value('preferred_supplier_id'));
        $src = Supplier::findOrFail($s->id);
        $this->assertFalse($src->is_active);
        $this->assertSame($t->id, $src->contact['merged_into']);
        $this->assertSame(1, $db->table('inventory_audit_logs')->where('action', 'supplier.party_merge')->count());
    }

    public function test_replay_is_idempotent(): void
    {
        $s = $this->supplier('S1');
        $t = $this->supplier('T1');
        $this->po('PO-1', $s->id);
        $svc = app(LinkedPartyMerge::class);
        $svc->merge($this->org, 'supplier', $s->id, $t->id);
        $again = $svc->merge($this->org, 'supplier', $s->id, $t->id);
        $this->assertTrue($again['already_merged']);
        $this->assertSame(1, DB::connection('tenant')->table('inventory_audit_logs')->where('action', 'supplier.party_merge')->count());
    }

    public function test_unique_collision_keeps_target_row_drops_source_duplicate_and_moves_tiers(): void
    {
        $s = $this->supplier('S1');
        $t = $this->supplier('T1');
        $shared = $this->item('A');
        $only = $this->item('B');
        $this->link($shared, $s->id);
        $keep = $this->link($shared, $t->id);
        $this->link($only, $s->id);
        $srcRel = DB::connection('tenant')->table('item_suppliers')->where('item_id', $shared)->where('supplier_id', $s->id)->value('id');
        DB::connection('tenant')->table('supplier_price_lists')->insert(['organization_id' => $this->org, 'item_id' => $shared, 'supplier_id' => $s->id, 'item_supplier_id' => $srcRel, 'unit_cost' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $r = app(LinkedPartyMerge::class)->merge($this->org, 'supplier', $s->id, $t->id);
        $db = DB::connection('tenant');
        $this->assertSame(1, $r['dropped']['item_suppliers']);
        $this->assertSame(1, $r['counts']['item_suppliers']);
        $this->assertSame(0, $db->table('item_suppliers')->where('supplier_id', $s->id)->count());
        $this->assertSame(2, $db->table('item_suppliers')->where('supplier_id', $t->id)->count());
        $tier = $db->table('supplier_price_lists')->first();
        $this->assertSame($keep, (int) $tier->item_supplier_id);
        $this->assertSame($t->id, (int) $tier->supplier_id);
    }

    public function test_customers_merge_the_same_way(): void
    {
        $s = Customer::create(['code' => 'C1', 'name' => 'C1', 'is_active' => true]);
        $t = Customer::create(['code' => 'C2', 'name' => 'C2', 'is_active' => true]);
        $r = app(LinkedPartyMerge::class)->merge($this->org, 'customer', $s->id, $t->id);
        $this->assertTrue($r['ok']);
        $this->assertFalse(Customer::findOrFail($s->id)->is_active);
        $this->assertTrue(Customer::findOrFail($t->id)->is_active);
    }

    public function test_errors_have_stable_codes_and_write_nothing(): void
    {
        $s = $this->supplier('S1');
        $t = $this->supplier('T1');
        $t->update(['is_active' => false]);
        $po = $this->po('PO-1', $s->id);
        foreach ([[$s->id, 999999, 404, 'party_merge_not_found'], [$s->id, $t->id, 409, 'party_merge_target_archived'], [$s->id, $s->id, 422, 'party_merge_same_party']] as [$a, $b, $status, $code]) {
            try {
                app(LinkedPartyMerge::class)->merge($this->org, 'supplier', $a, $b);
                $this->fail('expected '.$code);
            } catch (HttpException $e) {
                $this->assertSame($status, $e->getStatusCode());
                $this->assertSame($code, $e->getMessage());
            }
        }
        $this->assertSame($s->id, (int) DB::connection('tenant')->table('inventory_purchase_orders')->where('id', $po)->value('supplier_id'));
        $this->assertTrue(Supplier::findOrFail($s->id)->is_active);
    }

    public function test_other_organizations_parties_are_not_found(): void
    {
        $s = $this->supplier('S1');
        $t = $this->supplier('T1');
        $this->expectException(HttpException::class);
        app(LinkedPartyMerge::class)->merge($this->org + 1000, 'supplier', $s->id, $t->id);
    }

    public function test_dispatch_requires_continuous_party_sync_authority(): void
    {
        $this->expectException(HttpException::class);
        app(ContinuousPartySync::class)->dispatchMerge(['authority_kind' => 'other', 'actor_id' => 0, 'action' => 'purchasing.party.merge'], (object) ['id' => 1, 'client_id' => 1]);
    }
}
