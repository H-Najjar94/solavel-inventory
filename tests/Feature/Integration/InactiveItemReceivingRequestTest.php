<?php

namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Purchasing\ReceivingRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Requirements 3 + 4 (owner 2026-10-18) on the Finance -> Stock receiving handoff (machine path): an open
 * receiving request blocks deactivation of its items; complete / cancelled requests and fully received lines
 * do not; a request is never created for an inactive item.
 */
final class InactiveItemReceivingRequestTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $mapping;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        DB::connection('tenant')->table('organizations')->insertOrIgnore(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A, 'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $this->mapping = IntegrationOrganizationMapping::create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(), 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
        IntegrationSetting::create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14, 'meta' => ['client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'signing_key_id' => 'test-key', 'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD'], 'currency_precisions' => ['JOD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        F::warehouse();
        $unit = Unit::create(['code' => 'Q12-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $category = \App\Models\Tenant\ItemCategory::query()->firstOrCreate(['name' => 'General'], ['level' => 0, 'is_active' => true]);
        $this->item = F::averageItem(['base_unit_id' => $unit->id, 'category_id' => $category->id]);
        $supplier = Supplier::create(['code' => 'Q12-SUP', 'name' => 'Supplier', 'is_active' => true]);
        $this->master('item', $this->item->id, 701);
        $this->master('unit', $unit->id, 702);
        $this->master('supplier', $supplier->id, 703);
        foreach (['inventory_asset' => 100, 'grni' => 200] as $role => $id) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'code' => (string) $id, 'name' => $role, 'type' => $role === 'grni' ? 'liability' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $a = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $this->master('account_role', $a->id, $id);
        }
    }

    private function master($type, $stock, $finance): void
    {
        IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid, 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'entity_type' => $type, 'solastock_record_id' => (string) $stock, 'solabooks_record_id' => (string) $finance, 'status' => 'verified']);
    }

    private function request(): array
    {
        return app(ReceivingRequestService::class)->upsert(['request_uuid' => (string) Str::uuid(), 'source_bill_id' => 800, 'source_bill_number' => 'DRAFT-800',
            'source_revision' => str_repeat('a', 64), 'supplier_external_id' => 703, 'currency_code' => 'JOD',
            'lines' => [['source_line_id' => '801', 'item_external_id' => 701, 'unit_external_id' => 702, 'quantity' => '4.0000', 'unit_cost' => '2.5000']]]);
    }

    /** @return array{0:int,1:list<string>} */
    private function deactivate(): array
    {
        $item = Item::query()->findOrFail($this->item->id);
        $req = UpdateItemRequest::create('/api/v1/items/'.$item->id, 'PUT', ['is_active' => false]);
        $req->setRouteResolver(fn () => new class($item) {
            public function __construct(private Item $item) {}
            public function parameter(string $key): mixed { return $key === 'item' ? $this->item : null; }
        });
        $req->setContainer(app())->setRedirector(app('redirect'));
        try {
            $req->validateResolved();

            return [app(ItemController::class)->update($req, $item)->getStatusCode(), []];
        } catch (ValidationException $e) {
            return [$e->status, $e->errors()['deactivation_blockers'] ?? []];
        }
    }

    public function test_open_receiving_request_blocks_deactivation_until_complete_or_cancelled(): void
    {
        $request = $this->request();
        $this->assertSame('pending', $request['status']);
        [$status, $blockers] = $this->deactivate();
        $this->assertSame(422, $status);
        $this->assertContains('open_purchasing_receiving_requests', $blockers);

        $rr = ReceivingRequest::query()->findOrFail($request['id']);
        DB::connection('tenant')->table('purchasing_receiving_request_lines')->where('receiving_request_id', $rr->id)->update(['received_qty' => '1']);
        $rr->forceFill(['status' => 'partial'])->save();
        $this->assertContains('open_purchasing_receiving_requests', $this->deactivate()[1], 'partial with remaining quantity is open');

        // A partial request whose line for this item is fully received no longer obliges this item.
        DB::connection('tenant')->table('purchasing_receiving_request_lines')->where('receiving_request_id', $rr->id)->update(['received_qty' => '4']);
        $this->assertSame(200, $this->deactivate()[0], 'partial request with this line fully received');
        DB::connection('tenant')->table('items')->where('id', $this->item->id)->update(['is_active' => true]);

        foreach (['complete', 'cancelled'] as $terminal) {
            DB::connection('tenant')->table('purchasing_receiving_request_lines')->where('receiving_request_id', $rr->id)->update(['received_qty' => '0']);
            $rr->forceFill(['status' => $terminal])->save();
            $this->assertSame(200, $this->deactivate()[0], $terminal);
            DB::connection('tenant')->table('items')->where('id', $this->item->id)->update(['is_active' => true]);
        }
    }

    public function test_no_receiving_request_is_created_for_an_inactive_item(): void
    {
        DB::connection('tenant')->table('items')->where('id', $this->item->id)->update(['is_active' => false]);
        try {
            $this->request();
            $this->fail('A receiving request was created for an inactive item.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertSame(0, ReceivingRequest::query()->count());
    }
}
