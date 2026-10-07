<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\SupplierReturn;
use App\Services\Purchasing\SupplierReturnRequestService;
use App\Services\Documents\SupplierReturnService;
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

/** Actual native signed workspace admission and durable intent rows; remote Finance authority and physical post are explicitly isolated seams. */
final class SupplierReturnRequestSignedTest extends TestCase {
    use TenantAware { tearDown as tenantTearDown; }
    private const SECRET="isolated-workspace-signature-secret-0000000000";
    private const ACTOR=980071; private const CLIENT=980072;
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $this->assertTrue(Schema::connection('tenant')->hasTable('supplier_return_requests'), 'Private bootstrap must install native 191000 before runtime.');
        $this->assertTrue(Schema::connection('tenant')->hasTable('finance_supplier_return_requests'), 'Private bootstrap must install the exact typed Finance189 projection before runtime.');
        config()->set('finance_workspace.secret', self::SECRET);
        config()->set('inventory.demo_tenant.enabled', false);
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('inventory_entitlements.feature_enforcement', true);
        $this->centralFixtureSchema();
        // The disposable shared-Finance projection must satisfy the actual
        // onboarding contract; an application grant alone is not readiness.
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A,
            'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $central = DB::connection('mysql');
        $central->beginTransaction();
        $central->table('clients')->insert(['id' => self::CLIENT, 'is_active' => true]);
        $central->table('organizations')->updateOrInsert(['id' => TenantTestManager::ORG_A], ['client_id' => self::CLIENT,
            'name' => 'Isolated workspace', 'database_name' => 'workspace-fixture', 'is_active' => true]);
        $central->table('users')->insert(['id' => self::ACTOR, 'client_id' => self::CLIENT,
            'name' => 'Synthetic owner', 'email' => 'workspace-owner@example.invalid', 'password' => 'not-a-login', 'status' => 'active']);
        $central->table('user_organizations')->insert(['user_id' => self::ACTOR, 'organization_id' => TenantTestManager::ORG_A, 'role' => 'client_owner', 'status' => 'active']);
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
            'allowed_features' => ['stock.locations_bins', 'stock.transfers', 'stock.counts']]);
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
    private function source():array {
        $org = TenantTestManager::ORG_A;
        $mapping = IntegrationOrganizationMapping::sole();
        $warehouse = StockTestFactory::warehouse();
        $unit = Unit::create(['code' => 'SET-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $item = StockTestFactory::averageItem(['base_unit_id' => $unit->id]);
        $supplier = Supplier::create(['code' => 'SET-SUP', 'name' => 'Synthetic supplier', 'is_active' => true]);
        foreach (['inventory_asset' => 801, 'grni' => 802] as $role => $id) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'name' => $role, 'type' => $role === 'grni' ? 'liability' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $account = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $pairs['account_role'][] = [$account->id, $id];
        }
        $pairs += ['item' => [[$item->id, 901]], 'unit' => [[$unit->id, 902]], 'supplier' => [[$supplier->id, 903]]];
        foreach ($pairs as $type => $entries) {
            foreach ($entries as [$native, $external]) {
                IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $mapping->mapping_uuid,
                    'central_client_id' => self::CLIENT, 'central_organization_id' => $org, 'finance_organization_id' => 14,
                    'solastock_organization_id' => $org, 'entity_type' => $type, 'solastock_record_id' => (string) $native,
                    'solabooks_record_id' => (string) $external, 'status' => 'verified']);
            }
        }
        IntegrationSetting::sole()->update(['meta' => ['client_id' => self::CLIENT, 'central_organization_id' => $org,
            'signing_key_id' => 'fixture', 'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'],
                'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $item->id, 'entered_unit_id' => $unit->id, 'received_qty' => '10', 'accepted_qty' => '10', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        $lifecycle = IntegrationDocumentLifecycleMapping::query()->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $receipt->id)->sole();

        $line=$receipt->lines()->sole();$uuid=(string)Str::uuid();
        $payload=['organization_mapping_uuid'=>$mapping->mapping_uuid,'finance_organization_id'=>14,'stock_receipt_id'=>$receipt->id,'source_bill_id'=>990,'bill_journal_id'=>991,'finance_receipt_id'=>992,'receipt_mapping_uuid'=>$lifecycle->mapping_uuid,'actor_id'=>self::ACTOR,'operation_uuid'=>$uuid,'return_date'=>'2026-10-07','reason'=>'Synthetic signed return request',
        'source_lines'=>[['source_receipt_line_id'=>$line->id,'bill_line_id'=>993,'confirmed_entered_quantity'=>'10']],
        'lines'=>[['source_receipt_line_id'=>$line->id,'bill_line_id'=>993,'source_stock_ledger_id'=>StockLedger::where('source_type',GoodsReceipt::class)->where('source_id',$receipt->id)->sole()->id,'entered_quantity'=>'2']]];
        // Synthetic persisted Finance projection, never a production Finance JE claim.
        DB::connection('tenant')->table('bills')->insert(['id'=>990,'organization_id'=>14,'supplier_id'=>903,'status'=>'posted','journal_entry_id'=>991]);
        DB::connection('tenant')->table('journal_entries')->insert(['id'=>991,'organization_id'=>14,'source'=>'AP','source_type'=>'App\\Models\\Bill','source_id'=>990,'source_key'=>'synthetic-signed-return-bill','status'=>'posted','posted_at'=>now()]);
        $json=json_encode($payload,JSON_THROW_ON_ERROR);
        DB::connection('tenant')->table('finance_supplier_return_requests')->insert(['organization_id'=>14,'organization_mapping_uuid'=>$mapping->mapping_uuid,'operation_uuid'=>$uuid,'bill_id'=>990,'bill_journal_id'=>991,'finance_receipt_id'=>992,'stock_receipt_id'=>$receipt->id,'receipt_mapping_uuid'=>$lifecycle->mapping_uuid,'actor_id'=>self::ACTOR,'payload_hash'=>hash('sha256',$json),'payload'=>$json]);
        $transport=$this->createMock(SolaBooksOutboxDeliveryService::class);
        $transport->method('authorizeSupplierReturnRequest')->willReturnCallback(fn($data,$action,$actor)=>['allowed'=>true,'contract'=>'purchasing.return_request.v1','actor_id'=>$actor,'request_actor_id'=>self::ACTOR,'operation'=>$action,'canonical_payload'=>$payload]);
        $this->app->instance(SolaBooksOutboxDeliveryService::class,$transport);
        return [$receipt,$payload];
    }
    private function envelope(array $payload,string $action):array {
        return ['action'=>'purchasing.return_request.'.$action,'authority_kind'=>'supplier_return_request','idempotency_key'=>'return-request-'.$payload['operation_uuid'].'-'.$action,'data'=>['operation_uuid'=>$payload['operation_uuid'],'arrival_confirmed'=>true]];
    }
    public function test_signed_nonphysical_request_reuses_one_intent_without_a_return_draft_or_movement():void {
        [$receipt,$p]=$this->source();$before=StockLedger::count();$journals=IntegrationOutboxEvent::count();
        $project=DB::connection('mysql')->table('projects')->where('slug','inventory')->value('id');DB::connection('mysql')->table('user_projects')->where('user_id',self::ACTOR)->where('project_id',$project)->update(['is_active'=>false]);
        $this->send($this->envelope($p,'create'))->assertOk()->assertJsonPath('data.state','requested');
        $this->send($this->envelope($p,'status'))->assertOk()->assertJsonPath('data.state','requested');
        $this->assertSame(1,DB::connection('tenant')->table('supplier_return_requests')->count());$this->assertSame(0,SupplierReturn::count());$this->assertSame($before,StockLedger::count());$this->assertSame($journals,IntegrationOutboxEvent::count());
    }
    public function test_signed_physical_post_requires_current_stock_admission_before_draft_creation():void {
        [, $p]=$this->source();$this->send($this->envelope($p,'create'))->assertOk();
        $project=DB::connection('mysql')->table('projects')->where('slug','inventory')->value('id');DB::connection('mysql')->table('user_projects')->where('user_id',self::ACTOR)->where('project_id',$project)->update(['is_active'=>false]);
        $before=StockLedger::count();$this->send($this->envelope($p,'post'))->assertForbidden();$this->assertSame(0,SupplierReturn::count());$this->assertSame($before,StockLedger::count());
    }
    public function test_signed_foreign_organization_is_denied_without_return_intent_or_movement():void {
        [, $p]=$this->source();$before=StockLedger::count();$this->send($this->envelope($p,'create')+['organization_id'=>TenantTestManager::ORG_B])->assertForbidden();$this->assertSame(0,DB::connection('tenant')->table('supplier_return_requests')->count());$this->assertSame($before,StockLedger::count());
    }
    public function test_failed_physical_post_retains_one_committed_draft_identity_on_replay():void {
        [, $p]=$this->source();$this->send($this->envelope($p,'create'))->assertOk();$before=StockLedger::count();
        // Current connected accounting capability remains deliberately unavailable; this is a real native post rejection, not fabricated network success.
        $this->send($this->envelope($p,'post'))->assertStatus(422);
        $row=DB::connection('tenant')->table('supplier_return_requests')->sole();$this->assertSame('physical_pending',$row->state);$this->assertNotNull($row->supplier_return_id);$id=$row->supplier_return_id;
        $this->send($this->envelope($p,'post'))->assertStatus(422);
        $this->assertSame($id,DB::connection('tenant')->table('supplier_return_requests')->sole()->supplier_return_id);$this->assertSame(1,SupplierReturn::count());$this->assertSame('draft',SupplierReturn::sole()->status);$this->assertSame($before,StockLedger::count());
    }

}
