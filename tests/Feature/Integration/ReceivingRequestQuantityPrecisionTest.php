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
use Illuminate\Support\Facades\Validator;
use App\Rules\ExactDecimalString;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/** Receiving-request prefill must stay within the 6 dp quantity contract (no 18 dp bcmath padding). */
final class ReceivingRequestQuantityPrecisionTest extends TestCase
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
            'lines' => [['source_line_id' => '801', 'item_external_id' => 701, 'unit_external_id' => 702, 'quantity' => '10.0000', 'unit_cost' => '2.5000']]]);
    }

    public function test_receiving_request_status_quantities_fit_the_six_decimal_contract_and_validate(): void
    {
        $request = $this->request();
        $line = $request['lines'][0];
        $this->assertMatchesRegularExpression('/^\d+(\.\d{1,6})?$/', $line['remaining_qty']);
        $this->assertSame(0, bccomp($line['remaining_qty'], '10', 6));

        // Front-end prefill (normalizeQty) trims zeros; both forms must pass the unchanged store rule.
        $normalized = rtrim(rtrim($line['remaining_qty'], '0'), '.');
        $this->assertSame('10', $normalized);
        foreach ([$line['remaining_qty'], $normalized] as $qty) {
            $v = Validator::make(['lines' => [['received_qty' => $qty]]], ['lines.*.received_qty' => ['required', new ExactDecimalString(6), 'numeric', 'gt:0']]);
            $this->assertFalse($v->fails(), (string) $qty);
        }
        // Regression proof: the old 18 dp value is what the rule rejects.
        $old = Validator::make(['lines' => [['received_qty' => '10.000000000000000000']]], ['lines.*.received_qty' => [new ExactDecimalString(6)]]);
        $this->assertTrue($old->fails());

        // Partially received: remainder is also scale-limited.
        DB::connection('tenant')->table('purchasing_receiving_request_lines')->where('receiving_request_id', $request['id'])->update(['received_qty' => '3.333333']);
        $status = app(ReceivingRequestService::class)->status(ReceivingRequest::query()->findOrFail($request['id']));
        $this->assertSame('6.666667', $status['lines'][0]['remaining_qty']);
    }
}
