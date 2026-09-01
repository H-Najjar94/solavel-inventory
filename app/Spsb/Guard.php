<?php

namespace App\Spsb;

use PDO;
use RuntimeException;

final class Guard
{
    public const DATABASE_PATTERN = '/\Aspsb_probe_solastock_[a-z0-9_]{1,38}\z/D';

    public static function assertEnvironment(PDO $pdo, string $database): void
    {
        $root = (string) getenv('SPSB_RUN_ROOT');
        $descriptorPath = (string) getenv('SPSB_DESCRIPTOR_PATH');
        $keyPath = (string) getenv('SPSB_HMAC_KEY_PATH');
        $seal = (string) getenv('SPSB_DESCRIPTOR_SEAL');

        if (getenv('APP_ENV') !== 'testing'
            || getenv('TEST_DATABASE_ENVIRONMENT') !== 'spsb_guarded_isolated'
            || getenv('TEST_DATABASE_PREFIX') !== 'spsb_probe_solastock_'
            || preg_match(self::DATABASE_PATTERN, $database) !== 1
            || str_contains($database, 'tenant')) {
            throw new RuntimeException('REFUSING: invalid SolaStock SPSB database guard context.');
        }
        if (preg_match('#\A/tmp/solastock-spsb\.[A-Za-z0-9]{8,16}\z#D', $root) !== 1
            || ! is_dir($root) || is_link($root)
            || ! self::regularPrivateFile($descriptorPath)
            || ! self::regularPrivateFile($keyPath)
            || preg_match('/\A[a-f0-9]{64}\z/D', $seal) !== 1) {
            throw new RuntimeException('REFUSING: invalid SolaStock SPSB descriptor inputs.');
        }

        $descriptor = (string) file_get_contents($descriptorPath);
        $key = (string) file_get_contents($keyPath);
        if (! hash_equals(hash_hmac('sha256', $descriptor, $key), $seal)) {
            throw new RuntimeException('REFUSING: SolaStock SPSB descriptor seal mismatch.');
        }
        $payload = json_decode($descriptor, true, 32, JSON_THROW_ON_ERROR);
        if (($payload['run_root'] ?? null) !== $root
            || ($payload['socket'] ?? null) !== getenv('DB_SOCKET')
            || ($payload['database'] ?? null) !== $database
            || ! is_string($payload['challenge'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $payload['challenge']) !== 1) {
            throw new RuntimeException('REFUSING: SolaStock SPSB descriptor binding mismatch.');
        }
        $selected = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $challenge = (string) $pdo->query('SELECT challenge_nonce FROM spsb_guard_marker WHERE marker_id = 1')->fetchColumn();
        if ($selected !== $database || ! hash_equals($payload['challenge'], $challenge)) {
            throw new RuntimeException('REFUSING: SolaStock SPSB database marker mismatch.');
        }
    }

    private static function regularPrivateFile(string $path): bool
    {
        if ($path === '' || ! is_file($path) || is_link($path)) {
            return false;
        }
        $mode = fileperms($path);

        return $mode !== false && ($mode & 0077) === 0;
    }
}
