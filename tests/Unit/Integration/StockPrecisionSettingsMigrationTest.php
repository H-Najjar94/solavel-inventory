<?php

namespace Tests\Unit\Integration;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** Isolated in-memory configuration qualification; no tenant credentials or application database. */
final class StockPrecisionSettingsMigrationTest extends TestCase
{
    public function test_precision_update_preserves_other_configuration_and_frozen_history_and_is_idempotent(): void
    {
        $previous = Facade::getFacadeApplication();
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'tenant');
        $capsule->getContainer()->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($capsule->getContainer());
        try {
            $connection = $capsule->getConnection('tenant');
            $connection->getSchemaBuilder()->create('integration_settings', function (Blueprint $table): void {
                $table->increments('id'); $table->string('integration'); $table->text('meta');
            });
            $meta = ['finance_currency_contract' => ['base_currency_code' => 'AED', 'money_scale' => 2, 'rate_scale' => 8],
                'signing_key_id' => 'preserve-this', 'frozen_receipt' => ['stock_money_scale' => 2, 'amount' => '12.34']];
            $connection->table('integration_settings')->insert([
                ['id' => 1, 'integration' => 'solabooks', 'meta' => json_encode($meta)],
                ['id' => 2, 'integration' => 'solabooks', 'meta' => '{"unconfigured":true}'],
                ['id' => 3, 'integration' => 'other', 'meta' => json_encode($meta)],
            ]);
            $migration = require __DIR__.'/../../../database/migrations/tenant/2026_10_15_230100_set_connected_finance_money_precision_three.php';
            $migration->up();
            $expected = $meta; $expected['finance_currency_contract']['money_scale'] = 3;
            $after = $connection->table('integration_settings')->where('id', 1)->value('meta');
            self::assertSame($expected, json_decode($after, true));
            self::assertSame('{"unconfigured":true}', $connection->table('integration_settings')->where('id', 2)->value('meta'));
            self::assertSame($meta, json_decode($connection->table('integration_settings')->where('id', 3)->value('meta'), true));
            $migration->up(); $migration->down();
            self::assertSame($after, $connection->table('integration_settings')->where('id', 1)->value('meta'));
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }
}
