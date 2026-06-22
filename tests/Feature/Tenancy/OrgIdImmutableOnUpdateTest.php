<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant\Item;
use App\Tenancy\Concerns\BelongsToOrganization;
use App\Tenancy\OrganizationContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Medium audit item: tenant models use $guarded = ['id'], so organization_id is
 * mass-assignable. The BelongsToOrganization trait stamps it on create and now
 * makes it IMMUTABLE on update — otherwise a mass-assigned update could move a
 * row to another org. These tests prove the update guard fails closed while
 * ordinary updates keep working.
 */
class OrgIdImmutableOnUpdateTest extends TestCase
{
    use TenantAware;

    #[Test]
    public function a_normal_field_update_still_works(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'UP-OK', 'name' => 'Before']);

        $item->update(['name' => 'After']);

        $this->assertSame('After', $item->fresh()->name);
        $this->assertSame(TenantTestManager::ORG_A, (int) $item->fresh()->organization_id);
    }

    #[Test]
    public function mass_assigning_a_foreign_organization_id_on_update_is_rejected(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'UP-MOVE', 'name' => 'Mine']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('organization_id is immutable');

        // Mass-assignment vector: ->update([...]) on a $guarded=['id'] model.
        $item->update(['organization_id' => TenantTestManager::ORG_B, 'name' => 'Moved']);
    }

    #[Test]
    public function explicitly_setting_a_different_organization_id_then_saving_is_rejected(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'UP-SET', 'name' => 'Mine']);

        $item->organization_id = TenantTestManager::ORG_B;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('organization_id is immutable');
        $item->save();
    }

    #[Test]
    public function re_saving_with_the_same_org_id_is_a_no_op_and_allowed(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'UP-SAME', 'name' => 'Mine']);

        // Setting the SAME org id is not dirty → no exception, update proceeds.
        $item->organization_id = TenantTestManager::ORG_A;
        $item->name = 'Renamed';
        $item->save();

        $this->assertSame('Renamed', $item->fresh()->name);
    }

    #[Test]
    public function the_row_is_not_moved_after_a_rejected_update(): void
    {
        $this->useTenantA();
        $item = Item::create(['sku' => 'UP-INTACT', 'name' => 'Mine']);

        try {
            $item->update(['organization_id' => TenantTestManager::ORG_B]);
        } catch (\RuntimeException $e) {
            // expected
        }

        // Still owned by org A in the database.
        $this->assertSame(
            TenantTestManager::ORG_A,
            (int) Item::query()->whereKey($item->id)->value('organization_id')
        );
    }
}
