<?php

namespace Tests\Support;

use App\Tenancy\TenancySafetyGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One committed test in its own sealed private SQL lifecycle; ledger triggers stay enabled. */
final class CommittedTenantFixture
{
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
        $this->connection();
        $this->lock = 'stock-committed-fixture:'.$this->database;
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->lock])->acquired !== 1) {
            throw new RuntimeException('Another committed fixture owns this disposable database.');
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

    /** Close connections only. The native launcher destroys this owned private SQL lifecycle. */
    public function restore(): void
    {
        $db = $this->connection();
        try {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lock]);
        }
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
        $manifest = getenv('STOCK_COMMITTED_FIXTURE_MANIFEST');
        if (! $manifest || ! preg_match('#^/tmp/stock-tests\.[A-Za-z0-9]+/committed-lifecycle\.json$#', $manifest) || ! is_file($manifest) || is_link($manifest)) {
            throw new RuntimeException('Committed fixture requires its own private SQL lifecycle manifest.');
        }
        $lifecycle = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $server = $db->selectOne('SELECT @@socket AS socket, @@port AS port, @@datadir AS datadir');
        if (($lifecycle['cohort'] ?? null) !== 'committed-native-transport'
            || ($lifecycle['socket'] ?? null) !== $server->socket || (int) $server->port !== 0
            || rtrim((string) ($lifecycle['disposable_datadir'] ?? ''), '/') !== rtrim($server->datadir, '/')) {
            throw new RuntimeException('Committed fixture is not on the owned non-networked private SQL server.');
        }
        return $db;
    }
}
