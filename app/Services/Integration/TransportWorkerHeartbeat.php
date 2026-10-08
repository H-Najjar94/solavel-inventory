<?php

namespace App\Services\Integration;

use RuntimeException;

final class TransportWorkerHeartbeat
{
    /** Production supervisor proof, scoped to the authoritative commercial target registry. */
    public function runningFor(int $clientId, int $organizationId): bool
    {
        $path=(string)config('integration_transport.supervisor.heartbeat_path');
        if (! is_readable($path)) return false;
        try {
            $heartbeat=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            $release=trim((string)@file_get_contents(base_path('RELEASE_SHA')));
            if (! $this->isCurrent($heartbeat,$release)) return false;
            foreach (app(ApprovedTransportTargetRegistry::class)->targets() as $target) {
                if ($target['client_id']===$clientId && $target['organization_id']===$organizationId
                    && $target['database']===\Illuminate\Support\Facades\DB::connection('tenant')->getDatabaseName()) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    public function isCurrent(array $heartbeat, string $release): bool
    {
        try {
            if (($heartbeat['contract_version']??null)!=='solastock-finance-worker.v1'
                || ($heartbeat['state']??null)!=='running' || $release===''
                || ! hash_equals($release,(string)($heartbeat['release_sha']??''))) return false;
            $time=\Carbon\Carbon::parse($heartbeat['updated_at']??'1970-01-01');
            return $time->gte(now()->subMinutes(2)) && $time->lte(now()->addSeconds(10));
        } catch (\Throwable) {
            return false;
        }
    }

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
