<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Unit;
use App\Services\Integration\FinancialLineAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class FinancialLineAllocationServiceTest extends TestCase
{
    use TenantAware;

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

    #[Test]
    public function converted_partial_allocations_are_exact_idempotent_and_cannot_overconsume(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $first = $service->reserve($this->payload(9001, 11, '1.2', '6', '6', '5', '30', '2', '1', '27'));
        $again = $service->reserve($this->payload(9001, 11, '1.2', '6', '6', '5', '30', '2', '1', '27'));
        $this->assertSame($first['allocations'][0]['allocation_uuid'], $again['allocations'][0]['allocation_uuid']);
        $this->assertSame('6.00000000', $first['allocations'][0]['base_quantity']);
        $this->assertSame('6.00000000', $first['allocations'][0]['destination_quantity']);
        $this->assertSame('3.00000000', $first['allocations'][0]['price_difference']);
        $this->assertSame(1, IntegrationFinancialLineAllocation::query()->count());

        $second = $service->reserve($this->payload(9002, 12, '0.8', '4', '0.8', '25', '20', '0', '0', '20'));
        $this->assertSame('4.00000000', $second['allocations'][0]['base_quantity']);
        try {
            $service->reserve($this->payload(9003, 13, '0.2', '1', '1', '4', '4', '0', '0', '4'));
            $this->fail('Cumulative allocations must not exceed the source line.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations', $exception->errors());
        }
        $this->assertSame(2, IntegrationFinancialLineAllocation::query()->count());

        $service->transition([
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9001,
            'destination_fingerprint' => str_repeat('a', 64),
        ], 'posted');
        $service->transition([
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9001,
            'destination_fingerprint' => str_repeat('a', 64),
        ], 'posted');
        $this->assertSame('posted', IntegrationFinancialLineAllocation::query()->where('destination_document_id', 9001)->value('state'));
    }

    #[Test]
    public function a_changed_draft_selection_atomically_releases_the_old_reservation(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $old = $service->reserve($this->payload(9100, 21, '1.2', '6', '6', '4', '24', '0', '0', '24'));
        $new = $service->reserve($this->payload(9100, 21, '1', '5', '5', '4', '20', '0', '0', '20'));

        $this->assertNotSame($old['allocations'][0]['allocation_uuid'], $new['allocations'][0]['allocation_uuid']);
        $this->assertSame('released', IntegrationFinancialLineAllocation::query()->where('allocation_uuid', $old['allocations'][0]['allocation_uuid'])->value('state'));
        $this->assertSame('draft_reserved', IntegrationFinancialLineAllocation::query()->where('allocation_uuid', $new['allocations'][0]['allocation_uuid'])->value('state'));
        $this->assertSame('5.00000000', (string) IntegrationFinancialLineAllocation::query()->where('state', 'draft_reserved')->sum('base_quantity'));
    }

    #[Test]
    public function cancelling_a_draft_releases_capacity_idempotently_for_a_later_document(): void
    {
        $service = app(FinancialLineAllocationService::class);
        $reserved = $service->reserve($this->payload(9200, 31, '2', '10', '10', '4', '40', '0', '0', '40'));

        $transition = [
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 9200,
            'destination_fingerprint' => str_repeat('a', 64),
        ];
        $service->transition($transition, 'released');
        $service->transition($transition, 'released');
        $this->assertSame('released', IntegrationFinancialLineAllocation::query()
            ->where('allocation_uuid', $reserved['allocations'][0]['allocation_uuid'])->value('state'));

        $replacement = $service->reserve($this->payload(9201, 32, '2', '10', '10', '4', '40', '0', '0', '40'));
        $this->assertSame('draft_reserved', $replacement['allocations'][0]['state']);
        $this->assertSame('10.00000000', $replacement['allocations'][0]['base_quantity']);
    }

    private function payload(int $documentId, int $lineId, string $entered, string $base, string $destinationQty,
        string $destinationPrice, string $gross, string $lineDiscount, string $documentDiscount, string $net): array
    {
        return [
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => $documentId,
            'destination_revision' => str_repeat('a', 64), 'destination_fingerprint' => str_repeat('a', 64),
            'allocation_kind' => 'bill', 'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'exchange_rate' => '1',
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
