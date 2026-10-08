<?php

namespace Tests\Unit;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Unit;
use App\Observers\ItemCatalogObserver;
use App\Services\Catalog\DurableCatalogSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class ItemCatalogObserverTest extends TestCase
{
    use TenantAware;

    private $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        IntegrationOrganizationMapping::create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified',
            'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now(),
        ]);
        $unit = Unit::create(['code' => 'CAT-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $this->item = StockTestFactory::averageItem(['base_unit_id' => $unit->id]);
    }

    private function state()
    {
        return DB::connection('tenant')->table(DurableCatalogSync::TABLE)
            ->where('entity_type', 'item')->where('source_id', $this->item->id);
    }

    public function test_observer_records_durable_identity_dependencies_and_new_revision_without_transport(): void
    {
        $first = $this->state()->sole();
        $this->assertSame('pending', $first->state);
        $this->assertSame(1, DB::connection('tenant')->table(DurableCatalogSync::TABLE)->where('entity_type', 'unit')->count());
        (new ItemCatalogObserver())->saved($this->item);
        $this->assertSame($first->source_uuid, $this->state()->sole()->source_uuid);
        $this->assertSame($first->state_version, $this->state()->sole()->state_version);
        $this->item->update(['name' => 'Renamed native item']);
        $renamed = $this->state()->sole();
        $this->assertSame($first->source_uuid, $renamed->source_uuid);
        $this->assertNotSame($first->source_revision, $renamed->source_revision);
        $this->assertSame((int) $first->state_version + 1, (int) $renamed->state_version);
        $this->assertSame('Renamed native item', json_decode($renamed->source_snapshot, true)['name']);
        $this->assertSame(1, $this->state()->count());
    }

    public function test_native_retry_preserves_live_lease_then_reuses_identity_and_keeps_delivered_state(): void
    {
        $first = $this->state()->sole();
        $lease = (string) Str::uuid();
        $this->state()->update(['state' => 'unknown_outcome', 'actor_id' => 88, 'attempts' => 3,
            'last_error' => 'finance_connection_transport_unknown_retry_same_key',
            'lease_uuid' => $lease, 'lease_expires_at' => now()->addMinute(), 'next_attempt_at' => now()->addMinute()]);
        $sync = app(DurableCatalogSync::class);
        $sync->retry('item', $this->item->id, TenantTestManager::ORG_A, 88);
        $this->assertSame($lease, $this->state()->sole()->lease_uuid);
        $this->assertSame('unknown_outcome', $this->state()->sole()->state);
        $this->state()->update(['lease_expires_at' => now()->subMinute()]);
        $sync->retry('item', $this->item->id, TenantTestManager::ORG_A, 88);
        $retry = $this->state()->sole();
        $this->assertSame($first->source_uuid, $retry->source_uuid);
        $this->assertSame('pending', $retry->state);
        $this->assertSame(0, (int) $retry->attempts);
        $this->assertNull($retry->lease_uuid);
        $this->assertNull($retry->next_attempt_at);
        $this->assertNull($retry->last_error);
        $this->state()->update(['state' => 'delivered', 'target_id' => 701]);
        $sync->retry('item', $this->item->id, TenantTestManager::ORG_A, 99);
        $this->assertSame('delivered', $this->state()->sole()->state);
        $this->assertSame(88, (int) $this->state()->sole()->actor_id);
        $this->assertSame(1, $this->state()->count());
    }
}
