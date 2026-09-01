<?php

declare(strict_types=1);

use App\Spsb\Guard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$candidate = '/var/www/html/solavel-finance/resources/spsb/candidates/solacount-sv2-b0004-362a2189';
$relative = $argv[1] ?? '';
if (preg_match('#\Aschema/[A-Za-z0-9_.\/-]+\.sql\z#D', $relative) !== 1 || str_contains($relative, '..')) {
    throw new RuntimeException('REFUSING: invalid canonical fragment path.');
}
$path = realpath($candidate.'/'.$relative);
if ($path === false || ! str_starts_with($path, $candidate.'/') || is_link($path)) {
    throw new RuntimeException('REFUSING: canonical fragment is unavailable or unsafe.');
}
$manifest = json_decode((string) file_get_contents($candidate.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$declared = null;
foreach ($manifest['fragments'] ?? [] as $fragment) {
    if (($fragment['path'] ?? null) === $relative) {
        $declared = $fragment;
        break;
    }
}
if (! is_array($declared) || ! hash_equals((string) $declared['sha256'], hash_file('sha256', $path))) {
    throw new RuntimeException('REFUSING: canonical fragment is not sealed by the pinned manifest.');
}
$database = (string) getenv('DB_DATABASE');
$socket = (string) getenv('DB_SOCKET');
$pdo = new PDO("mysql:unix_socket={$socket};dbname={$database};charset=utf8mb4", (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
Guard::assertEnvironment($pdo, $database);
$sql = (string) file_get_contents($path);
preg_match_all('/-- SPSB:BEGIN\R(.*?)\R-- SPSB:END/s', $sql, $matches);
if (($matches[1] ?? []) === []) {
    throw new RuntimeException('REFUSING: canonical fragment contains no bounded SPSB statements.');
}
foreach ($matches[1] as $statement) {
    $pdo->exec(trim($statement));
}
