<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{IntegrationOrganizationMapping, IntegrationSetting, ItemSupplier, Supplier, Unit};
use App\Services\Access\InventoryPermissionService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\InventoryWorkspace\WorkspaceDispatcher;
use App\Services\Reports\InventoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * SolaCount edits SolaStock-owned supplier terms only through signed workspace commands:
 * revisioned writes, idempotent replays, stale revisions refused, and deletes that publish
 * the remaining revision instead of failing after the commit.
 */
final class SupplierTermsWorkspaceDispatchTest extends TestCase
{
    use TenantAware;

    private const ACTOR = 5151;
    private IntegrationSetting $setting;
    private IntegrationOrganizationMapping $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config(['inventory.demo_tenant.enabled' => false]);
        config(['integration_safety.phase6a_uat' => ['enabled' => true, 'organization_id' => TenantTestManager::ORG_A,
            'tenant_database' => DB::connection('tenant')->getDatabaseName()]]);
        config(['database.connections.tenant.database' => DB::connection('tenant')->getDatabaseName()]);
        $this->connection = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 77,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD',
        ]);
        $this->setting = IntegrationSetting::query()->firstOrCreate(['organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks'], ['mode' => 'active', 'solabooks_organization_id' => 701]);
        $this->setting->forceFill(['mode' => 'active'])->save();
        $this->mock(InventoryPermissionService::class, fn ($m) => $m->shouldReceive('can')->andReturn(true));
        $commercial = \Mockery::mock(InventoryCommercialEntitlementService::class);
        $commercial->shouldReceive('checkPermission', 'checkFeature')->andReturn(['allowed' => true, 'reason_code' => null]);
        $this->app->instance(InventoryCommercialEntitlementService::class, $commercial);
        $reports = \Mockery::mock(InventoryReportService::class)->makePartial();
        $reports->shouldReceive('baseCurrency')->andReturn('JOD');
        $this->app->instance(InventoryReportService::class, $reports);
    }

    /** @return array{0:int,1:mixed,2:bool} */
    private function send(string $action, array $parameters, array $data = [], ?string $key = null, ?string $revision = null): array
    {
        $request = Request::create('/api/internal/finance-workspace', 'POST');
        $request->setUserResolver(fn () => (object) ['id' => self::ACTOR]);
        \Illuminate\Support\Facades\Auth::setUser((new \App\Models\User)->forceFill(['id' => self::ACTOR]));
        $input = array_filter(['action' => $action, 'parameters' => $parameters, 'data' => $data, 'idempotency_key' => $key, 'revision' => $revision], fn ($v) => $v !== null);
        try {
            $response = app(WorkspaceDispatcher::class)->dispatch($request, $input, $this->connection, $this->setting);

            return [$response->getStatusCode(), $response->getData(true), $response->headers->get('X-Workspace-Replayed') === 'true'];
        } catch (HttpException $e) {
            return [$e->getStatusCode(), $e->getMessage(), false];
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return [$e->getResponse()->getStatusCode(), json_decode($e->getResponse()->getContent(), true), false];
        }
    }

    #[Test]
    public function solacount_creates_updates_and_deletes_supplier_terms_with_revisions_and_replays(): void
    {
        $item = F::item(['sku' => 'WS-TERMS', 'base_unit_id' => Unit::query()->create(['code' => 'WS-EA', 'name' => 'Each', 'symbol' => 'ea'])->id]);
        $supplier = Supplier::query()->create(['code' => 'WS-SUP', 'name' => 'Workspace supplier', 'is_active' => true]);

        [$status, $index] = $this->send('item-suppliers.index', ['item' => $item->id]);
        $this->assertSame(200, $status);
        $itemRevision = $index['workspace_revision'];

        $create = ['supplier_id' => $supplier->id, 'supplier_sku' => 'WS-1', 'tiers' => [['minimum_qty' => '1', 'unit_cost' => '2.5']]];
        [$status, $body] = $this->send('item-suppliers.store', ['item' => $item->id], $create, 'terms-create-1', $itemRevision);
        $this->assertSame(201, $status, json_encode($body));
        $relationship = ItemSupplier::query()->where('item_id', $item->id)->sole();

        // Retrying the same command (same key, same body) replays the committed answer once.
        [$status, , $replayed] = $this->send('item-suppliers.store', ['item' => $item->id], $create, 'terms-create-1', $itemRevision);
        $this->assertSame(201, $status);
        $this->assertTrue($replayed);
        $this->assertSame(1, ItemSupplier::query()->where('item_id', $item->id)->count());
        // The same key with a different body is a conflict, never a second write.
        [$status] = $this->send('item-suppliers.store', ['item' => $item->id], ['supplier_id' => $supplier->id, 'supplier_sku' => 'OTHER'], 'terms-create-1', $itemRevision);
        $this->assertSame(409, $status);

        [, $show] = $this->send('item-suppliers.show', ['item' => $item->id, 'itemSupplier' => $relationship->id]);
        $fresh = $show['workspace_revision'];
        [$status] = $this->send('item-suppliers.update', ['item' => $item->id, 'itemSupplier' => $relationship->id], ['lead_time_days' => 9], 'terms-update-1', $fresh);
        $this->assertSame(200, $status);
        $this->assertSame(9, $relationship->fresh()->lead_time_days);
        // An out-of-order command carrying the old revision is refused.
        [$status, $message] = $this->send('item-suppliers.update', ['item' => $item->id, 'itemSupplier' => $relationship->id], ['lead_time_days' => 1], 'terms-update-2', $fresh);
        $this->assertSame(409, $status);
        $this->assertSame('workspace_revision_conflict', $message);
        $this->assertSame(9, $relationship->fresh()->lead_time_days);

        [, $show] = $this->send('item-suppliers.show', ['item' => $item->id, 'itemSupplier' => $relationship->id]);
        [$status, $body] = $this->send('item-suppliers.destroy', ['item' => $item->id, 'itemSupplier' => $relationship->id], [], 'terms-delete-1', $show['workspace_revision']);
        $this->assertSame(200, $status, is_string($body) ? $body : json_encode($body));
        $this->assertIsString($body['workspace_revision']);
        $this->assertFalse(ItemSupplier::query()->whereKey($relationship->id)->exists());
    }

    #[Test]
    public function a_paused_connection_refuses_supplier_term_writes(): void
    {
        $item = F::item(['sku' => 'WS-PAUSED']);
        $this->setting->forceFill(['mode' => 'paused'])->save();
        [$status] = $this->send('item-suppliers.store', ['item' => $item->id], ['supplier_id' => 1], 'terms-paused', 'x');
        $this->assertSame(409, $status);
        $this->assertSame(0, ItemSupplier::query()->where('item_id', $item->id)->count());
    }
}
