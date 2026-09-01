<?php

namespace Tests\Unit\Spsb;

use App\Services\Integration\SolaStockJournalContract;
use App\Spsb\Guard;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SpsbPolicyTest extends TestCase
{
    #[Test]
    public function ownership_is_exhaustive_disjoint_and_has_frozen_counts(): void
    {
        $ownership = config('spsb.ownership');
        $this->assertCount(60, $ownership['solastock_owned']);
        $this->assertCount(2, $ownership['shared_core_contributor']);
        $this->assertCount(21, $ownership['integration_contract']);

        $all = array_merge(...array_values($ownership));
        $this->assertCount(83, $all);
        $this->assertCount(83, array_unique($all));
        $this->assertContains('stock_ledger', $ownership['solastock_owned']);
        $this->assertContains('stock_balances', $ownership['solastock_owned']);
        $this->assertContains('warehouses', $ownership['solastock_owned']);
        $this->assertContains('items', $ownership['solastock_owned']);
    }

    #[Test]
    public function migration_application_and_order_are_deterministic(): void
    {
        $group = config('spsb.migration_group');
        $this->assertSame('solastock.inventory', $group['id']);
        $this->assertSame(300, $group['order']);
        $this->assertSame('database/migrations/tenant', $group['path']);
        $this->assertSame(['shared-core', 'solacount'], $group['after']);

        $files = glob(base_path($group['path'].'/*.php')) ?: [];
        sort($files, SORT_STRING);
        $this->assertCount(49, $files);
        $this->assertSame($files, array_values(array_unique($files)));
        $this->assertStringNotContainsString(
            '2026_08_17_130000_solastock_create_pos_sale_consumptions.php',
            implode("\n", $files),
        );
    }

    #[Test]
    public function every_preexisting_name_is_an_exact_contract_or_shared_core_contribution(): void
    {
        $expected = config('spsb.exact_preexisting_contracts');
        $this->assertCount(11, $expected);
        $this->assertContains('tenant_entitlements_snapshots', $expected);
        foreach (array_diff($expected, ['tenant_entitlements_snapshots']) as $table) {
            $this->assertContains($table, config('spsb.ownership.integration_contract'));
        }
    }

    #[Test]
    public function foreign_accounting_and_legacy_inventory_names_can_never_be_claimed(): void
    {
        $owned = array_merge(...array_values(config('spsb.ownership')));
        $forbidden = config('spsb.forbidden_foreign_authority');
        $this->assertSame([], array_values(array_intersect($owned, $forbidden)));
        foreach (['customers', 'suppliers', 'currencies', 'taxes', 'organizations', 'users', 'inventory_items', 'inventory_locations', 'inventory_stocks', 'inventory_movements', 'inventory_valuations'] as $table) {
            $this->assertContains($table, $forbidden);
        }
    }

    #[Test]
    public function entitlement_contributor_does_not_mutate_shared_core_shape(): void
    {
        foreach (['2026_07_07_120000_create_tenant_entitlements_snapshots_table.php', '2026_07_07_120100_add_timestamps_to_tenant_entitlements_snapshots_table.php'] as $name) {
            $migration = file_get_contents(base_path('database/migrations/tenant/'.$name));
            $this->assertStringNotContainsString("timestamp('created_at'", $migration);
            $this->assertStringNotContainsString("timestamp('updated_at'", $migration);
            $this->assertStringNotContainsString('$table->timestamps()', $migration);
        }
    }

    #[Test]
    public function receiver_v2_safety_hold_and_jod_contract_are_frozen(): void
    {
        $invariants = config('spsb.integration_invariants');
        $this->assertSame('solastock-journal.v2', SolaStockJournalContract::VERSION);
        $this->assertSame('solastock-journal.v2', $invariants['receiver_contract']);
        $this->assertSame('JOD', $invariants['base_currency']);
        $this->assertFalse($invariants['delivery_enabled']);
        $this->assertFalse($invariants['pending_event_replay_enabled']);
        $this->assertFalse($invariants['historical_repair_enabled']);
        $this->assertTrue($invariants['legacy_inventory_writes_blocked']);
        $this->assertSame(['pending', 'ignored', 'untrusted'], $invariants['preserved_statuses']);
    }

    #[Test]
    public function disabled_solapos_consumption_surface_is_absent(): void
    {
        foreach ([
            'app/Http/Controllers/Api/Integration/SolaposConsumptionController.php',
            'app/Http/Middleware/VerifySolaposSignature.php',
            'app/Models/Tenant/PosSaleConsumption.php',
            'app/Models/Tenant/PosSaleConsumptionLine.php',
            'app/Services/Integration/SolaposConsumptionService.php',
            'database/migrations/tenant/2026_08_17_130000_solastock_create_pos_sale_consumptions.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path));
        }

        $surface = implode("\n", [
            file_get_contents(base_path('routes/api.php')),
            file_get_contents(base_path('bootstrap/app.php')),
            file_get_contents(base_path('config/solavel_sync.php')),
            file_get_contents(base_path('app/Services/Integration/IntegrationEvents.php')),
        ]);
        $this->assertStringNotContainsString('solapos', strtolower($surface));
        $this->assertStringNotContainsString('pos_sale.posted', $surface);
        $this->assertStringNotContainsString('pos_sale_return.posted', $surface);
        $this->assertSame([], array_values(array_intersect(
            ['pos_sale_consumptions', 'pos_sale_consumption_lines'],
            array_merge(...array_values(config('spsb.ownership'))),
        )));
    }

    #[Test]
    public function accounting_journal_uses_the_authoritative_inventory_models(): void
    {
        $builder = file_get_contents(base_path('app/Services/Integration/AccountingJournalBuilder.php'));
        foreach ([
            'use App\\Models\\Tenant\\GoodsReceipt;',
            'use App\\Models\\Tenant\\PurchaseOrderLine;',
            'use App\\Models\\Tenant\\SalesOrderLine;',
            'use App\\Models\\Tenant\\Shipment;',
        ] as $import) {
            $this->assertStringContainsString($import, $builder);
        }
    }

    #[Test]
    public function guard_rejects_an_unattested_or_tenant_named_database(): void
    {
        $this->assertSame(1, preg_match(Guard::DATABASE_PATTERN, 'spsb_probe_solastock_lifecycle'));
        $this->assertSame(0, preg_match(Guard::DATABASE_PATTERN, 'tenant_990010'));
        $this->expectException(RuntimeException::class);
        Guard::assertEnvironment(new PDO('sqlite::memory:'), 'tenant_990010');
    }

    #[Test]
    public function generator_cannot_write_before_postflight_guards_pass(): void
    {
        $tool = file_get_contents(base_path('scripts/spsb/schema-tool.php'));
        $runner = file_get_contents(base_path('scripts/spsb/run-guarded-candidate.sh'));
        $applier = file_get_contents(base_path('scripts/spsb/apply-canonical-fragment.php'));
        $this->assertStringContainsString("verification['result']", $tool);
        $this->assertStringContainsString('candidate generation requires passing lifecycle guards', $tool);
        $this->assertLessThan(strpos($runner, ' generate '), strpos($runner, ' postflight '));
        $this->assertLessThan(strpos($runner, 'cp -a "$CANDIDATE_ROOT"'), strpos($runner, 'validate-candidate.php'));
        $this->assertStringContainsString("hash_equals((string) \$declared['sha256']", $applier);
        $this->assertStringContainsString('Guard::assertEnvironment', $applier);
        $this->assertStringContainsString('-- SPSB:BEGIN', $applier);
    }
}
