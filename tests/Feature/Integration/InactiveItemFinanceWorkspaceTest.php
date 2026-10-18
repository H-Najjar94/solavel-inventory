<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\InventoryAuditLog;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemBarcode;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\OpeningStockEntry;
use App\Models\Tenant\PurchaseValuationHold;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\WarehouseZone;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Integration\IntegrationStatusService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContractBuilder;
use App\Services\InventoryWorkspace\MigrationCatalogScope;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use App\Services\Purchasing\PostedPurchaseSettlementService;
use App\Services\Stock\StockLedgerService;
use App\Services\Stock\StockMovement;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class InactiveItemFinanceWorkspaceTest extends TestCase
{
    use TenantAware { tearDown as tenantTearDown; }

    private const SECRET = 'isolated-workspace-signature-secret-0000000000';

    private const ACTOR = 980071;

    private const CLIENT = 980072;

    private const APPROVER = 980075;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        config()->set('finance_workspace.secret', self::SECRET);
        config()->set('inventory.demo_tenant.enabled', false);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('inventory_entitlements.feature_enforcement', true);
        $this->centralFixtureSchema();
        // The disposable shared-Finance projection must satisfy the actual
        // onboarding contract; an application grant alone is not readiness.
        DB::connection('tenant')->table('organizations')->insertOrIgnore(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
            'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $central = DB::connection('mysql');
        $central->beginTransaction();
        $central->table('clients')->insert(['id' => self::CLIENT, 'is_active' => true]);
        $central->table('organizations')->updateOrInsert(['id' => TenantTestManager::ORG_A], ['client_id' => self::CLIENT,
            'name' => 'Isolated workspace', 'database_name' => 'workspace-fixture', 'is_active' => true]);
        $central->table('users')->insert(['id' => self::ACTOR, 'client_id' => self::CLIENT,
            'name' => 'Synthetic owner', 'email' => 'workspace-owner@example.invalid', 'password' => 'not-a-login', 'status' => 'active']);
        $central->table('user_organizations')->insert(['user_id' => self::ACTOR, 'organization_id' => TenantTestManager::ORG_A, 'role' => 'client_owner', 'status' => 'active']);
        // Q12.6: a second owner-level person for the inactive write-off approval.
        $central->table('users')->insert(['id' => self::APPROVER, 'client_id' => self::CLIENT,
            'name' => 'Synthetic approver', 'email' => 'workspace-approver@example.invalid', 'password' => 'not-a-login', 'status' => 'active']);
        $central->table('user_organizations')->insert(['user_id' => self::APPROVER, 'organization_id' => TenantTestManager::ORG_A, 'role' => 'client_owner', 'status' => 'active']);
        foreach (['finance' => 980073, 'inventory' => 980074] as $slug => $id) {
            $central->table('projects')->insert(['id' => $id, 'slug' => $slug, 'is_active' => true]);
            $central->table('organization_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'is_active' => true]);
            $central->table('user_projects')->insert(['project_id' => $id, 'organization_id' => TenantTestManager::ORG_A, 'user_id' => self::ACTOR, 'is_active' => true]);
        }
        $central->table('entitlement_state_snapshots')->updateOrInsert(['organization_id' => TenantTestManager::ORG_A], ['underlying_subscription_state' => 'paid_active',
            'effective_access_state' => 'paid_active', 'state_hash' => str_repeat('a', 64),
            'state_payload' => json_encode([
                'client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
                'integration_capabilities' => ['connection_activation_delivery_entitled' => true],
                'applications' => ['finance' => ['accessible' => true, 'commercially_entitled' => true],
                    'inventory' => ['accessible' => true, 'commercially_entitled' => true]],
            ]),
        ]);
        $cache = $this->createStub(EntitlementsCache::class);
        $cache->method('currentClientId')->willReturn(self::CLIENT);
        $cache->method('getProjectSnapshot')->willReturn(['accessible' => true, 'commercially_entitled' => true,
            'tier' => 'enterprise', 'access_until' => now()->addMonth()->toIso8601String(),
            'allowed_features' => ['stock.locations_bins', 'stock.transfers', 'stock.counts', 'stock.landed_costs']]);
        $this->app->instance(EntitlementsCache::class, $cache);
        // Keep the rollback transaction and resolve only the fixed disposable database.
        $tenants = $this->createMock(TenantManager::class);
        $tenants->method('resolveDatabaseName')->with(self::CLIENT)->willReturn('solastock_test_a');
        $tenants->method('useTenant')->with(TenantTestManager::ORG_A, 'solastock_test_a');
        $this->app->instance(TenantManager::class, $tenants);
        IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => self::CLIENT,
            'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => 'solastock_test_a',
            'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active',
            'base_currency_code' => 'JOD', 'verified_at' => now(),
        ]);
        IntegrationSetting::query()->create(['organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14, 'meta' => ['transport_enabled_workflows' => []]]);
        $status = $this->createStub(IntegrationStatusService::class);
        $status->method('status')->willReturnCallback(fn ($id) => ['readiness' => ['state' => IntegrationSetting::where('organization_id', $id)->value('mode') === 'active' ? 'CONNECTED_READY' : 'CONNECTION_BLOCKED']]);
        $this->app->instance(IntegrationStatusService::class, $status);
        // Central is the production authority; this isolated server has no
        // network access and supplies the same decision from synthetic fixtures.
        $access = $this->createStub(CentralAppAccess::class);
        $access->method('decision')->willReturnCallback(function (int $userId, int $organizationId, string $appKey): array {
            $central = DB::connection('mysql');
            $project = $central->table('projects')->where('slug', $appKey)->where('is_active', true)->value('id');
            $allowed = $project && $central->table('user_organizations')->where('user_id', $userId)
                ->where('organization_id', $organizationId)->where('status', 'active')->exists()
                && $central->table('user_projects')->where('user_id', $userId)->where('organization_id', $organizationId)
                    ->where('project_id', $project)->where('is_active', true)->exists();
            $role = $central->table('user_organizations')->where('user_id', $userId)->where('organization_id', $organizationId)->value('role');

            return ['allowed' => (bool) $allowed, 'reason' => $allowed ? 'allowed' : 'access_required',
                'owner' => $allowed && $role === 'client_owner',
                'roles' => $role === 'viewer' ? ['scoped_inventory_viewer'] : ($role === 'client_owner' ? [] : [$role])];
        });
        $this->app->instance(CentralAppAccess::class, $access);
    }

    protected function tearDown(): void
    {
        DB::connection('mysql')->rollBack();
        $this->tenantTearDown();
    }

    private function send(array $input, ?string $nonce = null)
    {
        $payload = array_replace(['client_id' => self::CLIENT, 'organization_id' => TenantTestManager::ORG_A,
            'finance_organization_id' => 14, 'actor_id' => self::ACTOR], $input);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce ??= bin2hex(random_bytes(24));

        return $this->call('POST', WorkspaceSignature::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WORKSPACE_TIMESTAMP' => $timestamp, 'HTTP_X_WORKSPACE_NONCE' => $nonce,
            'HTTP_X_WORKSPACE_SIGNATURE' => WorkspaceSignature::sign($body, $timestamp, $nonce, self::SECRET),
        ], $body);
    }

    private function centralFixtureSchema(): void
    {
        $schema = Schema::connection('mysql');
        if (! $schema->hasTable('app_permission_grants')) {
            $schema->create('app_permission_grants', function ($t): void {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('app_key');
                $t->string('permission_key');
                $t->string('effect');
                $t->timestamp('expires_at')->nullable();
            });
        }
        if (! $schema->hasColumn('users', 'deleted_at')) {
            $schema->table('users', fn ($t) => $t->softDeletes());
        }
        foreach (['clients', 'projects', 'organization_projects', 'user_projects'] as $name) {
            if (! $schema->hasTable($name)) {
                $schema->create($name, function ($t) use ($name) {
                    $t->id();
                    $t->boolean('is_active')->default(true);
                    $t->softDeletes();
                    if ($name === 'projects') {
                        $t->string('slug');
                    }
                    if (in_array($name, ['organization_projects', 'user_projects'], true)) {
                        $t->unsignedBigInteger('project_id');
                        $t->unsignedBigInteger('organization_id');
                    }
                    if ($name === 'user_projects') {
                        $t->unsignedBigInteger('user_id');
                    }
                });
            }
        }
    }

    private function catalogItem(): Item
    {
        $unit = Unit::query()->firstOrCreate(['code' => 'Q12-WS'], ['name' => 'Piece', 'kind' => 'count', 'is_active' => true]);
        $category = ItemCategory::query()->firstOrCreate(['name' => 'Q12 category'], ['level' => 0, 'is_active' => true]);

        return StockTestFactory::averageItem(['base_unit_id' => $unit->id, 'category_id' => $category->id]);
    }

    private function assertInactiveRefused($response, int $itemId): void
    {
        $response->assertStatus(422);
        // Some controllers wrap validation errors in their error envelope; the framework renders the rest.
        $this->assertSame([$itemId], $response->json('error.errors.inactive_item_ids') ?? $response->json('errors.inactive_item_ids'), (string) $response->getContent());
    }

    /** Signed Finance workspace HTTP command -> router -> the same /api/v1 controllers the SolaStock UI uses. */
    public function test_signed_workspace_commands_refuse_new_documents_for_an_inactive_item(): void
    {
        $warehouse = Warehouse::create(['name' => 'Q12 warehouse', 'code' => 'Q12-WH', 'type' => 'warehouse']);
        $other = Warehouse::create(['name' => 'Q12 other', 'code' => 'Q12-WH2', 'type' => 'warehouse']);
        $item = $this->catalogItem();
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
        $key = fn (string $s) => 'q12-inactive-'.$s.'-'.bin2hex(random_bytes(4));

        $this->assertInactiveRefused($this->send(['action' => 'adjustments.store', 'idempotency_key' => $key('adj'), 'data' => [
            'warehouse_id' => $warehouse->id, 'adjustment_date' => '2026-10-18', 'reason_code' => 'FOUND',
            'lines' => [['item_id' => $item->id, 'direction' => 'increase', 'quantity' => '2', 'unit_cost' => '3']]]]), $item->id);
        $this->assertInactiveRefused($this->send(['action' => 'grn.store', 'idempotency_key' => $key('grn'), 'data' => [
            'warehouse_id' => $warehouse->id, 'receipt_date' => '2026-10-18',
            'lines' => [['item_id' => $item->id, 'received_qty' => '2', 'unit_cost' => '3', 'entered_unit_id' => $item->base_unit_id]]]]), $item->id);
        $this->assertInactiveRefused($this->send(['action' => 'opening.store', 'idempotency_key' => $key('os'), 'data' => [
            'warehouse_id' => $warehouse->id, 'opening_date' => '2026-10-18',
            'lines' => [['item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '3']]]]), $item->id);
        $this->assertInactiveRefused($this->send(['action' => 'transfers.store', 'idempotency_key' => $key('tr'), 'data' => [
            'from_warehouse_id' => $warehouse->id, 'to_warehouse_id' => $other->id, 'transfer_date' => '2026-10-18',
            'lines' => [['item_id' => $item->id, 'quantity' => '1']]]]), $item->id);
        $this->assertInactiveRefused($this->send(['action' => 'po.store', 'idempotency_key' => $key('po'), 'data' => [
            'warehouse_id' => $warehouse->id, 'order_date' => '2026-10-18',
            'lines' => [['item_id' => $item->id, 'ordered_qty' => '2', 'unit_price' => '3']]]]), $item->id);
        // Nothing was written for the item (other committed fixtures may exist in the shared schema).
        foreach (['stock_adjustment_lines', 'goods_receipt_lines', 'opening_stock_entry_lines', 'stock_transfer_lines', 'purchase_order_lines'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->where('item_id', $item->id)->count(), $table);
        }
    }

    public function test_signed_workspace_write_off_of_positive_stock_on_an_inactive_item_is_allowed(): void
    {
        $warehouse = Warehouse::create(['name' => 'Q12 write-off', 'code' => 'Q12-WO', 'type' => 'warehouse']);
        $item = $this->catalogItem();
        // Existing (legacy) positive stock of an item that is already inactive.
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'on_hand_qty' => '5', 'reserved_qty' => '0', 'average_cost' => '2', 'total_value' => '10']);
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
        $created = $this->send(['action' => 'adjustments.store', 'idempotency_key' => 'q12-write-off-'.bin2hex(random_bytes(6)), 'data' => [
            'warehouse_id' => $warehouse->id, 'adjustment_date' => '2026-10-18', 'reason_code' => 'DAMAGE',
            'lines' => [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '2']]]]);
        $created->assertCreated();
        $this->assertSame('decrease', \App\Models\Tenant\StockAdjustment::query()->findOrFail($created->json('data.id'))->lines()->sole()->direction);
    }

    /** Q12.6 (G6): signed workspace write-off needs a second owner-level approval; self-approval and viewers are refused. */
    public function test_signed_workspace_inactive_write_off_needs_a_second_person_approval_before_posting(): void
    {
        $warehouse = Warehouse::create(['name' => 'Q12 approval', 'code' => 'Q12-AP', 'type' => 'warehouse']);
        $item = $this->catalogItem();
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'on_hand_qty' => '5', 'reserved_qty' => '0', 'average_cost' => '2', 'total_value' => '10']);
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
        $key = fn (string $p) => 'q12-g6-'.$p.'-'.bin2hex(random_bytes(6));
        $created = $this->send(['action' => 'adjustments.store', 'idempotency_key' => $key('store'), 'data' => [
            'warehouse_id' => $warehouse->id, 'adjustment_date' => '2026-10-18', 'reason_code' => 'DAMAGE',
            'lines' => [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '2']]]]);
        $created->assertCreated();
        $this->assertTrue($created->json('data.inactive_write_off.requires_approval'));
        $this->assertNotEmpty($created->json('data.inactive_write_off.warnings'));
        $id = (int) $created->json('data.id');
        $revision = fn () => $this->send(['action' => 'adjustments.show', 'parameters' => ['adjustment' => $id]])->json('workspace_revision');
        $call = fn (string $action, string $tag, int $actor = self::ACTOR) => $this->send(['action' => $action, 'idempotency_key' => $key($tag),
            'actor_id' => $actor, 'parameters' => ['adjustment' => $id], 'revision' => $revision()]);

        $call('adjustments.post', 'post-unapproved')->assertForbidden();
        $call('adjustments.approve-inactive-write-off', 'self')->assertForbidden(); // creator cannot approve
        $approved = $call('adjustments.approve-inactive-write-off', 'approve', self::APPROVER);
        $approved->assertOk();
        $this->assertSame(self::APPROVER, $approved->json('data.inactive_write_off.approval.approved_by'));
        $call('adjustments.post', 'post-by-approver', self::APPROVER)->assertForbidden(); // approver cannot post
        $this->assertSame('5.0000', (string) DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->value('on_hand_qty'));
        $call('adjustments.post', 'post')->assertOk();
        $this->assertSame('3.0000', (string) DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->value('on_hand_qty'));
        $audit = InventoryAuditLog::query()->where('action', 'stock_adjustment.inactive_item_write_off')->where('entity_id', $id)->sole();
        $this->assertSame(self::ACTOR, (int) $audit->actor_user_id);
        $this->assertSame(TenantTestManager::ORG_A, (int) $audit->organization_id);
        $this->assertSame(self::APPROVER, $audit->after['approved_by']);
        $this->assertNotEmpty($audit->after['approval_operation_id']);
        $this->assertSame('stock_adjustment:'.$id.':post', $audit->after['operation_id']);
        $this->assertSame(['5.0000', '3.0000'], [$audit->before['on_hand_qty'], $audit->after['on_hand_qty']]);
    }

    public function test_signed_workspace_inactive_write_off_approval_is_refused_for_a_viewer(): void
    {
        $warehouse = Warehouse::create(['name' => 'Q12 approval viewer', 'code' => 'Q12-APV', 'type' => 'warehouse']);
        $item = $this->catalogItem();
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'on_hand_qty' => '5', 'reserved_qty' => '0', 'average_cost' => '2', 'total_value' => '10']);
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
        $adj = app(\App\Services\Documents\StockAdjustmentService::class)->createDraft(['warehouse_id' => $warehouse->id, 'reason_code' => 'DAMAGE'],
            [['item_id' => $item->id, 'direction' => 'decrease', 'quantity' => '1']]);
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::APPROVER)->update(['role' => 'viewer']);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $revision = $this->send(['action' => 'adjustments.show', 'parameters' => ['adjustment' => $adj->id]])->json('workspace_revision');
        $this->send(['action' => 'adjustments.approve-inactive-write-off', 'idempotency_key' => 'q12-g6-viewer-'.bin2hex(random_bytes(6)),
            'actor_id' => self::APPROVER, 'parameters' => ['adjustment' => $adj->id], 'revision' => $revision])->assertForbidden();
        $this->assertSame(0, InventoryAuditLog::query()->where('action', 'stock_adjustment.inactive_write_off_approved')->count());
    }

    /** Signed Finance workspace: the dispatcher applies the route permission to the signed actor before the controller. */
    public function test_signed_workspace_count_post_for_an_inactive_item_needs_the_actor_permission(): void
    {
        $warehouse = Warehouse::create(['name' => 'Q12 count WS', 'code' => 'Q12-CWS', 'type' => 'warehouse']);
        $item = $this->catalogItem();
        StockBalance::create(['organization_id' => TenantTestManager::ORG_A, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'on_hand_qty' => '5', 'reserved_qty' => '0', 'average_cost' => '2', 'total_value' => '10']);
        DB::connection('tenant')->table('items')->where('id', $item->id)->update(['is_active' => false]);
        $count = app(\App\Services\Documents\StockCountService::class)->createDraft(['warehouse_id' => $warehouse->id], [['item_id' => $item->id, 'system_qty' => '5', 'counted_qty' => '4']]);
        DB::connection('mysql')->table('user_organizations')->where('user_id', self::ACTOR)->update(['role' => 'viewer']);
        $this->app->forgetInstance(InventoryPermissionService::class);
        $this->send(['action' => 'counts.post', 'idempotency_key' => 'q12-count-viewer-'.bin2hex(random_bytes(6)), 'parameters' => ['stock_count' => $count->id], 'revision' => str_repeat('0', 64)])
            ->assertForbidden();
        $this->assertSame('draft', $count->fresh()->status);
        $this->assertSame('5.0000', (string) DB::connection('tenant')->table('stock_balances')->where('item_id', $item->id)->value('on_hand_qty'));
    }
}
