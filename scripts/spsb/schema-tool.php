<?php

declare(strict_types=1);

use App\Services\Integration\SolaStockJournalContract;
use App\Spsb\CanonicalJson;
use App\Spsb\Guard;
use App\Spsb\SchemaSnapshot;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = realpath(dirname(__DIR__, 2));
$mode = $argv[1] ?? '';
$database = (string) getenv('DB_DATABASE');
$socket = (string) getenv('DB_SOCKET');
if ($root === false || $socket === '' || $database === '') {
    throw new RuntimeException('REFUSING: incomplete SPSB schema-tool environment.');
}
$pdo = new PDO("mysql:unix_socket={$socket};dbname={$database};charset=utf8mb4", (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_STRINGIFY_FETCHES => true,
]);
Guard::assertEnvironment($pdo, $database);
$config = require $root.'/config/spsb.php';
$runRoot = (string) getenv('SPSB_RUN_ROOT');

$safePath = static function (string $path) use ($runRoot): string {
    $parent = realpath(dirname($path));
    if ($parent === false || ! str_starts_with($parent.'/', $runRoot.'/') || is_link($path)) {
        throw new RuntimeException('REFUSING: unsafe SPSB artifact path.');
    }

    return $path;
};
$readSnapshot = static function (string $path) use ($safePath): array {
    $value = json_decode((string) file_get_contents($safePath($path)), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value) || ($value['format'] ?? null) !== 'solastock.schema-snapshot.v1') {
        throw new RuntimeException('Invalid SolaStock schema snapshot.');
    }

    return $value;
};
$ownedTables = static function (array $config): array {
    $tables = [];
    foreach (['shared_core_contributor', 'integration_contract', 'solastock_owned'] as $class) {
        foreach ($config['ownership'][$class] as $table) {
            if (isset($tables[$table])) {
                throw new RuntimeException("Duplicate ownership declaration for {$table}.");
            }
            $tables[$table] = $class;
        }
    }
    ksort($tables, SORT_STRING);

    return $tables;
};

if ($mode === 'guard') {
    echo CanonicalJson::encode(['database' => $database, 'result' => 'PASS']);
    exit(0);
}

if ($mode === 'snapshot') {
    $path = $safePath($argv[2] ?? '');
    file_put_contents($path, CanonicalJson::encode(SchemaSnapshot::capture($pdo, $database)), LOCK_EX);
    chmod($path, 0600);
    echo SchemaSnapshot::hash($readSnapshot($path))."\n";
    exit(0);
}

if ($mode === 'preflight') {
    $reference = $readSnapshot($argv[2] ?? '');
    $before = SchemaSnapshot::capture($pdo, $database);
    $owners = $ownedTables($config);
    $referenceNames = array_column($reference['tables'], 'name');
    sort($referenceNames, SORT_STRING);
    if ($referenceNames !== array_keys($owners)) {
        throw new RuntimeException('SolaStock ownership manifest is not exhaustive for its reference schema.');
    }
    $beforeNames = array_fill_keys(array_column($before['tables'], 'name'), true);
    foreach ($config['ownership']['solastock_owned'] as $table) {
        if (isset($beforeNames[$table])) {
            throw new RuntimeException("Forbidden duplicate inventory authority exists before SolaStock: {$table}.");
        }
    }
    foreach ($config['forbidden_foreign_authority'] as $table) {
        if (! isset($beforeNames[$table])) {
            throw new RuntimeException("Canonical SolaCount/Shared Core authority table is missing: {$table}.");
        }
    }
    $contracts = $config['exact_preexisting_contracts'];
    $expectedHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($reference, $contracts));
    $actualHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($before, $contracts));
    if (! hash_equals($expectedHash, $actualHash)) {
        $divergent = [];
        foreach ($contracts as $table) {
            $expectedTable = SchemaSnapshot::forTables($reference, [$table]);
            $actualTable = SchemaSnapshot::forTables($before, [$table]);
            $classes = [];
            foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'] as $class) {
                if (! hash_equals(hash('sha256', CanonicalJson::encode($expectedTable[$class])), hash('sha256', CanonicalJson::encode($actualTable[$class])))) {
                    $classes[] = $class;
                }
            }
            if ($classes !== []) {
                $divergent[] = $table.'['.implode('+', $classes).']';
            }
        }
        throw new RuntimeException('Preexisting Shared Core/integration contract shape diverges from SolaStock migrations: '.implode(', ', $divergent).'.');
    }
    echo CanonicalJson::encode([
        'collision_count' => count($contracts),
        'contract_schema_sha256' => $actualHash,
        'result' => 'PASS',
    ]);
    exit(0);
}

