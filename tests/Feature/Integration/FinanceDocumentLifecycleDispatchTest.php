<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\{GoodsReceipt, IntegrationDocumentLifecycleMapping, IntegrationFinancialLineAllocation, IntegrationOrganizationMapping, IntegrationSetting, InventoryAuditLog, Unit};
use App\Services\Access\{CentralAppAccess, InventoryPermissionService};
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Integration\FinancialLineAllocationService;
use App\Services\InventoryWorkspace\{FinanceDocumentLifecycleAuthority, WorkspaceDispatcher};
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
 * Database-backed: a SolaCount-only accountant completes and reverses a linked bill that a
 * SolaStock-authorized reviewer reserved. Effects happen once, are attributed to the accountant,
 * and nothing outside the closed follow-through scope opens.
 */
final class FinanceDocumentLifecycleDispatchTest extends TestCase
{
    use TenantAware;

    private const ACCOUNTANT = 4242;
    private IntegrationSetting $setting;
    private IntegrationOrganizationMapping $connection;
    private GoodsReceipt $receipt;
    private IntegrationDocumentLifecycleMapping $source;
    private int $lineId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        $unit = Unit::query()->create(['code' => 'CASE-ALLOC', 'name' => 'Case', 'symbol' => 'cs']);
        $baseUnit = Unit::query()->create(['code' => 'EACH-ALLOC', 'name' => 'Each', 'symbol' => 'ea']);
        $item = F::item(['sku' => 'ALLOC-ITEM', 'base_unit_id' => $baseUnit->id]);
        $warehouse = F::warehouse(['code' => 'ALLOC-WH']);
        $this->receipt = GoodsReceipt::query()->create([
            'grn_number' => 'ALLOC-GRN-1', 'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-09', 'status' => 'posted', 'posted_at' => now(),
        ]);
        $line = $this->receipt->lines()->create([
            'item_id' => $item->id, 'received_qty' => '10', 'accepted_qty' => '10',
            'entered_qty' => '2', 'entered_unit_id' => $unit->id, 'base_unit_id' => $baseUnit->id,
            'unit_conversion_factor' => '5', 'unit_conversion_version' => 'v1',
            'unit_conversion_hash' => hash('sha256', 'case-to-base-5'),
            'unit_conversion_precision' => 4, 'unit_conversion_rounding_mode' => 'HALF_UP',
            'unit_cost' => '4',
        ]);
        $this->lineId = (int) $line->id;
        $this->connection = IntegrationOrganizationMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 77,
            'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'integration' => 'solabooks', 'contract_version' => 'solastock-journal.v2',
            'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD',
        ]);
        $this->source = IntegrationDocumentLifecycleMapping::query()->create([
            'mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->connection->mapping_uuid,
            'central_client_id' => 77, 'central_organization_id' => TenantTestManager::ORG_A,
            'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 701, 'solastock_organization_id' => TenantTestManager::ORG_A,
            'source_application' => 'solastock', 'source_document_type' => 'goods_receipt',
            'source_document_id' => (string) $this->receipt->id, 'lifecycle_status' => 'posted',
            'received_qty' => '10', 'transaction_currency_code' => 'JOD', 'base_currency_code' => 'JOD',
            'exchange_rate' => '1', 'accounting_source_key' => 'grn:alloc:1',
        ]);
    }


    private function arrange(bool $financeAccess = true): void
    {
        config(['inventory.demo_tenant.enabled' => false]);
        config(['integration_safety.phase6a_uat' => ['enabled' => true, 'organization_id' => TenantTestManager::ORG_A,
            'tenant_database' => DB::connection('tenant')->getDatabaseName()]]);
        config(['database.connections.tenant.database' => DB::connection('tenant')->getDatabaseName()]);
        $this->setting = IntegrationSetting::query()->firstOrCreate(['organization_id' => TenantTestManager::ORG_A, 'integration' => 'solabooks'], ['mode' => 'active', 'solabooks_organization_id' => 701]);
        $this->setting->forceFill(['mode' => 'active'])->save();
        $central = \Mockery::mock(CentralAppAccess::class);
        $central->shouldReceive('decision')->with(self::ACCOUNTANT, TenantTestManager::ORG_A, 'finance')->andReturn($financeAccess ? ['allowed' => true, 'roles' => ['accountant']] : ['allowed' => false, 'reason' => 'user_not_assigned_to_project']);
        $central->shouldReceive('decision')->with(self::ACCOUNTANT, TenantTestManager::ORG_A, 'inventory')->andReturn(['allowed' => false, 'reason' => 'user_not_assigned_to_project']);
        $this->app->instance(CentralAppAccess::class, $central);
        $commercial = \Mockery::mock(InventoryCommercialEntitlementService::class);
        $commercial->shouldReceive('checkPermission', 'checkFeature')->andReturn(['allowed' => true, 'reason_code' => null]);
        $this->app->instance(InventoryCommercialEntitlementService::class, $commercial);
        // The reviewer with SolaStock authority reserved 6 of 10 for bill 9001 before the accountant acts.
        \Illuminate\Support\Facades\Auth::forgetUser();
        app(FinancialLineAllocationService::class)->reserve($this->payload(9001, 11, '1.2', '6', '6', '5', '30', '2', '1', '27'));
    }

    /** @return array{0:int,1:array|string,2:bool} */
    private function send(string $action, array $data, string $key): array
    {
        $request = Request::create('/api/internal/finance-workspace', 'POST');
        $request->setUserResolver(fn () => (object) ['id' => self::ACCOUNTANT]);
        \Illuminate\Support\Facades\Auth::setUser((new \App\Models\User)->forceFill(['id' => self::ACCOUNTANT]));
        try {
            $response = app(WorkspaceDispatcher::class)->dispatch($request, ['action' => $action, 'idempotency_key' => $key, 'data' => $data], $this->connection, $this->setting);
            return [$response->getStatusCode(), $response->getData(true), $response->headers->get('X-Workspace-Replayed') === 'true'];
        } catch (HttpException $e) { return [$e->getStatusCode(), $e->getMessage(), false]; }
        catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { return [$e->getResponse()->getStatusCode(), json_decode($e->getResponse()->getContent(), true), false]; }
        catch (\Illuminate\Validation\ValidationException $e) { return [422, $e->errors(), false]; }
    }

    private function document(int $id = 9001, string $fingerprint = 'a'): array
    {
        return ['destination_document_type' => 'supplier_bill', 'destination_document_id' => $id, 'destination_fingerprint' => str_repeat($fingerprint, 64)];
    }

    #[Test]
    public function finance_only_accountant_commits_once_replays_safely_and_is_the_audited_actor(): void
    {
        $this->arrange();
        $key = 'financial-allocation:commit:'.hash('sha256', 'bill-9001');
        [$status, $body, $replayed] = $this->send('finance-allocations.commit', $this->document(), $key);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertFalse($replayed);
        $row = IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->firstOrFail();
        $this->assertSame('posted', $row->state);
        $this->assertSame(self::ACCOUNTANT, (int) $row->updated_by_user_id);
        $postedAt = (string) $row->posted_at;

        // Lost acknowledgement: SolaCount retries with the same identity. Stock replays its receipt.
        [$status, $again, $replayed] = $this->send('finance-allocations.commit', $this->document(), $key);
        $this->assertSame(200, $status);
        $this->assertTrue($replayed);
        $this->assertSame($body, $again);
        $this->assertSame($postedAt, (string) $row->fresh()->posted_at);
        $this->assertSame(1, IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->count());
        $receipts = InventoryAuditLog::query()->where('entity_type', 'finance_workspace_command')->where('action', 'finance-allocations.commit')->get();
        $this->assertCount(1, $receipts);
        $this->assertSame(self::ACCOUNTANT, (int) $receipts[0]->actor_user_id);
        $this->assertSame(FinanceDocumentLifecycleAuthority::SCOPE, $receipts[0]->after['authorization_scope']);

        // The same key can never carry a different document.
        [$status, $message] = $this->send('finance-allocations.commit', $this->document(9002), $key);
        $this->assertSame([409, 'workspace_idempotency_conflict'], [$status, $message]);
    }

    #[Test]
    public function source_document_validation_still_binds_the_scope_to_the_reviewed_document(): void
    {
        $this->arrange();
        foreach ([$this->document(9001, 'b'), $this->document(7777)] as $index => $foreign) {
            [$status] = $this->send('finance-allocations.commit', $foreign, str_repeat((string) $index, 32));
            $this->assertSame(422, $status);
        }
        $this->assertSame('draft_reserved', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
        $this->assertSame(0, InventoryAuditLog::query()->where('entity_type', 'finance_workspace_command')->count(), 'A rejected effect leaves no success receipt.');
    }

    #[Test]
    public function reversal_and_release_follow_the_lifecycle_once(): void
    {
        $this->arrange();
        $this->assertSame(200, $this->send('finance-allocations.commit', $this->document(), str_repeat('c', 32))[0]);
        $this->assertSame(422, $this->send('finance-allocations.release', $this->document(), str_repeat('r', 32))[0], 'A posted allocation cannot be released.');
        $this->assertSame(200, $this->send('finance-allocations.reverse', $this->document(), str_repeat('v', 32))[0]);
        [$status, , $replayed] = $this->send('finance-allocations.reverse', $this->document(), str_repeat('v', 32));
        $this->assertSame([200, true], [$status, $replayed]);
        $this->assertSame('reversed', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
        // Reversal returns the capacity: the full source quantity is reservable again by an authorized reviewer.
        \Illuminate\Support\Facades\Auth::forgetUser();
        $again = app(FinancialLineAllocationService::class)->reserve($this->payload(9005, 15, '2', '10', '10', '4', '40', '0', '0', '40'));
        $this->assertSame('10.00000000', $again['allocations'][0]['base_quantity']);
    }

    #[Test]
    public function accountant_cannot_reserve_review_or_touch_stock_and_loses_the_scope_with_solacount_access(): void
    {
        $this->arrange();
        foreach (['finance-allocations.reserve' => $this->payload(9010, 31, '0.2', '1', '1', '4', '4', '0', '0', '4'), 'items.index' => [], 'finance-sources.receipts' => []] as $action => $data) {
            $this->assertSame([403, 'workspace_permission_required'], array_slice($this->send($action, $data, str_repeat('x', 32)), 0, 2), $action);
        }
        $this->assertSame(0, IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9010)->count());
        $this->arrange(false);
        $this->assertSame([403, 'workspace_finance_access_required'], array_slice($this->send('finance-allocations.commit', $this->document(), str_repeat('z', 32)), 0, 2));
        $this->assertSame('draft_reserved', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
    }

    #[Test]
    public function accountant_applies_and_reverses_the_reviewed_purchase_cost_plan_exactly_once(): void
    {
        $this->arrange();
        \Illuminate\Support\Facades\Auth::forgetUser();
        $line = $this->receipt->lines()->firstOrFail();
        app(\App\Services\Stock\StockLedgerService::class)->post([new \App\Services\Stock\StockMovement(direction: 'in', itemId: (int) $line->item_id,
            warehouseId: (int) $this->receipt->warehouse_id, quantity: '10', sourceType: GoodsReceipt::class, sourceId: (int) $this->receipt->id,
            sourceLineId: (int) $line->id, unitCost: '4', movedAt: '2026-09-09 09:00:00')], 'test:lifecycle-cost:receipt');
        app(FinancialLineAllocationService::class)->reserve($this->payload(9300, 41, '0.8', '4', '4', '5', '20', '0', '0', '20'));
        $plan = ['organization_mapping_uuid' => $this->connection->mapping_uuid, 'destination_document_id' => 9300, 'destination_fingerprint' => str_repeat('a', 64)];
        app(\App\Services\Stock\PurchaseCostAdjustmentService::class)->prepare($plan + ['currency_code' => 'JOD', 'base_currency_code' => 'JOD',
            'exchange_rate' => '1', 'finance_money_scale' => 3, 'discount_posting_mode' => 'net']);
        $value = fn (): string => (string) \App\Models\Tenant\StockBalance::query()->withoutGlobalScopes()->where('item_id', $line->item_id)->value('total_value');
        $this->assertSame('40.00', $value());

        // The accountant has no warehouse assignment at all; the reviewed plan, not the actor, fixes the rows.
        $this->assertSame([403, 'workspace_permission_required'], array_slice($this->send('finance-allocations.cost-adjustment.prepare', $plan, str_repeat('q', 32)), 0, 2));
        [$status, $body] = $this->send('finance-allocations.cost-adjustment.apply', $plan, str_repeat('a', 32));
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('44.00', $value());
        [$status, , $replayed] = $this->send('finance-allocations.cost-adjustment.apply', $plan, str_repeat('a', 32));
        $this->assertSame([200, true], [$status, $replayed]);
        // Even a fresh key cannot apply the same plan twice: the adjustment state machine is idempotent.
        $this->assertSame(200, $this->send('finance-allocations.cost-adjustment.apply', $plan, str_repeat('b', 32))[0]);
        $this->assertSame('44.00', $value());
        $this->assertSame(200, $this->send('finance-allocations.cost-adjustment.reverse', $plan, str_repeat('e', 32))[0]);
        $this->assertSame('40.00', $value());
        \Illuminate\Support\Facades\Auth::forgetUser();
        $this->assertTrue(app(\App\Services\Stock\IntegrityChecker::class)->check('tenant', TenantTestManager::ORG_A)['ok']);
    }

    #[Test]
    public function held_or_paused_connection_blocks_the_effect_for_everyone(): void
    {
        $this->arrange();
        $this->setting->forceFill(['mode' => 'paused'])->save();
        $this->assertSame([409, 'workspace_connection_read_only'], array_slice($this->send('finance-allocations.commit', $this->document(), str_repeat('p', 32)), 0, 2));
        $this->assertSame('draft_reserved', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
    }

    private function payload(int $documentId, int $lineId, string $entered, string $base, string $destinationQty,
        string $destinationPrice, string $gross, string $lineDiscount, string $documentDiscount, string $net, string $exchangeRate='1', string $currency='JOD'): array
    {
        return [
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => $documentId,
            'destination_revision' => str_repeat('a', 64), 'destination_fingerprint' => str_repeat('a', 64),
            'allocation_kind' => 'bill', 'currency_code' => $currency, 'base_currency_code' => 'JOD', 'exchange_rate' => $exchangeRate,
            'allocations' => [[
                'source_document_mapping_uuid' => $this->source->mapping_uuid,
                'source_document_type' => 'goods_receipt', 'source_document_id' => $this->receipt->id,
                'source_line_id' => $this->lineId, 'stock_item_id' => $this->receipt->lines()->firstOrFail()->item_id,
                'destination_line_id' => $lineId, 'entered_quantity' => $entered, 'base_quantity' => $base,
                'destination_quantity' => $destinationQty, 'destination_unit_id' => 801,
                'destination_unit_price' => $destinationPrice, 'destination_gross' => $gross,
                'line_discount_allocated' => $lineDiscount, 'document_discount_allocated' => $documentDiscount,
                'destination_net' => $net,
            ]],
        ];
    }
}
