<?php
namespace Tests\Feature\Stock;

use App\Models\Tenant\{GoodsReceipt, IntegrationOutboxEvent};
use App\Services\Stock\Historical\HistoricalFifoSourceOwnership;
use App\Services\Stock\{StockLedgerService, StockMovement};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StockTestFactory as F;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class HistoricalFifoSourceOwnershipTest extends TestCase
{
    use TenantAware;
    public function test_native_posted_source_binding_allows_exact_parent_and_rejects_wrong_document_or_line(): void
    {
        $this->useTenantA(); $item = F::fifoItem(); $wh = F::warehouse();
        $org = (int) $item->organization_id;
        $row = app(StockLedgerService::class)->post([new StockMovement('in', $item->id, $wh->id, '5', GoodsReceipt::class, 11,
            sourceLineId: 21, unitCost: '2', movedAt: '2024-01-02 00:00:00')], 'native-ownership-proof')[0];
        $key = 'solabooks:grn.posted:GoodsReceipt:11';
        IntegrationOutboxEvent::query()->create(['organization_id' => $org, 'event_uuid' => (string) Str::uuid(), 'integration' => 'solabooks',
            'event_type' => 'grn.posted', 'aggregate_type' => 'GoodsReceipt', 'aggregate_id' => 11, 'aggregate_number' => 'GRN-11',
            'occurred_at' => now(), 'payload' => [], 'status' => 'sent', 'mapping_status' => 'complete', 'attempts' => 1, 'idempotency_key' => $key]);
        // Fixture rows only: production remains exclusively native-service owned.
        DB::connection('tenant')->table('integration_financial_line_allocations')->insert([
            'allocation_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 990010,
            'central_organization_id' => $org, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(),
            'finance_organization_id' => 1, 'solastock_organization_id' => $org, 'source_document_mapping_uuid' => (string) Str::uuid(),
            'source_document_type' => 'goods_receipt', 'source_document_id' => '11', 'source_line_id' => 21,
            'destination_document_type' => 'supplier_bill', 'destination_document_id' => 31, 'destination_line_id' => 41,
            'allocation_kind' => 'bill', 'entered_quantity' => '5', 'base_quantity' => '5', 'destination_quantity' => '5',
            'source_unit_price' => '2', 'destination_unit_price' => '2', 'source_gross' => '10', 'destination_gross' => '10',
            'source_net' => '10', 'destination_net' => '10', 'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'exchange_rate' => '1',
            'state' => 'posted', 'destination_revision' => '1', 'source_fingerprint' => str_repeat('a',64),
            'destination_fingerprint' => str_repeat('b',64), 'idempotency_key' => 'native-fixture-allocation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = ['finance_document_id' => 31, 'finance_document_type' => 'bill', 'finance_source_id' => 'bill:31', 'finance_line_id' => 41,
            'finance_line_ids' => [41], 'stock_item_id' => $item->id, 'warehouse_id' => $wh->id, 'previous_stock_source_keys' => [$key]];
        $proof = app(HistoricalFifoSourceOwnership::class);
        $proof->assert($event, $row, $org, '5'); $this->addToAssertionCount(1);
        foreach ([['finance_document_id' => 32], ['finance_line_ids' => [42]]] as $wrong) {
            try { $proof->assert(array_merge($event, $wrong), $row, $org, '5'); $this->fail('Wrong native parent/line accepted'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('binding unproven', $e->getMessage()); }
        }
    }
}
