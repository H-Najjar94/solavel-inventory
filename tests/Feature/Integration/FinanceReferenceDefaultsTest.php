<?php

namespace Tests\Feature\Integration;

use App\Services\Catalog\FinanceReferenceDefaultsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class FinanceReferenceDefaultsTest extends TestCase
{
    use TenantAware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();

        DB::connection('tenant')->table('organizations')->insert([
            'id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
        ]);
        foreach ([
            ['Electrical', ['Switches', 'Cables']],
            ['Plumbing', ['Valves', 'Pipes']],
            ['Mechanical', ['Bearings', 'Bolts']],
        ] as [$parentName, $children]) {
            // Two Finance seed runs must still create one Stock tree.
            foreach ([1, 2] as $seedRun) {
                $parentId = DB::connection('tenant')->table('inventory_categories')->insertGetId([
                    'organization_id' => null, 'name' => $parentName, 'parent_id' => null,
                    'level' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($children as $childName) {
                    DB::connection('tenant')->table('inventory_categories')->insert([
                        'organization_id' => null, 'name' => $childName, 'parent_id' => $parentId,
                        'level' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
        DB::connection('tenant')->table('inventory_categories')->insert([
            'organization_id' => 14, 'name' => 'Customer-specific category', 'parent_id' => null,
            'level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function canonical_finance_categories_are_deduplicated_and_custom_categories_are_not_copied(): void
    {
        $service = app(FinanceReferenceDefaultsService::class);

        $first = $service->sync(TenantTestManager::ORG_A, true);
        $this->assertSame(24, $first['categories']['created']);
        $this->assertSame('shared_starter_catalog_plus_legacy_finance_defaults', $first['categories']['policy']);
        $this->assertSame([], $first['categories']['missing_in_finance']);

        $categories = DB::connection('tenant')->table('item_categories')
            ->where('organization_id', TenantTestManager::ORG_A)->orderBy('id')->get();
        $this->assertCount(24, $categories);
        $this->assertContains('General Merchandise', $categories->whereNull('parent_id')->pluck('name')->all());
        $this->assertContains('Services & Non-Inventory', $categories->whereNull('parent_id')->pluck('name')->all());
        $this->assertFalse($categories->contains('name', 'Customer-specific category'));

        $second = $service->sync(TenantTestManager::ORG_A, true);
        $this->assertSame(0, $second['categories']['created']);
        $this->assertSame(24, $second['categories']['existing']);
        $this->assertSame(24, DB::connection('tenant')->table('item_categories')
            ->where('organization_id', TenantTestManager::ORG_A)->count());
    }

    #[Test]
    public function starter_categories_are_available_even_before_finance_categories_exist(): void
    {
        DB::connection('tenant')->table('inventory_categories')->delete();

        $result = app(FinanceReferenceDefaultsService::class)->sync(TenantTestManager::ORG_A, true);

        $this->assertSame(15, $result['categories']['created']);
        $this->assertSame(15, DB::connection('tenant')->table('item_categories')
            ->where('organization_id', TenantTestManager::ORG_A)->count());
        $this->assertFalse(DB::connection('tenant')->table('item_categories')
            ->where('organization_id', TenantTestManager::ORG_A)->where('name', 'iPhone 15')->exists());
    }
}
