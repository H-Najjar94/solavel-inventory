<?php

namespace Tests\Feature\Stock;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** DDL requires a fresh disposable lifecycle, not a rollback transaction. */
#[Group('committed-native-transport')]
final class PurchasePrecisionMigrationTest extends TestCase
{
    public function test_widening_preserves_values_ranges_defaults_and_forward_recovery(): void
    {
        $db = DB::connection('tenant');
        $this->assertStringStartsWith('solastock_test_', $db->getDatabaseName());
        $migration = require database_path('migrations/tenant/2026_10_19_220000_expand_purchase_and_valuation_precision_to_six.php');
        $widen = new \ReflectionMethod($migration, 'widenPreservingModifiers');
        $table = 'purchase_precision_qualification';
        foreach ([
            ['DECIMAL(20,4) NOT NULL DEFAULT 1.2345', '1234567890123456.1234', 'decimal(24,6)', '1234567890123456.123400', 'NO', '1.234500'],
            ['DECIMAL(24,6) NULL DEFAULT NULL', '1.234567', 'decimal(24,6)', '1.234567', 'YES', 'NULL'],
            ['DECIMAL(24,12) NULL DEFAULT 0.123456789123', '1.123456789123', 'decimal(30,12)', '1.123456789123', 'YES', '0.123456789123'],
            ['DECIMAL(30,6) UNSIGNED NOT NULL DEFAULT 0', '123456789012345678901234.123456', 'decimal(30,6) unsigned', '123456789012345678901234.123456', 'NO', '0.000000'],
        ] as [$definition, $value, $type, $expected, $nullable, $default]) {
            $db->statement("CREATE TABLE `{$table}` (`value` {$definition})");
            try {
                $db->table($table)->insert(['value'=>$value]);
                $widen->invoke($migration, $table, 'value');
                $widen->invoke($migration, $table, 'value');
                $migration->down();
                $metadata = $db->selectOne('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$db->getDatabaseName(), $table, 'value']);
                $this->assertSame($type, $metadata->COLUMN_TYPE);
                $this->assertSame($nullable, $metadata->IS_NULLABLE);
                $this->assertSame($default, (string) $metadata->COLUMN_DEFAULT);
                $this->assertSame($expected, $db->table($table)->value('value'));
            } finally {
                $db->statement("DROP TABLE `{$table}`");
            }
        }
        foreach (['DECIMAL(65,0)', 'DOUBLE'] as $definition) {
            $db->statement("CREATE TABLE `{$table}` (`value` {$definition})");
            try {
                $db->table($table)->insert(['value'=>'1']);
                try {
                    $widen->invoke($migration, $table, 'value');
                    $this->fail('Unsafe implicit conversion was accepted.');
                } catch (\RuntimeException $exception) {
                    $this->assertMatchesRegularExpression('/losslessly|non-decimal/', $exception->getMessage());
                }
                $this->assertEquals('1', $db->table($table)->value('value'));
            } finally {
                $db->statement("DROP TABLE `{$table}`");
            }
        }
    }
}
