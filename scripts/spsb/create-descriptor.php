<?php

declare(strict_types=1);

use App\Spsb\CanonicalJson;
use App\Spsb\Guard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getenv('SPSB_RUN_ROOT');
$database = (string) getenv('DB_DATABASE');
$socket = (string) getenv('DB_SOCKET');
$challenge = (string) getenv('SPSB_CHALLENGE_NONCE');
$descriptorPath = (string) getenv('SPSB_DESCRIPTOR_PATH');
$keyPath = (string) getenv('SPSB_HMAC_KEY_PATH');
$sealPath = (string) getenv('SPSB_SEAL_PATH');
if (preg_match('#\A/tmp/solastock-spsb\.[A-Za-z0-9]{8,16}\z#D', $root) !== 1
    || preg_match(Guard::DATABASE_PATTERN, $database) !== 1
    || preg_match('/\A[a-f0-9]{64}\z/D', $challenge) !== 1
    || dirname($descriptorPath) !== $root || dirname($keyPath) !== $root || dirname($sealPath) !== $root
    || ! is_file($keyPath) || is_link($keyPath)) {
    throw new RuntimeException('REFUSING: unsafe SolaStock SPSB descriptor request.');
}
$payload = CanonicalJson::encode([
    'format' => 'solastock.spsb-guard.v1',
    'run_root' => $root,
    'socket' => $socket,
    'database' => $database,
    'challenge' => $challenge,
]);
file_put_contents($descriptorPath, $payload, LOCK_EX);
chmod($descriptorPath, 0600);
$seal = hash_hmac('sha256', $payload, (string) file_get_contents($keyPath));
file_put_contents($sealPath, $seal."\n", LOCK_EX);
chmod($sealPath, 0600);
echo $seal."\n";
