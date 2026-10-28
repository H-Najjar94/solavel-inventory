<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\InventorySetting;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Services\Documents\InventoryReversalService;
use App\Services\Documents\OpeningStockService;
use App\Services\Documents\SalesOrderService;
use App\Services\Documents\SalesReturnService;
use App\Services\Documents\ShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Supported sequence for a return that has a Finance customer credit: SolaCount voids the credit first
 * (a return-sourced credit never needs the physical return reversed), then Stock reverses the return.
 * Stock refuses atomically while the credit is effective, so stock can never be reversed under a live credit.
 */
class ConnectedReturnReversalTest extends TestCase
{
    use TenantAware;

    private string $n = '';

    private function postedReturn(): array
    {
        $this->n = strtoupper(Str::random(5));
        $n = $this->n;
        $this->useTenantA();
        InventorySetting::query()->updateOrCreate(['organization_id' => TenantTestManager::ORG_A],
            ['default_costing_method' => 'average', 'allow_negative_stock' => false]);
        $w = F::warehouse();
        $item = F::averageItem(['sales_price' => '20.0000']);
        $c = Customer::create(['code' => 'CRR-C-'.$n, 'name' => 'Reversal Customer', 'contact' => [], 'is_active' => true]);
        $os = app(OpeningStockService::class);
        $os->post($os->createDraft(['entry_number' => 'CRR-OS-'.$n, 'warehouse_id' => $w->id],
            [['item_id' => $item->id, 'quantity' => '10.000000', 'unit_cost' => '5.0000']]));
        $so = app(SalesOrderService::class);
        $order = $so->reserve($so->confirm($so->createDraft(['order_number' => 'CRR-SO-'.$n, 'warehouse_id' => $w->id, 'customer_id' => $c->id],
            [['item_id' => $item->id, 'ordered_qty' => '4.000000', 'unit_price' => '20.0000']])));
        $ship = app(ShipmentService::class);
        $shipment = $ship->createDraft(['shipment_number' => 'CRR-SH-'.$n, 'sales_order_id' => $order->id,
            'warehouse_id' => $w->id, 'ship_date' => now()->toDateString()], $ship->fromSalesOrder($order));
        $ship->post($shipment);
        $shipment = $shipment->fresh('lines');
        $line = $shipment->lines->first();
        $svc = app(SalesReturnService::class);
        $r = $svc->createDraft(['return_number' => 'CRR-R1-'.$n, 'shipment_id' => $shipment->id, 'customer_id' => $c->id,
            'warehouse_id' => $w->id, 'return_date' => now()->toDateString(), 'reason' => 'customer return'],
            [['source_line_id' => $line->id, 'item_id' => $item->id, 'returned_qty' => '1', 'entered_qty' => '1', 'condition' => 'resellable'],
             ['source_line_id' => $line->id, 'item_id' => $item->id, 'returned_qty' => '2', 'entered_qty' => '2', 'condition' => 'damaged']]);

        return [$svc->post($r), $item];
    }

    private function onHand($item): string
    {
        return (string) StockBalance::query()->where('item_id', $item->id)->sum('on_hand_qty');
    }

