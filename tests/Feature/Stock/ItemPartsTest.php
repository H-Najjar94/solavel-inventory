<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Api\V1\ItemController;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\ItemPart;
use App\Services\Catalog\ItemParts\ItemPartService;
use App\Services\InventoryWorkspace\WorkspaceActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class ItemPartsTest extends TestCase
{
    use TenantAware;

    private function service(): ItemPartService
    {
        return app(ItemPartService::class);
    }

    private function boot(): void
    {
        $this->useTenantA();
    }

    private function assertValidation(callable $callback, string $field): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));

            return;
        }
        $this->fail("Expected a validation error on {$field}.");
    }

    /** Verified Finance connection + item mappings, and the SolaCount table (created only when absent). */
    private function financeSide(array $stockToFinance): void
    {
        if (! Schema::connection('tenant')->hasTable('inventory_item_parts')) {
            $this->markTestSkipped('inventory_item_parts is not present in the test tenant database.');
        }
        $org = \Tests\Support\TenantTestManager::ORG_A;
        $mapping = IntegrationOrganizationMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => $org, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => $org, 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
        foreach ($stockToFinance as $stockId => $financeId) {
            IntegrationMasterDataMapping::query()->create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                'central_client_id' => 7, 'central_organization_id' => $org, 'finance_organization_id' => 14,
                'solastock_organization_id' => $org, 'entity_type' => 'item',
                'solastock_record_id' => (string) $stockId, 'solabooks_record_id' => (string) $financeId, 'status' => 'verified']);
        }
        // DDL would implicitly commit the test transaction, so the SolaCount table must already exist in the reserved tenant DB.
        if (! Schema::connection('tenant')->hasTable('inventory_item_parts')) {
            $this->markTestSkipped('inventory_item_parts is not present in the test tenant database.');
        }
    }

    #[Test]
    public function a_link_is_stored_audited_and_listed_on_both_items(): void
    {
        $this->boot();
        [$p, $c] = [F::item(), F::item()];
        $this->assertTrue($this->service()->attach($p->id, $c->id));

        $this->assertSame([$c->id], array_column($this->service()->links($p->id)['parts'], 'id'));
        $this->assertSame([$p->id], array_column($this->service()->links($c->id)['parents'], 'id'));
        $this->assertSame('stock', $this->service()->links($p->id)['parts'][0]['owner']);
        $this->assertSame([$c->id], $this->service()->partIds($p->id));
        $this->assertTrue(InventoryAuditLog::query()->where('action', 'item_part.linked')->where('entity_id', $p->id)->exists());
    }

    #[Test]
    public function self_duplicate_inactive_and_cycles_are_handled(): void
    {
        $this->boot();
        [$a, $b, $c] = [F::item(), F::item(), F::item()];
        $inactive = F::item(['is_active' => false]);

        $this->assertValidation(fn () => $this->service()->attach($a->id, $a->id), 'part_item_id');
        $this->assertTrue($this->service()->attach($a->id, $b->id));
        $this->assertFalse($this->service()->attach($a->id, $b->id), 'duplicate is an idempotent no-op');
        $this->assertSame(1, ItemPart::query()->where('parent_item_id', $a->id)->count());

        $this->service()->attach($b->id, $c->id);
        $this->assertValidation(fn () => $this->service()->attach($c->id, $a->id), 'part_item_id');
        $this->assertValidation(fn () => $this->service()->attach($b->id, $a->id), 'part_item_id');
        $this->assertValidation(fn () => $this->service()->attach($a->id, $inactive->id), 'part_item_id');
        $this->assertValidation(fn () => $this->service()->attach($a->id, 999999), 'part_item_id');
        $this->assertSame(2, ItemPart::query()->count());
    }

    #[Test]
    public function destroy_is_idempotent_and_sync_parents_diffs(): void
    {
        $this->boot();
        [$p1, $p2, $c] = [F::item(), F::item(), F::item()];
        $this->service()->syncParents($c->id, [$p1->id, $p2->id]);
        $this->assertCount(2, $this->service()->links($c->id)['parents']);
        $this->service()->syncParents($c->id, [$p2->id]);
        $this->assertSame([$p2->id], array_column($this->service()->links($c->id)['parents'], 'id'));
        $this->assertTrue($this->service()->detach($p2->id, $c->id));
        $this->assertFalse($this->service()->detach($p2->id, $c->id), 'second destroy is a no-op');
        $this->service()->syncParents($c->id, []);
        $this->assertSame([], $this->service()->links($c->id)['parents']);
        $this->assertValidation(fn () => $this->service()->syncParents($c->id, [$c->id]), 'part_of_ids');
    }

    #[Test]
    public function an_inactive_item_keeps_its_existing_link_and_marks_it(): void
    {
        $this->boot();
        [$p, $c] = [F::item(), F::item()];
        $this->service()->attach($p->id, $c->id);
        $c->update(['is_active' => false]);

        $row = $this->service()->links($p->id)['parts'][0];
        $this->assertFalse($row['is_active']);
        $this->assertFalse($this->service()->attach($p->id, $c->id), 'existing link stays an idempotent no-op even when inactive');
    }

    #[Test]
    public function links_are_isolated_per_tenant(): void
    {
        $this->boot();
        [$p, $c] = [F::item(), F::item()];
        $this->service()->attach($p->id, $c->id);
        $this->useTenantB();
        $this->assertSame([], $this->service()->edges());
        $other = F::item();
        $this->assertValidation(fn () => $this->service()->attach($p->id + 100000, $other->id), 'part_item_id');
    }

    #[Test]
    public function a_finance_row_is_read_through_verified_mappings_and_destroy_removes_both(): void
    {
        $this->boot();
        [$p, $c, $unmapped] = [F::item(), F::item(), F::item()];
        $this->financeSide([$p->id => 501, $c->id => 502]);
        DB::connection('tenant')->table('inventory_item_parts')->insert([
            ['organization_id' => 14, 'parent_item_id' => 501, 'part_item_id' => 502, 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => 14, 'parent_item_id' => 501, 'part_item_id' => 777, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $links = $this->service()->links($p->id);
        $this->assertSame([$c->id], array_column($links['parts'], 'id'));
        $this->assertSame('finance', $links['parts'][0]['owner']);
        $this->assertSame([$p->id], array_column($this->service()->links($c->id)['parents'], 'id'));

        // The same pair is already linked in the union: idempotent, no second Stock row.
        $this->assertFalse($this->service()->attach($p->id, $c->id));
        $this->assertSame(0, ItemPart::query()->count());
        // A cycle through the Finance edge is refused.
        $this->assertValidation(fn () => $this->service()->attach($c->id, $p->id), 'part_item_id');

        // Stock also holds the pair (created before mapping) -> destroy clears both stores.
        ItemPart::query()->create(['parent_item_id' => $p->id, 'part_item_id' => $c->id]);
        $this->assertCount(1, $this->service()->links($p->id)['parts'], 'deduped by pair');
        $this->assertTrue($this->service()->detach($p->id, $c->id));
        $this->assertSame(0, ItemPart::query()->count());
        $this->assertSame(1, DB::connection('tenant')->table('inventory_item_parts')->count(), 'only the unmapped Finance row remains');
        $this->assertSame([], $this->service()->links($p->id)['parts']);
    }

    #[Test]
    public function item_search_filters_by_part_of_and_exclude(): void
    {
        $this->boot();
        [$p, $c1, $c2, $other] = [F::item(['name' => 'Parent']), F::item(['name' => 'Part A']), F::item(['name' => 'Part B']), F::item(['name' => 'Loose'])];
        $this->service()->attach($p->id, $c1->id);
        $this->service()->attach($p->id, $c2->id);

        $names = fn (array $q) => collect(json_decode(app(ItemController::class)->index(Request::create('/items', 'GET', $q))->getContent(), true)['data'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Part A', 'Part B'], $names(['part_of' => $p->id]));
        $this->assertSame(['Part B'], $names(['part_of' => $p->id, 'exclude' => (string) $c1->id]));
        $this->assertSame([], $names(['part_of' => $other->id]));
        $this->assertNotContains('Parent', $names(['search' => 'Loose', 'exclude' => $other->id]));
    }

    #[Test]
    public function routes_and_workspace_actions_exist_with_item_permissions(): void
    {
        foreach (['index' => 'inventory.view_items', 'store' => 'inventory.manage_items', 'destroy' => 'inventory.manage_items'] as $action => $perm) {
            $this->assertContains("item-parts.$action", WorkspaceActions::ALLOWED);
            $route = Route::getRoutes()->getByName("api.v1.item-parts.$action");
            $this->assertNotNull($route);
            $this->assertContains("perm:$perm", $route->gatherMiddleware());
        }
    }
}
