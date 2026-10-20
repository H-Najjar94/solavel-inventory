<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Schema-only Money3 compatibility; never changes calculations, settings or historical amounts. */
return new class extends Migration
{
    // Canonical Money3 storage contract. Columns already at six or more decimal
    // places are left completely untouched, including their existing modifiers.
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
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) return;

        foreach (self::COLUMNS as $table => $names) {
            foreach ($names as $column) {
                $metadata = $connection->selectOne(
                    'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type,
                            NUMERIC_PRECISION AS numeric_precision, NUMERIC_SCALE AS numeric_scale,
                            IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
                            COLUMN_COMMENT AS column_comment, EXTRA AS extra,
                            GENERATION_EXPRESSION AS generation_expression
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$connection->getDatabaseName(), $table, $column]
                );
                if (! $metadata) continue;
                if (strtolower((string) $metadata->data_type) !== 'decimal') {
                    throw new RuntimeException("Refusing a non-decimal Money3 conversion on {$table}.{$column}.");
                }
                if ((int) $metadata->numeric_scale >= 6) continue;
                if (trim((string) $metadata->generation_expression) !== '' || trim((string) $metadata->extra) !== '') {
                    throw new RuntimeException("Refusing to reinterpret generated or special metadata on {$table}.{$column}.");
                }

                $scale = 6;
                $precision = max(18, (int) $metadata->numeric_precision - (int) $metadata->numeric_scale) + $scale;
                if ($precision > 65) throw new RuntimeException("Cannot widen {$table}.{$column} without losing integer range.");
                $type = strtolower((string) $metadata->column_type);
                $modifiers = str_contains($type, 'unsigned') ? ' UNSIGNED' : '';
                if (str_contains($type, 'zerofill')) $modifiers .= ' ZEROFILL';
                $nullable = $metadata->is_nullable === 'YES' ? ' NULL' : ' NOT NULL';
                $default = '';
                if ($metadata->column_default !== null) {
                    $value = trim((string) $metadata->column_default, "'");
                    if (strcasecmp($value, 'null') === 0 && $metadata->is_nullable === 'YES') {
                        $default = ' DEFAULT NULL';
                    } elseif (preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                        $default = ' DEFAULT '.$value;
                    } else {
                        throw new RuntimeException("Refusing to reinterpret a non-numeric default on {$table}.{$column}.");
                    }
                } elseif ($metadata->is_nullable === 'YES') {
                    $default = ' DEFAULT NULL';
                }
                $comment = ' COMMENT '.$connection->getPdo()->quote((string) $metadata->column_comment);
                $connection->statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL({$precision},{$scale}){$modifiers}{$nullable}{$default}{$comment}, ALGORITHM=COPY, LOCK=SHARED");
            }
        }
    }

    /** Never narrow populated columns during application rollback. */
    public function down(): void {}
};
