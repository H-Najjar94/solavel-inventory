<?php

namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Shared qualification boundary: configuration must never reinterpret historical activity. */
final class PristineStockHistory
{
    public static function assertEmpty(int $financeId, int $stockId): void
    {
        $finance = ['journal_entries','invoices','bills','payments','sales_receipts','inventory_items','stock_movements','inventory_stock_movements','opening_balances'];
        $stock = ['items','stock_ledger','stock_balances','opening_stock_entries','stock_adjustments','stock_counts','stock_transfers',
            'goods_receipts','shipments','sales_returns','inventory_purchase_orders','inventory_sales_orders','integration_events','integration_outbox_events'];
        foreach ([[$financeId,$finance],[$stockId,$stock]] as [$id,$tables]) {
            foreach ($tables as $table) {
                if (! Schema::connection('tenant')->hasTable($table)) continue;
                if (! Schema::connection('tenant')->hasColumn($table, 'organization_id')) {
                    throw new RuntimeException('unscoped_history_requires_review:'.$table);
                }
                if (DB::connection('tenant')->table($table)->where('organization_id',$id)->exists()) {
                    throw new RuntimeException('existing_activity_requires_review:'.$table);
                }
            }
        }
    }
}
