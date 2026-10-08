<?php

namespace Tests\Support;

use App\Tenancy\TenancySafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Committed native transport fixture; limited to the disposable tenant A schema. */
final class CommittedTenantFixture
{
    private array $baseline = [];
    private string $database;
    private string $lock;
    private bool $committed = false;

    public function __construct(TenantTestManager $manager)
    {
        $db = DB::connection('tenant');
        $this->database = $db->getDatabaseName();
        $this->assertSealedNamespace();
        TenancySafetyGuard::assertTestingEnvironment();
        TenancySafetyGuard::assertSafeTestDatabase($this->database);
        TenancySafetyGuard::assertCentralAndTenantDiffer($manager->centralDatabase(), $this->database);
        if ($this->database !== $manager->tenantADatabase() || $db->transactionLevel() !== 1) {
            throw new RuntimeException('Committed fixture requires the isolated tenant A transaction.');
        }
        $this->lock = 'stock-committed-fixture:'.$this->database;
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->lock])->acquired !== 1) {
            throw new RuntimeException('Another committed fixture owns this disposable database.');
        }
        foreach ($db->select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$this->database]) as $table) {
            $this->baseline[$table->name] = $db->table($table->name)->get()->map(fn ($row) => (array) $row)->all();
        }
    }

    public function commit(): void
    {
        $db = $this->connection();
        if ($this->committed || $db->transactionLevel() !== 1) {
            throw new RuntimeException('Committed fixture boundary must execute exactly once.');
        }
        $db->commit();
        $this->committed = true;
    }

    public function restore(): void
    {
        $db = $this->connection();
        try {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if (! $this->committed) {
                return;
            }
            $changed = [];
            foreach ($this->baseline as $table => $rows) {
                if ($this->canonicalRows($db->table($table)->get()->map(fn ($row) => (array) $row)->all()) !== $this->canonicalRows($rows)) {
                    $changed[$table] = $rows;
                }
            }
            $foreignKeys = (int) $db->selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS enabled')->enabled;
            $db->statement('SET SESSION FOREIGN_KEY_CHECKS = 0');
            try {
                $db->transaction(function () use ($db, $changed) {
                    foreach ($changed as $table => $rows) {
                        $db->table($table)->delete();
                        // Generated values are restored by MySQL, not inserted explicitly.
                        $generated = array_map(fn ($column) => $column->name, $db->select("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND (EXTRA LIKE '%VIRTUAL GENERATED%' OR EXTRA LIKE '%STORED GENERATED%')", [$this->database, $table]));
                        foreach ($rows as $row) {
                            $db->table($table)->insert(array_diff_key($row, array_flip($generated)));
                        }
                    }
                    foreach ($this->baseline as $table => $rows) {
                        $restored = $db->table($table)->get()->map(fn ($row) => (array) $row)->all();
                        if ($this->canonicalRows($restored) !== $this->canonicalRows($rows)) {
                            throw new RuntimeException('Committed fixture baseline restoration failed for '.$table);
                        }
                    }
                });
            } finally {
                $db->statement('SET SESSION FOREIGN_KEY_CHECKS = '.$foreignKeys);
            }
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lock]);
        }
    }

    /** Order-independent exact comparison, including binary and timestamp values. */
    private function canonicalRows(array $rows): array
    {
        $canonical = array_map(function (array $row): string {
            ksort($row, SORT_STRING);
            return serialize($row);
        }, $rows);
        sort($canonical, SORT_STRING);
        return $canonical;
    }

    private function assertSealedNamespace(): void
    {
        $marker = '/qualification/host-net-namespace';
        $current = readlink('/proc/self/ns/net');
        if (! is_file($marker) || ! $current || trim(file_get_contents($marker)) === $current) {
            throw new RuntimeException('Committed fixture requires the sealed contained network namespace.');
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        $this->assertSealedNamespace();
        TenancySafetyGuard::assertTestingEnvironment();
        $db = DB::connection('tenant');
        TenancySafetyGuard::assertSafeTestDatabase($db->getDatabaseName());
        if ($db->getDatabaseName() !== $this->database) {
            throw new RuntimeException('Committed fixture tenant context changed; refusing restoration.');
        }
        return $db;
    }
}
