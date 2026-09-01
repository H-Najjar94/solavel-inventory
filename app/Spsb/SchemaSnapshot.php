<?php

namespace App\Spsb;

use PDO;
use RuntimeException;

final class SchemaSnapshot
{
    public static function capture(PDO $pdo, string $database): array
    {
        if (preg_match(Guard::DATABASE_PATTERN, $database) !== 1) {
            throw new RuntimeException('Unsafe schema snapshot database.');
        }

        return [
            'format' => 'solastock.schema-snapshot.v1',
            'tables' => self::rows($pdo, <<<'SQL'
SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation
FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'spsb_guard_marker'
ORDER BY TABLE_NAME
SQL, [$database]),
            'columns' => self::rows($pdo, <<<'SQL'
SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, ORDINAL_POSITION AS ordinal,
       COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value,
       EXTRA AS extra, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation,
       GENERATION_EXPRESSION AS generation_expression
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> 'spsb_guard_marker'
ORDER BY TABLE_NAME, ORDINAL_POSITION
SQL, [$database]),
            'indexes' => self::rows($pdo, <<<'SQL'
SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, NON_UNIQUE AS non_unique,
       SEQ_IN_INDEX AS ordinal, COLUMN_NAME AS column_name, SUB_PART AS prefix_length, INDEX_TYPE AS index_type
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME <> 'spsb_guard_marker'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
SQL, [$database]),
            'foreign_keys' => self::rows($pdo, <<<'SQL'
SELECT k.TABLE_NAME AS table_name, k.CONSTRAINT_NAME AS name, k.ORDINAL_POSITION AS ordinal,
       k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS referenced_table,
       k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS on_delete, r.UPDATE_RULE AS on_update
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.REFERENTIAL_CONSTRAINTS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.CONSTRAINT_SCHEMA = ? AND k.TABLE_NAME <> 'spsb_guard_marker'
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
SQL, [$database]),
            'checks' => self::rows($pdo, <<<'SQL'
SELECT tc.TABLE_NAME AS table_name, tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause
FROM information_schema.TABLE_CONSTRAINTS tc
JOIN information_schema.CHECK_CONSTRAINTS cc
  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.TABLE_NAME = tc.TABLE_NAME AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.TABLE_NAME <> 'spsb_guard_marker'
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
SQL, [$database]),
            'triggers' => self::rows($pdo, <<<'SQL'
SELECT EVENT_OBJECT_TABLE AS table_name, TRIGGER_NAME AS name, ACTION_TIMING AS timing,
       EVENT_MANIPULATION AS event, ACTION_STATEMENT AS statement
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE <> 'spsb_guard_marker'
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME
SQL, [$database]),
        ];
    }

    public static function forTables(array $snapshot, array $tables): array
    {
        $lookup = array_fill_keys($tables, true);
        $filtered = ['format' => $snapshot['format'] ?? null];
        foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'] as $class) {
            $filtered[$class] = array_values(array_filter(
                (array) ($snapshot[$class] ?? []),
                static fn (array $row): bool => isset($lookup[$row['table_name'] ?? $row['name'] ?? ''])
            ));
        }

        return $filtered;
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', CanonicalJson::encode($snapshot));
    }

    private static function rows(PDO $pdo, string $sql, array $bindings): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
