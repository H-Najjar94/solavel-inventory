<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Widen only: no financial amount updates; down intentionally retains precision for forward recovery. */
return new class extends Migration
{
    private const COLUMNS = [
        'stock_ledger' => ['total_cost', 'balance_value_after'],
        'stock_balances' => ['total_value'],
        'opening_stock_entries' => ['total_value'],
        'opening_stock_entry_lines' => ['total_cost'],
        'stock_adjustments' => ['total_increase_value', 'total_decrease_value'],
        'inventory_purchase_orders' => ['subtotal', 'tax_total', 'total'],
        'purchase_order_lines' => ['tax_amount', 'line_total'],
        'inventory_sales_orders' => ['subtotal', 'discount_total', 'tax_total', 'total'],
        'sales_order_lines' => ['discount_amount', 'tax_amount', 'line_total'],
        'shipments' => ['rate_amount'],
        'internal_consumption_lines' => ['total_cost'],
        'stock_landed_costs' => ['total_base_amount', 'inventory_base_amount', 'consumed_base_amount'],
        'stock_landed_cost_charges' => ['base_amount'],
        'stock_landed_cost_lines' => ['receipt_value', 'allocated_base_amount', 'inventory_base_amount', 'consumed_base_amount'],
        'stock_landed_cost_components' => ['posted_base_amount'],
    ];

    public function getConnection(): ?string { return 'tenant'; }

    public function up(): void
    {
        $connection = DB::connection('tenant');
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite test schemas have no fixed decimal scale.
        }
        foreach (self::COLUMNS as $table => $names) {
            $columns = $connection->table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())->where('TABLE_NAME', $table)
                ->whereIn('COLUMN_NAME', $names)->where('DATA_TYPE', 'decimal')
                ->get(['COLUMN_NAME', 'NUMERIC_PRECISION', 'NUMERIC_SCALE', 'IS_NULLABLE', 'COLUMN_DEFAULT']);
            $clauses = [];
            foreach ($columns as $column) {
                $scale = (int) $column->NUMERIC_SCALE;
                if ($scale >= 6) {
                    continue;
                }
                $precision = max(24, (int) $column->NUMERIC_PRECISION - $scale + 6);
                $nullable = $column->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';
                $default = $column->COLUMN_DEFAULT === null || strtoupper((string) $column->COLUMN_DEFAULT) === 'NULL'
                    ? ($column->IS_NULLABLE === 'YES' ? ' DEFAULT NULL' : '')
                    : ' DEFAULT '.(is_numeric($column->COLUMN_DEFAULT)
                        ? (string) $column->COLUMN_DEFAULT : $connection->getPdo()->quote((string) $column->COLUMN_DEFAULT));
                $name = str_replace('`', '``', $column->COLUMN_NAME);
                $clauses[] = "MODIFY `{$name}` DECIMAL({$precision},6){$nullable}{$default}";
            }
            if ($clauses !== []) {
                // Same widening pattern as 2026_10_06_000400 (COPY is required when widening decimals on the supported MariaDB).
                $connection->statement('ALTER TABLE `'.str_replace('`', '``', $table).'` '.implode(', ', $clauses).', ALGORITHM=COPY, LOCK=SHARED');
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: narrowing populated money columns would destroy valid fils/baisa precision.
    }
};
