<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockLedger;
use App\Services\Integration\AccountingJournalBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

class AccountingJournalBuilderTest extends TestCase
{
    use TenantAware;

    private function mappings(): void
    {
        foreach (['inventory_asset' => 100, 'grni' => 200, 'accounts_receivable' => 300, 'sales_revenue' => 400, 'cogs' => 500] as $type => $id) {
            IntegrationAccountMapping::query()->create([
                'mapping_type' => $type, 'integration' => 'solabooks',
                'solabooks_account_id' => (string) $id, 'status' => 'mapped',
            ]);
        }
        IntegrationTaxMapping::query()->create([
            'integration' => 'solabooks', 'tax_code' => 'STD16', 'treatment' => 'standard',
            'solabooks_tax_id' => 7, 'solabooks_tax_code' => 'VAT_STD_B',
            'input_tax_account_id' => 647, 'output_tax_account_id' => 648, 'status' => 'mapped',
        ]);
    }

    private function event(string $type, string $aggregate, int $id, string $number): IntegrationOutboxEvent
    {
        return IntegrationOutboxEvent::query()->create([
            'event_uuid' => fake()->uuid(), 'integration' => 'solabooks', 'event_type' => $type,
            'aggregate_type' => $aggregate, 'aggregate_id' => $id, 'aggregate_number' => $number,
            'occurred_at' => now(), 'payload' => ['document_date' => now()->toDateString()],
            'status' => 'pending', 'mapping_status' => 'complete', 'attempts' => 0,
            'idempotency_key' => "solabooks:{$type}:{$aggregate}:{$id}",
        ]);
    }

    #[Test]
    public function grn_journal_includes_inventory_input_vat_and_grni(): void
    {
        $this->useTenantA();
        $this->mappings();
        $warehouse = F::warehouse();
        $item = F::item();
        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-ACC', 'order_date' => now(), 'warehouse_id' => $warehouse->id,
            'status' => 'approved', 'subtotal' => 100, 'tax_total' => 16, 'total' => 116,
        ]);
        $poLine = $po->lines()->create([
            'item_id' => $item->id, 'ordered_qty' => 10, 'received_qty' => 0, 'unit_price' => 10,
            'tax_code' => 'STD16', 'tax_treatment' => 'standard', 'tax_rate' => 16, 'tax_amount' => 16, 'line_total' => 116,
        ]);
        $grn = GoodsReceipt::query()->create([
            'grn_number' => 'GRN-ACC', 'receipt_date' => now(), 'warehouse_id' => $warehouse->id, 'status' => 'posted',
        ]);
        $line = $grn->lines()->create([
            'purchase_order_line_id' => $poLine->id, 'item_id' => $item->id,
            'received_qty' => 5, 'accepted_qty' => 5, 'rejected_qty' => 0, 'unit_cost' => 10,
        ]);
        StockLedger::query()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'in',
            'quantity' => 5, 'unit_cost' => 10, 'total_cost' => 50, 'costing_method' => 'average',
            'source_type' => GoodsReceipt::class, 'source_id' => $grn->id, 'source_line_id' => $line->id,
            'moved_at' => now(), 'posted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $lines = app(AccountingJournalBuilder::class)->build($this->event('grn.posted', 'GoodsReceipt', $grn->id, 'GRN-ACC'), TenantTestManager::ORG_A);

        $this->assertSame([100, 647, 200], array_column($lines, 'account_id'));
        $this->assertSame(['50.00', '8.00', '0.00'], array_column($lines, 'debit'));
        $this->assertSame('58.00', $lines[2]['credit']);
        $this->assertSame(7, $lines[1]['tax_rate_id']);
    }

    #[Test]
    public function shipment_journal_separates_revenue_output_vat_and_fifo_cogs(): void
    {
        $this->useTenantA();
        $this->mappings();
        $warehouse = F::warehouse();
        $item = F::fifoItem();
        $order = SalesOrder::query()->create([
            'order_number' => 'SO-ACC', 'order_date' => now(), 'warehouse_id' => $warehouse->id,
            'status' => 'confirmed', 'subtotal' => 100, 'discount_total' => 10, 'tax_total' => 14.40, 'total' => 104.40,
        ]);
        $orderLine = $order->lines()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'ordered_qty' => 10,
            'unit_price' => 10, 'discount_rate' => 10, 'discount_amount' => 10,
            'tax_code' => 'STD16', 'tax_treatment' => 'standard', 'tax_rate' => 16,
            'tax_amount' => 14.40, 'line_total' => 104.40, 'status' => 'open',
        ]);
        $shipment = Shipment::query()->create([
            'shipment_number' => 'SHIP-ACC', 'sales_order_id' => $order->id,
            'ship_date' => now(), 'warehouse_id' => $warehouse->id, 'status' => 'posted',
        ]);
        $line = $shipment->lines()->create([
            'sales_order_line_id' => $orderLine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 5,
        ]);
        StockLedger::query()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'out',
            'quantity' => 5, 'unit_cost' => 6, 'total_cost' => 30, 'costing_method' => 'fifo',
            'source_type' => Shipment::class, 'source_id' => $shipment->id, 'source_line_id' => $line->id,
            'moved_at' => now(), 'posted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $lines = app(AccountingJournalBuilder::class)->build($this->event('shipment.posted', 'Shipment', $shipment->id, 'SHIP-ACC'), TenantTestManager::ORG_A);

        $this->assertSame([300, 400, 648, 500, 100], array_column($lines, 'account_id'));
        $this->assertSame('52.20', $lines[0]['debit']);
        $this->assertSame('45.00', $lines[1]['credit']);
        $this->assertSame('7.20', $lines[2]['credit']);
        $this->assertSame('30.00', $lines[3]['debit']);
        $this->assertSame('30.00', $lines[4]['credit']);
    }

    #[Test]
    public function direct_shipment_posts_cogs_and_inventory_without_revenue_lines(): void
    {
        $this->useTenantA();
        $this->mappings();
        $warehouse = F::warehouse();
        $item = F::fifoItem();
        $order = SalesOrder::query()->create([
            'order_number' => 'SO-DIRECT', 'order_date' => now(), 'warehouse_id' => $warehouse->id,
            'status' => 'confirmed', 'subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_number' => 'SHIP-DIRECT', 'sales_order_id' => $order->id, 'ship_date' => now(),
            'warehouse_id' => $warehouse->id, 'status' => 'posted',
        ]);
        $line = $shipment->lines()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => 2,
        ]);
        StockLedger::query()->create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'direction' => 'out',
            'quantity' => 2, 'unit_cost' => 6, 'total_cost' => 12, 'costing_method' => 'fifo',
            'source_type' => Shipment::class, 'source_id' => $shipment->id, 'source_line_id' => $line->id,
            'moved_at' => now(), 'posted_at' => now(), 'idempotency_key' => fake()->uuid(),
        ]);

        $lines = app(AccountingJournalBuilder::class)->build(
            $this->event('shipment.posted', 'Shipment', $shipment->id, 'SHIP-DIRECT'),
            TenantTestManager::ORG_A
        );

        $this->assertSame([500, 100], array_column($lines, 'account_id'));
        $this->assertSame(['12.00', '0.00'], array_column($lines, 'debit'));
        $this->assertSame(['0.00', '12.00'], array_column($lines, 'credit'));
    }
}
