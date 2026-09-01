<?php

namespace App\Services\Integration;

use RuntimeException;

final class TransportWorkerHeartbeat
{
    public function write(string $state, int $targets, int $processed): void
    {
        $path = (string) config('integration_transport.supervisor.heartbeat_path');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the transport worker heartbeat directory.');
        }
        $payload = json_encode([
            'contract_version' => 'solastock-finance-worker.v1',
            'state' => $state,
            'approved_targets' => $targets,
            'processed' => $processed,
            'release_sha' => trim((string) @file_get_contents(base_path('RELEASE_SHA'))),
            'updated_at' => now('UTC')->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = $path.'.tmp.'.getmypid();
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! chmod($temporary, 0640) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish the transport worker heartbeat.');
        }
    }
}
