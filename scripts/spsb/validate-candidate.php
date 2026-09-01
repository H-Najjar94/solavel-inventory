<?php

declare(strict_types=1);

use App\Spsb\CanonicalJson;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$candidate = realpath($argv[1] ?? '');
$expectedParent = realpath((string) getenv('SPSB_RUN_ROOT').'/candidate-staging');
if ($candidate === false || $expectedParent === false || dirname($candidate) !== $expectedParent || is_link($candidate)) {
    throw new RuntimeException('REFUSING: unsafe candidate validation path.');
}
$manifestPath = $candidate.'/manifest.json';
$catalogPath = $candidate.'/CHECKSUMS.sha256';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['application'] ?? null) !== 'SolaStock'
    || ($manifest['status'] ?? null) !== 'candidate'
    || ($manifest['approval'] ?? null) !== 'NOT-APPROVED'
    || ($manifest['migration_order'] ?? null) !== ['shared-core', 'solacount', 'solastock.inventory']) {
    throw new RuntimeException('Candidate identity, approval, or order is invalid.');
}
$catalog = file($catalogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$seen = [];
foreach ($catalog as $line) {
    if (preg_match('/\A([a-f0-9]{64})  ([A-Za-z0-9_.\/-]+)\z/D', $line, $match) !== 1 || str_contains($match[2], '..')) {
        throw new RuntimeException('Candidate checksum catalog is malformed.');
    }
    $path = $candidate.'/'.$match[2];
    if (! is_file($path) || is_link($path) || ! hash_equals($match[1], hash_file('sha256', $path))) {
        throw new RuntimeException('Candidate checksum mismatch: '.$match[2]);
    }
    $seen[$match[2]] = true;
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($candidate) + 1);
    if ($file->isLink() || ($relative !== 'CHECKSUMS.sha256' && ! isset($seen[$relative]))) {
        throw new RuntimeException('Candidate contains an unsealed path: '.$relative);
    }
}
$represented = json_decode((string) file_get_contents($candidate.'/represented-migrations.json'), true, 512, JSON_THROW_ON_ERROR);
if (count($represented['migrations'] ?? []) !== ($manifest['schema_object_counts']['represented_migrations'] ?? -1)) {
    throw new RuntimeException('Candidate represented migration count mismatch.');
}
echo CanonicalJson::encode([
    'bundle_id' => $manifest['bundle_id'],
    'manifest_sha256' => hash_file('sha256', $manifestPath),
    'catalog_sha256' => hash_file('sha256', $catalogPath),
    'result' => 'PASS',
]);