if ($mode === 'postflight') {
    $reference = $readSnapshot($argv[2] ?? '');
    $before = $readSnapshot($argv[3] ?? '');
    $after = $readSnapshot($argv[4] ?? '');
    $rerun = $readSnapshot($argv[5] ?? '');
    $financeOwnershipPath = $config['canonical_solacount_candidate']['root'].'/manifests/object-ownership.json';
    $financeOwnership = json_decode((string) file_get_contents($financeOwnershipPath), true, 512, JSON_THROW_ON_ERROR);
    $financeTables = [];
    foreach ($financeOwnership['objects'] ?? [] as $object) {
        if (($object['object_type'] ?? null) === 'table' && ($object['capability'] ?? null) === 'solacount.finance') {
            $financeTables[] = $object['object_name'];
        }
    }
    $financeTables = array_values(array_unique($financeTables));
    sort($financeTables, SORT_STRING);
    $financeBeforeHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($before, $financeTables));
    $financeAfterHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($after, $financeTables));
    if (! hash_equals($financeBeforeHash, $financeAfterHash)) {
        throw new RuntimeException('SolaCount accounting schema changed while applying SolaStock.');
    }
    $stockTables = array_keys($ownedTables($config));
    $referenceHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($reference, $stockTables));
    $afterHash = SchemaSnapshot::hash(SchemaSnapshot::forTables($after, $stockTables));
    if (! hash_equals($referenceHash, $afterHash)) {
        throw new RuntimeException('Composite SolaStock schema differs from full-history SolaStock reference.');
    }
    $afterFullHash = SchemaSnapshot::hash($after);
    $rerunFullHash = SchemaSnapshot::hash($rerun);
    if (! hash_equals($afterFullHash, $rerunFullHash)) {
        throw new RuntimeException('SolaStock migration rerun changed the composite schema.');
    }

    $integrationSafety = require $root.'/config/integration_safety.php';
    $transport = require $root.'/config/integration_transport.php';
    if ($integrationSafety['solabooks_delivery_enabled'] !== false
        || $integrationSafety['legacy_finance_inventory_writes_blocked'] !== true
        || $integrationSafety['historical_repair_enabled'] !== false
        || $integrationSafety['pending_event_replay_enabled'] !== false
        || $transport['contract_version'] !== 'solastock-journal.v2'
        || SolaStockJournalContract::VERSION !== 'solastock-journal.v2'
        || $config['integration_invariants']['base_currency'] !== 'JOD') {
        throw new RuntimeException('SolaStock receiver-v2, safety-hold, or JOD integration invariant failed.');
    }

    $migrationFiles = glob($root.'/database/migrations/tenant/*.php') ?: [];
    sort($migrationFiles, SORT_STRING);
    $ledger = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $expectedMigrations = array_map(static fn (string $path): string => basename($path, '.php'), $migrationFiles);
    $stockLedger = array_values(array_intersect($ledger, $expectedMigrations));
    sort($stockLedger, SORT_STRING);
    if ($stockLedger !== $expectedMigrations) {
        throw new RuntimeException('SolaStock represented migration ledger is incomplete or out of order.');
    }

    $verification = [
        'format' => 'solastock.spsb-verification.v1',
        'result' => 'PASS',
        'database' => $database,
        'migration_order' => ['shared-core', 'solacount', 'solastock.inventory'],
        'represented_migrations' => count($expectedMigrations),
        'finance_accounting_schema_sha256_before' => $financeBeforeHash,
        'finance_accounting_schema_sha256_after' => $financeAfterHash,
        'solastock_schema_sha256' => $afterHash,
        'composite_schema_sha256' => $afterFullHash,
        'rerun_schema_sha256' => $rerunFullHash,
        'integration_contract' => $config['integration_invariants'],
    ];
    $path = $safePath($argv[6] ?? '');
    file_put_contents($path, CanonicalJson::encode($verification), LOCK_EX);
    chmod($path, 0600);
    echo CanonicalJson::encode($verification);
    exit(0);
}

