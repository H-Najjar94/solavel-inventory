<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantSchemaAuditService
{
    public function audit(?string $connection = null, ?array $requirements = null): array
    {
        $connection ??= config('tenancy.tenant_connection', 'tenant');
        $requirements ??= self::requirements();

        $missingTables = [];
        $missingColumns = [];
        $missingIndexes = [];

        foreach ($requirements as $table => $checks) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $missingTables[] = $table;
                continue;
            }

            foreach (($checks['columns'] ?? []) as $column) {
                if (! Schema::connection($connection)->hasColumn($table, $column)) {
                    $missingColumns[] = "{$table}.{$column}";
                }
            }

            foreach (($checks['indexes'] ?? []) as $index) {
                if (! $this->indexExists($connection, $table, $index)) {
                    $missingIndexes[] = "{$table}.{$index}";
                }
            }
        }

        $failures = array_merge($missingTables, $missingColumns, $missingIndexes);

        return [
            'ok' => $failures === [],
            'status' => $failures === [] ? 'pass' : 'fail',
            'connection' => $connection,
            'database' => DB::connection($connection)->getDatabaseName(),
            'checked_tables' => array_keys($requirements),
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
            'warnings' => [],
        ];
    }

    public static function requirements(): array
    {
        return [
            'inventory_settings' => ['columns' => ['organization_id', 'default_costing_method', 'allow_negative_stock']],
            'items' => ['columns' => ['organization_id', 'sku', 'name', 'costing_method']],
            'warehouses' => ['columns' => ['organization_id', 'code', 'name', 'is_active']],
            'stock_balances' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'on_hand_qty', 'reserved_qty', 'average_cost', 'total_value'], 'indexes' => ['balances_coord_uniq']],
            'stock_ledger' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'direction', 'quantity', 'unit_cost', 'source_type', 'source_id', 'idempotency_key'], 'indexes' => ['ledger_source_idx']],
            'cost_layers' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'unit_cost', 'original_qty', 'remaining_qty', 'source_ledger_id'], 'indexes' => ['layers_fifo_idx']],
            'cost_layer_consumptions' => ['columns' => ['organization_id', 'ledger_id', 'cost_layer_id', 'qty', 'unit_cost']],
            'inventory_suppliers' => ['columns' => ['organization_id', 'code', 'name', 'is_active']],
            'inventory_purchase_orders' => ['columns' => ['organization_id', 'po_number', 'supplier_id', 'warehouse_id', 'status'], 'indexes' => ['inv_po_org_sup_status_idx']],
            'purchase_order_lines' => ['columns' => ['organization_id', 'purchase_order_id', 'item_id', 'ordered_qty', 'received_qty']],
            'goods_receipts' => ['columns' => ['organization_id', 'grn_number', 'purchase_order_id', 'supplier_id', 'warehouse_id', 'status']],
            'goods_receipt_lines' => ['columns' => ['organization_id', 'goods_receipt_id', 'item_id', 'received_qty', 'accepted_qty']],
            'opening_stock_entries' => ['columns' => ['organization_id', 'entry_number', 'warehouse_id', 'status', 'total_value']],
            'opening_stock_entry_lines' => ['columns' => ['organization_id', 'opening_stock_entry_id', 'item_id', 'quantity', 'unit_cost']],
            'stock_transfers' => ['columns' => ['organization_id', 'transfer_number', 'from_warehouse_id', 'to_warehouse_id', 'status']],
            'stock_transfer_lines' => ['columns' => ['organization_id', 'stock_transfer_id', 'item_id', 'quantity']],
            'stock_adjustments' => ['columns' => ['organization_id', 'adjustment_number', 'warehouse_id', 'status']],
            'stock_adjustment_lines' => ['columns' => ['organization_id', 'stock_adjustment_id', 'item_id', 'direction', 'quantity']],
            'stock_counts' => ['columns' => ['organization_id', 'count_number', 'warehouse_id', 'status', 'adjustment_id']],
            'stock_count_lines' => ['columns' => ['organization_id', 'stock_count_id', 'item_id', 'system_qty', 'counted_qty']],
            'inventory_sales_orders' => ['columns' => ['organization_id', 'order_number', 'warehouse_id', 'customer_name', 'status']],
            'sales_order_lines' => ['columns' => ['organization_id', 'sales_order_id', 'item_id', 'ordered_qty', 'reserved_qty']],
        ];
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        $database = DB::connection($connection)->getDatabaseName();
        $rows = DB::connection($connection)->select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $rows !== [];
    }
}
