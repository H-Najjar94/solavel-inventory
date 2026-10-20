<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'items' => ['reorder_point', 'reorder_qty', 'min_stock', 'max_stock', 'safety_stock'],
        'warehouse_reorder_rules' => ['reorder_point', 'reorder_qty', 'min_stock', 'max_stock', 'safety_stock'],
    ];

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        foreach (self::COLUMNS as $table => $columns) {
            if (! $schema->hasTable($table)) continue;
            foreach ($columns as $column) {
                if ($schema->hasColumn($table, $column)) $this->widenPreservingModifiers($table, $column);
            }
        }
    }

    private function widenPreservingModifiers(string $table, string $column): void
    {
        $connection = DB::connection('tenant');
        $metadata = $connection->selectOne(
            'SELECT IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default,
                    DATA_TYPE AS data_type, NUMERIC_PRECISION AS numeric_precision,
                    NUMERIC_SCALE AS numeric_scale, COLUMN_TYPE AS column_type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getDatabaseName(), $table, $column]
        );
        if (! $metadata) return;
        if (strtolower((string) $metadata->data_type) !== 'decimal') {
            throw new RuntimeException("Refusing an implicit non-decimal conversion on {$table}.{$column}.");
        }
        // Match the preceding purchase migration: never narrow historical
        // fractional precision, integer range, or the unsigned constraint.
        $scale = max(6, (int) $metadata->numeric_scale);
        $precision = max(18, (int) $metadata->numeric_precision - (int) $metadata->numeric_scale) + $scale;
        if ($precision > 65 || $scale > 30) {
            throw new RuntimeException("Cannot widen {$table}.{$column} losslessly within database decimal limits.");
        }
        $unsigned = str_contains(strtolower((string) $metadata->column_type), 'unsigned') ? ' UNSIGNED' : '';

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

        $connection->statement("ALTER TABLE `{$table}` MODIFY `{$column}` DECIMAL({$precision},{$scale}){$unsigned}{$nullable}{$default}");
    }

    /** Precision widening is forward-only; narrowing could truncate valid replenishment quantities. */
    public function down(): void {}
};