if ($mode === 'generate') {
    $snapshot = $readSnapshot($argv[2] ?? '');
    $verificationPath = $safePath($argv[3] ?? '');
    $verification = json_decode((string) file_get_contents($verificationPath), true, 512, JSON_THROW_ON_ERROR);
    if (($verification['result'] ?? null) !== 'PASS'
        || ! hash_equals((string) $verification['composite_schema_sha256'], SchemaSnapshot::hash($snapshot))) {
        throw new RuntimeException('REFUSING: candidate generation requires passing lifecycle guards.');
    }
    $applicationSha = (string) getenv('SPSB_APPLICATION_CANDIDATE_SHA');
    $headSha = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
    if (preg_match('/\A[a-f0-9]{40}\z/D', $applicationSha) !== 1 || ! hash_equals($headSha, $applicationSha)) {
        throw new RuntimeException('REFUSING: application candidate SHA is not pinned to HEAD.');
    }
    $owners = $ownedTables($config);
    $migrationFiles = glob($root.'/database/migrations/tenant/*.php') ?: [];
    sort($migrationFiles, SORT_STRING);
    $represented = [];
    foreach ($migrationFiles as $path) {
        $represented[] = [
            'application' => 'SolaStock',
            'group' => $config['migration_group']['id'],
            'migration' => basename($path, '.php'),
            'source_sha256' => hash_file('sha256', $path),
        ];
    }
    $ownershipObjects = [];
    foreach ($owners as $table => $classification) {
        $ownershipObjects[] = [
            'object_name' => $table,
            'object_type' => 'table',
            'owner' => $classification === 'solastock_owned' ? 'solastock.inventory' : ($classification === 'integration_contract' ? 'integration.solastock-solacount' : 'shared-core'),
            'classification' => strtoupper($classification),
        ];
    }
    $ownershipText = CanonicalJson::encode([
        'format' => 'spsb.object-ownership.v1',
        'application' => 'SolaStock',
        'objects' => $ownershipObjects,
    ]);
    $migrationText = CanonicalJson::encode([
        'format' => 'spsb.represented-migrations.v1',
        'application' => 'SolaStock',
        'group' => $config['migration_group'],
        'migrations' => $represented,
    ]);
    $compatibilityInput = CanonicalJson::encode([
        'schema' => SchemaSnapshot::forTables($snapshot, array_keys($owners)),
        'ownership_sha256' => hash('sha256', $ownershipText),
        'represented_migrations_sha256' => hash('sha256', $migrationText),
    ]);
    $compatibilitySha = hash('sha256', $compatibilityInput);
    $bundleId = 'solastock-sv1-b0001-'.substr($compatibilitySha, 0, 8);
    $outputRoot = $safePath(($argv[4] ?? '').'/placeholder');
    $output = dirname($outputRoot).'/'.$bundleId;
    if (file_exists($output) || ! mkdir($output.'/schema', 0700, true) || ! mkdir($output.'/fingerprints', 0700) || ! mkdir($output.'/manifests', 0700)) {
        throw new RuntimeException('REFUSING: immutable candidate output already exists or cannot be created.');
    }
    file_put_contents($output.'/manifests/object-ownership.json', $ownershipText, LOCK_EX);
    file_put_contents($output.'/represented-migrations.json', $migrationText, LOCK_EX);
    file_put_contents($output.'/manifests/forbidden-foreign-authority.json', CanonicalJson::encode([
        'format' => 'spsb.forbidden-foreign-authority.v1',
        'objects' => array_map(static fn (string $table): array => ['object_type' => 'table', 'object_name' => $table, 'reason' => 'SolaCount-or-Shared-Core-authority'], $config['forbidden_foreign_authority']),
    ]), LOCK_EX);
    foreach (['tables', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'] as $class) {
        file_put_contents($output.'/fingerprints/'.str_replace('_', '-', $class).'.json', CanonicalJson::encode([
            'format' => 'spsb.fingerprint.v1',
            'object_class' => $class,
            'rows' => SchemaSnapshot::forTables($snapshot, array_keys($owners))[$class],
        ]), LOCK_EX);
    }
    $fragments = [];
    foreach ($owners as $table => $classification) {
        $statement = $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`')->fetch(PDO::FETCH_ASSOC);
        $sql = (string) ($statement['Create Table'] ?? '');
        if ($sql === '') {
            throw new RuntimeException("Missing SHOW CREATE TABLE output for {$table}.");
        }
        $relative = 'schema/'.$table.'.sql';
        $text = "-- SPSB OFFICIAL-CANDIDATE / STATUS-CANDIDATE / NOT-APPROVED\n-- application: SolaStock; classification: ".strtoupper($classification)."\n{$sql};\n";
        file_put_contents($output.'/'.$relative, $text, LOCK_EX);
        $fragments[] = ['table' => $table, 'classification' => strtoupper($classification), 'path' => $relative, 'sha256' => hash('sha256', $text)];
    }
    $generatedAt = gmdate('Y-m-d\TH:i:s\Z', (int) getenv('SOURCE_DATE_EPOCH'));
    $manifest = [
        'format_version' => 'spsb.bundle.v1',
        'application' => 'SolaStock',
        'app_key' => 'solastock',
        'bundle_id' => $bundleId,
        'status' => 'candidate',
        'approval' => 'NOT-APPROVED',
        'schema_version' => 1,
        'baseline_version' => 1,
        'generated_at' => $generatedAt,
        'application_candidate_sha' => $applicationSha,
        'canonical_solacount_candidate' => $config['canonical_solacount_candidate']['id'],
        'migration_order' => ['shared-core', 'solacount', 'solastock.inventory'],
        'schema_compatibility_sha256' => $compatibilitySha,
        'ownership_manifest_sha256' => hash('sha256', $ownershipText),
        'migration_manifest_sha256' => hash('sha256', $migrationText),
        'schema_object_counts' => [
            'tables' => count($owners),
            'columns' => count(SchemaSnapshot::forTables($snapshot, array_keys($owners))['columns']),
            'indexes' => count(SchemaSnapshot::forTables($snapshot, array_keys($owners))['indexes']),
            'foreign_keys' => count(SchemaSnapshot::forTables($snapshot, array_keys($owners))['foreign_keys']),
            'checks' => count(SchemaSnapshot::forTables($snapshot, array_keys($owners))['checks']),
            'triggers' => count(SchemaSnapshot::forTables($snapshot, array_keys($owners))['triggers']),
            'represented_migrations' => count($represented),
        ],
        'ownership_counts' => [
            'SOLASTOCK_OWNED' => count($config['ownership']['solastock_owned']),
            'SHARED_CORE_CONTRIBUTOR' => count($config['ownership']['shared_core_contributor']),
            'INTEGRATION_CONTRACT' => count($config['ownership']['integration_contract']),
            'FORBIDDEN_DUPLICATE_COLLISION' => 0,
        ],
        'integration_contract' => $config['integration_invariants'],
        'fragments' => $fragments,
    ];
    $manifest['content_id'] = 'sha256:'.hash('sha256', CanonicalJson::encode($manifest));
    file_put_contents($output.'/manifest.json', CanonicalJson::encode($manifest), LOCK_EX);
    file_put_contents($output.'/verification.json', CanonicalJson::encode($verification), LOCK_EX);
    file_put_contents($output.'/VERIFY.md', "# SolaStock SPSB candidate\n\nStatus: immutable official candidate; not approved and not deployed.\n\nLifecycle: Shared Core -> SolaCount -> SolaStock -> verification -> rerun.\n", LOCK_EX);
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getBasename() !== 'CHECKSUMS.sha256') {
            $relative = substr($file->getPathname(), strlen($output) + 1);
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    $catalog = '';
    foreach ($files as $relative => $hash) {
        $catalog .= $hash.'  '.$relative."\n";
    }
    file_put_contents($output.'/CHECKSUMS.sha256', $catalog, LOCK_EX);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        chmod($file->getPathname(), $file->isDir() ? 0555 : 0444);
    }
    chmod($output, 0555);
    echo CanonicalJson::encode(['bundle_id' => $bundleId, 'candidate_root' => $output, 'manifest_sha256' => hash_file('sha256', $output.'/manifest.json')]);
    exit(0);
}

throw new RuntimeException('Usage: schema-tool.php snapshot|preflight|postflight|generate ...');