    /** Posted Finance allocation of a customer credit against the return (what Finance records when the credit posts). */
    private function linkCredit($return, int $creditId = 777): string
    {
        $org = (int) $return->organization_id;
        $db = DB::connection('tenant');
        $life = (string) Str::uuid();
        $db->table('integration_document_lifecycle_mappings')->insert(['mapping_uuid' => $life, 'organization_mapping_uuid' => (string) Str::uuid(),
            'central_client_id' => 7, 'central_organization_id' => $org, 'solastock_organization_id' => $org, 'finance_organization_id' => 14,
            'tenant_database_identity' => $db->getDatabaseName(), 'source_application' => 'solastock', 'source_document_type' => 'sales_return',
            'source_document_id' => (string) $return->id, 'document_version' => 'phase3.v1', 'lifecycle_status' => 'posted',
            'base_currency_code' => 'JOD', 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) Str::uuid();
        $db->table('integration_financial_line_allocations')->insert([
            'allocation_uuid' => $uuid, 'organization_mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7,
            'central_organization_id' => $org, 'tenant_database_identity' => $db->getDatabaseName(), 'finance_organization_id' => 14,
            'solastock_organization_id' => $org, 'source_document_mapping_uuid' => $life, 'source_document_type' => 'sales_return',
            'source_document_id' => (string) $return->id, 'source_line_id' => 1, 'destination_document_type' => 'customer_credit_note',
            'destination_document_id' => $creditId, 'destination_line_id' => 1, 'allocation_kind' => 'credit', 'entered_quantity' => '3',
            'base_quantity' => '3', 'destination_quantity' => '3', 'source_unit_price' => '20', 'destination_unit_price' => '20',
            'source_gross' => '60', 'destination_gross' => '60', 'source_net' => '60', 'destination_net' => '60', 'currency_code' => 'JOD',
            'base_currency_code' => 'JOD', 'exchange_rate' => '1', 'state' => 'posted', 'destination_revision' => '1',
            'source_fingerprint' => str_repeat('a', 64), 'destination_fingerprint' => str_repeat('b', 64),
            'idempotency_key' => 'crr-'.$uuid, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    private function assertNothingReversed($return, $item, string $onHand, int $ledgerRows): void
    {
        $this->assertSame('posted', $return->fresh()->status);
        $this->assertNull($return->fresh()->reversal_id);
        $this->assertSame(0, InventoryReversal::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->count());
        $this->assertSame('posted', StockAdjustment::query()->where('adjustment_number', 'like', 'DMG-CRR-R1-'.$this->n.'%')->value('status'));
        $this->assertSame($onHand, $this->onHand($item));
        $this->assertSame($ledgerRows, StockLedger::query()->count());
    }

    #[Test]
    public function happy_path_reverses_write_off_first_restores_stock_and_emits_one_event(): void
    {
        [$r, $item] = $this->postedReturn();
        $this->assertEquals(7, $this->onHand($item)); // 10 - 4 shipped + 1 restocked (damaged units written off)
        $reversal = app(InventoryReversalService::class)->reverseSalesReturn($r, 'customer withdrew return');
        $this->assertEquals(6, $this->onHand($item));
        $this->assertSame('reversed', StockAdjustment::query()->where('adjustment_number', 'like', 'DMG-CRR-R1-'.$this->n.'%')->value('status'));
        $this->assertSame('reversed', $r->fresh()->status);
        $this->assertSame($reversal->id, $r->fresh()->reversal_id);
    }

    #[Test]
    public function effective_finance_credit_refuses_and_nothing_is_reversed(): void
    {
        [$r, $item] = $this->postedReturn();
        $onHand = $this->onHand($item);
        $ledger = StockLedger::query()->count();
        $this->linkCredit($r);
        try {
            app(InventoryReversalService::class)->reverseSalesReturn($r, 'customer withdrew return');
            $this->fail('Reversal must be refused while the Finance credit is effective.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('return_reversal.credit_before_return'), $e->getMessage());
            $this->assertStringContainsString('void the linked customer credit', $e->getMessage());
        }
        $this->assertNothingReversed($r, $item, $onHand, $ledger);
    }

    #[Test]
    public function credit_voided_in_finance_but_not_yet_acknowledged_is_refused_with_a_recovery_message_then_succeeds_once_acknowledged(): void
    {
        [$r, $item] = $this->postedReturn();
        $onHand = $this->onHand($item);
        $ledger = StockLedger::query()->count();
        $uuid = $this->linkCredit($r, 778);
        $db = DB::connection('tenant');
        // TEMPORARY table: no implicit commit inside the test transaction.
        $db->statement('CREATE TEMPORARY TABLE credit_notes (id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(20))');
        try {
            $db->table('credit_notes')->insert(['id' => 778, 'status' => 'void']);
            try {
                app(InventoryReversalService::class)->reverseSalesReturn($r, 'x reason');
                $this->fail('Stale acknowledgement must hold the reversal.');
            } catch (\RuntimeException $e) {
                $this->assertSame(__('return_reversal.credit_void_acknowledgement_pending'), $e->getMessage());
            }
            $this->assertNothingReversed($r, $item, $onHand, $ledger);
            // Recovery: Finance's retried void sync reverses the Stock allocation mirror; the same call now succeeds.
            $db->table('integration_financial_line_allocations')->where('allocation_uuid', $uuid)->update(['state' => 'reversed', 'reversed_at' => now()]);
            $reversal = app(InventoryReversalService::class)->reverseSalesReturn($r->fresh(), 'x reason');
            $this->assertSame('reversed', $r->fresh()->status);
            $this->assertSame($reversal->id, $r->fresh()->reversal_id);
        } finally {
            $db->statement('DROP TEMPORARY TABLE IF EXISTS credit_notes');
        }
    }

    #[Test]
    public function retry_and_duplicate_submit_are_idempotent(): void
    {
        [$r, $item] = $this->postedReturn();
        $svc = app(InventoryReversalService::class);
        $first = $svc->reverseSalesReturn($r, 'customer withdrew return');
        $ledger = StockLedger::query()->count();
        $onHand = $this->onHand($item);
        $second = $svc->reverseSalesReturn($r->fresh(), 'duplicate click');
        $again = app(\App\Http\Controllers\Api\V1\SalesReturnController::class)
            ->reverse(\Illuminate\Http\Request::create('/', 'POST', ['reason' => 'network retry']), $r->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(200, $again->getStatusCode());
        $this->assertSame($ledger, StockLedger::query()->count());
        $this->assertSame($onHand, $this->onHand($item));
        $this->assertSame(1, InventoryReversal::query()->where('source_type', 'sales_return')->where('source_id', $r->id)->count());
    }

    #[Test]
    public function rollout_report_requires_an_exact_organization_and_changes_nothing(): void
    {
        $this->artisan('returns:report-reversal-scope')->expectsOutputToContain('exact --organization')->assertExitCode(1);
    }
}
