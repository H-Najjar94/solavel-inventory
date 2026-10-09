<?php

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\TenantAware;

/**
 * Batch 10: the open-order claim's locking reads (OpenOrderClaims::assertClaimable / holder,
 * FulfillmentRequestService shipment hand-off) must range-lock only the order's or the
 * customer's request rows, not every request of the organization.
 */
final class OpenOrderClaimIndexesTest extends TestCase
{
    use TenantAware;

    private const MIGRATION = 'migrations/tenant/2026_10_09_100000_add_sales_fulfillment_request_claim_indexes.php';

    public function test_claim_lock_indexes_are_additive_idempotent_and_match_the_locking_queries(): void
    {
        $this->useTenantA();
        // MariaDB DDL commits implicitly; end the fixture transaction before running the migration.
        while (DB::connection('tenant')->transactionLevel() > 0) {
            DB::connection('tenant')->rollBack();
        }
        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame(['organization_id', 'sales_order_id'], $this->columns('sfr_org_order_index'));
        $this->assertSame(['organization_id', 'customer_id', 'sales_order_id'], $this->columns('sfr_org_customer_order_index'));
        // Existing uniqueness is untouched.
        $this->assertSame(['organization_id', 'source_invoice_id'], $this->columns('sfr_org_invoice_unique'));
        $this->assertSame(['organization_id', 'request_uuid'], $this->columns('sfr_org_uuid_unique'));
    }

    public function test_locking_claim_queries_filter_on_a_prefix_of_the_new_indexes(): void
    {
        $source = file_get_contents(app_path('Services/Sales/OpenOrderClaims.php'));
        // assertClaimable: organization + order.
        $this->assertMatchesRegularExpression("/where\('organization_id',\\\$order->organization_id\)->where\('sales_order_id',\\\$order->id\).{0,120}lockForUpdate/s", $source);
        // holder(): organization + customer + sales_order_id IS NULL.
        $this->assertMatchesRegularExpression("/where\('organization_id',\\\$order->organization_id\)->where\('customer_id',\\\$order->customer_id\)\s*->whereNull\('sales_order_id'\)/s", $source);
        $this->assertTrue(Str::contains(file_get_contents(database_path(self::MIGRATION)), "['organization_id','customer_id','sales_order_id']"));
    }

    /** @return list<string> */
    private function columns(string $index): array
    {
        $connection = DB::connection('tenant');

        return $connection->table('information_schema.statistics')
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', 'sales_fulfillment_requests')
            ->where('index_name', $index)
            ->orderBy('seq_in_index')
            ->selectRaw('column_name as indexed_column')
            ->get()
            ->map(fn ($row) => (string) $row->indexed_column)
            ->all();
    }
}
