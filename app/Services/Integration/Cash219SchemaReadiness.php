<?php
namespace App\Services\Integration;

use Illuminate\Support\Facades\DB;

/** Read-only exact shared-tenant readiness. Neither migrations nor this proof enable Cash. */
final class Cash219SchemaReadiness
{
    public const VERSION = 'cash-refunds-219-1';

    public function ready(): bool
    {
        $definition = config('cash219_schema');
        if (!is_array($definition) || ($definition['contract_version'] ?? null) !== self::VERSION) return false;
        $tables = array_keys($definition['required_columns'] ?? []);
        if (count($tables) !== 5) return false;
        try {
            $db = DB::connection('tenant');
            $columns = []; $indexes = [];
            foreach ($db->table('information_schema.columns')->where('table_schema', $db->getDatabaseName())
                ->whereIn('table_name', $tables)->get(['table_name', 'column_name', 'data_type', 'column_type', 'is_nullable']) as $row) {
                $columns[$row->table_name][$row->column_name] = (array) $row;
            }
            foreach ($db->table('information_schema.statistics')->where('table_schema', $db->getDatabaseName())
                ->whereIn('table_name', $tables)->orderBy('seq_in_index')
                ->get(['table_name', 'index_name', 'non_unique', 'column_name', 'seq_in_index', 'sub_part']) as $row) {
                $indexes[$row->table_name][$row->index_name][] = (array) $row;
            }
            return self::matches($columns, $indexes, $definition);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function matches(array $columns, array $indexes, array $definition): bool
    {
        if (($definition['contract_version'] ?? null) !== self::VERSION || count($definition['required_columns'] ?? []) !== 5) return false;
        foreach ($definition['required_columns'] as $table => $fields) {
            foreach ($fields as $name => $constraints) {
                $row = $columns[$table][$name] ?? null;
                if (!$row || !isset($row['data_type'], $row['column_type'], $row['is_nullable'])) return false;
                foreach ($constraints as $key => $expected) {
                    $valid = match ($key) {
                        'data_type' => ($row['data_type'] ?? null) === $expected,
                        'column_type' => ($row['column_type'] ?? null) === $expected,
                        'nullable' => (($row['is_nullable'] ?? null) === 'YES') === $expected,
                        'unsigned' => str_contains((string) ($row['column_type'] ?? ''), 'unsigned') === $expected,
                        default => false,
                    };
                    if (!$valid) return false;
                }
            }
        }
        foreach ($definition['index_definitions'] ?? [] as $table => $definitions) {
            foreach ($definitions as $name => $expected) {
                $rows = $indexes[$table][$name] ?? [];
                if (count($rows) !== count($expected['columns'])) return false;
                foreach ($rows as $position => $row) {
                    if (!array_key_exists('sub_part', $row) || !array_key_exists('non_unique', $row)
                        || (int) ($row['seq_in_index'] ?? 0) !== $position + 1
                        || ($row['column_name'] ?? null) !== $expected['columns'][$position]
                        || ((int) ($row['non_unique'] ?? 1) === 0) !== $expected['unique']
                        || ($row['sub_part'] ?? null) !== null) return false;
                }
            }
        }
        return count($definition['index_definitions'] ?? []) === 5;
    }
}
