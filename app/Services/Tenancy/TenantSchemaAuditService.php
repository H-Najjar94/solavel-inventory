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

    public function auditDatabase(string $database, ?string $connection = null, ?array $requirements = null): array
    {
        $connection ??= 'mysql';
        $requirements ??= self::requirements();

        $base = [
            'ok' => false,
            'status' => 'fail',
            'connection' => $connection,
            'database' => $database,
            'checked_tables' => array_keys($requirements),
            'missing_database' => false,
            'missing_tables' => [],
            'missing_columns' => [],
            'missing_indexes' => [],
            'warnings' => [],
        ];

        try {
            $exists = collect(DB::connection($connection)->select(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1',
                [$database]
            ))->isNotEmpty();
        } catch (\Throwable $e) {
            return array_merge($base, [
                'error' => 'schema_audit_unreachable',
                'message' => $e->getMessage(),
                'warnings' => ['Could not read information_schema for schema audit.'],
            ]);
        }

        if (! $exists) {
            return array_merge($base, [
                'missing_database' => true,
                'warnings' => ['Tenant database does not exist.'],
            ]);
        }

        $tables = array_keys($requirements);
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $params = array_merge([$database], $tables);

        $existingTables = collect(DB::connection($connection)->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders})",
            $params
        ))->pluck('TABLE_NAME')->map(fn ($name) => (string) $name)->all();

        $existingSet = array_flip($existingTables);
        $missingTables = [];
        $missingColumns = [];
        $missingIndexes = [];

        foreach ($requirements as $table => $checks) {
            if (! isset($existingSet[$table])) {
                $missingTables[] = $table;

                continue;
            }

            foreach (($checks['columns'] ?? []) as $column) {
                if (! $this->columnExistsInDatabase($connection, $database, $table, $column)) {
                    $missingColumns[] = "{$table}.{$column}";
                }
            }

            foreach (($checks['indexes'] ?? []) as $index) {
                if (! $this->indexExistsInDatabase($connection, $database, $table, $index)) {
                    $missingIndexes[] = "{$table}.{$index}";
                }
            }
        }

        $failures = array_merge($missingTables, $missingColumns, $missingIndexes);

        return array_merge($base, [
            'ok' => $failures === [],
            'status' => $failures === [] ? 'pass' : 'fail',
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'missing_indexes' => $missingIndexes,
        ]);
    }

    public static function requirements(): array
    {
        return [
            'inventory_settings' => ['columns' => ['organization_id', 'default_costing_method', 'allow_negative_stock', 'expiry_warning_days']],
            'items' => ['columns' => ['organization_id', 'sku', 'name', 'costing_method']],
            'item_variants' => ['columns' => ['organization_id', 'item_id', 'sku', 'variant_attributes', 'is_active']],
            'item_attachments' => ['columns' => ['organization_id', 'item_id', 'name', 'path', 'size_bytes']],
            'supplier_price_lists' => ['columns' => ['organization_id', 'item_id', 'supplier_id', 'unit_cost', 'minimum_qty']],
            'inventory_customers' => ['columns' => ['organization_id', 'code', 'name', 'is_active']],
            'warehouses' => ['columns' => ['organization_id', 'code', 'name', 'is_active']],
            'stock_balances' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'on_hand_qty', 'reserved_qty', 'average_cost', 'total_value'], 'indexes' => ['balances_coord_uniq']],
            'stock_ledger' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'direction', 'quantity', 'unit_cost', 'source_type', 'source_id', 'idempotency_key'], 'indexes' => ['ledger_source_idx']],
            'cost_layers' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'unit_cost', 'original_qty', 'remaining_qty', 'source_ledger_id'], 'indexes' => ['layers_fifo_idx']],
            'cost_layer_consumptions' => ['columns' => ['organization_id', 'ledger_id', 'cost_layer_id', 'qty', 'unit_cost']],
            'inventory_suppliers' => ['columns' => ['organization_id', 'code', 'name', 'is_active']],
            'inventory_purchase_orders' => ['columns' => ['organization_id', 'po_number', 'supplier_id', 'warehouse_id', 'status'], 'indexes' => ['inv_po_org_sup_status_idx']],
            'purchase_order_lines' => ['columns' => ['organization_id', 'purchase_order_id', 'item_id', 'ordered_qty', 'received_qty', 'entered_qty', 'entered_unit_id', 'unit_conversion_factor', 'tax_code', 'tax_rate', 'tax_amount', 'line_total']],
            'purchase_order_backorders' => ['columns' => ['organization_id', 'purchase_order_id', 'purchase_order_line_id', 'item_id', 'warehouse_id', 'backorder_qty', 'status'], 'indexes' => ['po_backorders_org_line_uniq']],
            'goods_receipts' => ['columns' => ['organization_id', 'grn_number', 'purchase_order_id', 'supplier_id', 'warehouse_id', 'status', 'blind_receiving', 'inspection_status']],
            'goods_receipt_lines' => ['columns' => ['organization_id', 'goods_receipt_id', 'item_id', 'received_qty', 'accepted_qty', 'rejected_qty', 'inspection_status', 'disposition', 'quarantine_qty', 'entered_qty', 'entered_unit_id', 'unit_conversion_factor']],
            'opening_stock_entries' => ['columns' => ['organization_id', 'entry_number', 'warehouse_id', 'status', 'total_value']],
            'opening_stock_entry_lines' => ['columns' => ['organization_id', 'opening_stock_entry_id', 'item_id', 'quantity', 'unit_cost', 'entered_qty', 'entered_unit_id', 'unit_conversion_factor']],
            'stock_transfers' => ['columns' => ['organization_id', 'transfer_number', 'from_warehouse_id', 'to_warehouse_id', 'status', 'shipped_at', 'received_at']],
            'stock_transfer_lines' => ['columns' => ['organization_id', 'stock_transfer_id', 'item_id', 'quantity']],
            'stock_adjustments' => ['columns' => ['organization_id', 'adjustment_number', 'warehouse_id', 'status']],
            'stock_adjustment_lines' => ['columns' => ['organization_id', 'stock_adjustment_id', 'item_id', 'direction', 'quantity']],
            'stock_counts' => ['columns' => ['organization_id', 'count_number', 'warehouse_id', 'status', 'adjustment_id', 'blind_count', 'scheduled_for', 'abc_class', 'snapshot_at']],
            'stock_count_lines' => ['columns' => ['organization_id', 'stock_count_id', 'item_id', 'system_qty', 'snapshot_qty', 'counted_qty']],
            'inventory_sales_orders' => ['columns' => ['organization_id', 'order_number', 'warehouse_id', 'customer_name', 'customer_id', 'status', 'subtotal', 'discount_total', 'tax_total', 'total']],
            'sales_order_lines' => ['columns' => ['organization_id', 'sales_order_id', 'item_id', 'ordered_qty', 'reserved_qty', 'discount_rate', 'tax_rate', 'line_total']],
            'reservations' => ['columns' => ['organization_id', 'item_id', 'warehouse_id', 'qty', 'priority', 'source_type', 'source_id', 'status', 'expires_at', 'expired_at']],
            'shipments' => ['columns' => ['organization_id', 'shipment_number', 'sales_order_id', 'warehouse_id', 'carrier', 'carrier_service', 'tracking_number', 'label_status', 'label_payload', 'tracking_status', 'tracking_events', 'warranty_months']],
            'shipment_lines' => ['columns' => ['organization_id', 'shipment_id', 'item_id', 'quantity', 'lot_id', 'serial_id']],
            'pack_lines' => ['columns' => ['organization_id', 'pack_id', 'item_id', 'package_number', 'lot_id', 'serial_id'], 'indexes' => ['packl_org_serial_idx']],
            'sales_returns' => ['columns' => ['organization_id', 'return_number', 'warehouse_id', 'status', 'customer_id', 'authorized_at', 'inspected_at']],
            'sales_return_lines' => ['columns' => ['organization_id', 'sales_return_id', 'item_id', 'returned_qty', 'condition', 'inspection_status', 'disposition']],
            'inventory_alerts' => ['columns' => ['organization_id', 'alert_key', 'type', 'severity', 'title', 'status', 'channels'], 'indexes' => ['inventory_alerts_org_key_uniq']],
            'inventory_scheduled_reports' => ['columns' => ['organization_id', 'report_key', 'name', 'filters', 'recipients', 'frequency', 'format', 'next_run_at', 'last_run_at', 'last_status']],
            'inventory_currency_rates' => ['columns' => ['organization_id', 'currency_code', 'rate_to_base', 'effective_date'], 'indexes' => ['inventory_currency_rates_org_ccy_date_uniq']],
            'inventory_custom_roles' => ['columns' => ['organization_id', 'key', 'name', 'permissions', 'is_active'], 'indexes' => ['inventory_custom_roles_org_key_uniq']],
            'inventory_user_role_assignments' => ['columns' => ['organization_id', 'user_id', 'role_id', 'assigned_by'], 'indexes' => ['inventory_role_assignments_org_user_uniq']],
        ];
    }

    private function indexExists(string $connection, string $table, string $index): bool
    {
        $database = DB::connection($connection)->getDatabaseName();

        return $this->indexExistsInDatabase($connection, $database, $table, $index);
    }

    private function columnExistsInDatabase(string $connection, string $database, string $table, string $column): bool
    {
        $rows = DB::connection($connection)->select(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$database, $table, $column]
        );

        return $rows !== [];
    }

    private function indexExistsInDatabase(string $connection, string $database, string $table, string $index): bool
    {
        $rows = DB::connection($connection)->select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $rows !== [];
    }
}
