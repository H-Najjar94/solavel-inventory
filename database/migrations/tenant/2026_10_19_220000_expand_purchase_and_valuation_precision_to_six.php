<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'purchase_order_lines' => ['ordered_qty','received_qty','unit_price','entered_qty'],
        'purchase_order_backorders' => ['ordered_qty','received_qty','backorder_qty','unit_price'],
        'purchasing_receiving_request_lines' => ['requested_qty','received_qty','unit_cost'],
        'goods_receipt_lines' => ['received_qty','accepted_qty','rejected_qty','quarantine_qty','unit_cost','entered_qty'],
        'supplier_return_lines' => ['quantity','entered_qty'],
        'opening_stock_entry_lines' => ['quantity','unit_cost','entered_qty'],
        'stock_adjustment_lines' => ['quantity','unit_cost','entered_qty'],
        'stock_ledger' => ['quantity','unit_cost','total_cost','balance_qty_after','balance_value_after'],
        'stock_balances' => ['on_hand_qty','reserved_qty','average_cost','total_value'],
        'cost_layers' => ['unit_cost','original_qty','remaining_qty'],
        'cost_layer_consumptions' => ['qty','unit_cost'],
        'stock_landed_cost_lines' => ['quantity','receipt_value','weight','allocated_base_amount','inventory_base_amount','consumed_base_amount'],
        'stock_landed_costs' => ['total_amount'],
        'stock_landed_cost_charges' => ['amount'],
        'integration_document_lifecycle_mappings' => ['ordered_qty','reserved_qty','received_qty','billed_qty','shipped_qty','invoiced_qty','returned_qty'],
    ];

    public function up(): void
    {
        $schema=Schema::connection('tenant');
        foreach(self::COLUMNS as $table=>$columns){
            if(!$schema->hasTable($table))continue;
            foreach($columns as $column){
                if(!$schema->hasColumn($table,$column))continue;
                $this->widenPreservingModifiers($table, $column);
            }
        }
    }

    /** Laravel's change() drops nullable/default modifiers unless restated. */
    private function widenPreservingModifiers(string $table, string $column): void
    {
        $connection = DB::connection('tenant');
        $metadata = $connection->selectOne(
            'SELECT IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getDatabaseName(), $table, $column]
        );
        if (! $metadata) return;

        $nullable = $metadata->is_nullable === 'YES' ? ' NULL' : ' NOT NULL';
        $default = '';
        if ($metadata->column_default !== null) {
            $value = trim((string) $metadata->column_default, "'");
            if (strcasecmp($value, 'null') === 0 && $metadata->is_nullable === 'YES') {
                $default = ' DEFAULT NULL';
            } elseif (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                throw new RuntimeException("Refusing to reinterpret a non-numeric default on {$table}.{$column}.");
            } else {
                $default = ' DEFAULT '.$value;
            }
        } elseif ($metadata->is_nullable === 'YES') {
            $default = ' DEFAULT NULL';
        }

        $connection->statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL(24,6){$nullable}{$default}");
    }

    /** Narrowing would silently destroy valid source precision, so rollback is intentionally non-destructive. */
    public function down(): void {}
};
