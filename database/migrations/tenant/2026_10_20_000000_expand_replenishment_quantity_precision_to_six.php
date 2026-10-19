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

    /** Precision widening is forward-only; narrowing could truncate valid replenishment quantities. */
    public function down(): void {}
};
