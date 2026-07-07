<?php

namespace App\Services\Entitlements;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EntitlementsCache
{
    private const SNAPSHOT_CACHE_PREFIX = 'entitlements:snapshot:';
    private const CACHE_TTL = 600;

    public function storeProjectSnapshot(
        int $clientId,
        string $projectSlug,
        array $projectPayload,
        string $version,
        ?Carbon $syncedAt = null,
        array $metadata = []
    ): void {
        $projectSlug = strtolower(trim($projectSlug));
        if ($clientId <= 0 || $projectSlug === '') {
            return;
        }

        $syncedAt ??= now('UTC');

        try {
            if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
                return;
            }

            $incomingBoundary = $this->snapshotOrderBoundary($syncedAt, $metadata);
            $existing = DB::connection('tenant')->table('tenant_entitlements_snapshots')
                ->where('client_id', $clientId)
                ->where('project_slug', $projectSlug)
                ->first();

            if ($existing) {
                $existingBoundary = $this->snapshotOrderBoundary(
                    $existing->synced_at ? Carbon::parse((string) $existing->synced_at, 'UTC')->utc() : null,
                    [
                        'pushed_at' => $existing->pushed_at ? Carbon::parse((string) $existing->pushed_at, 'UTC')->utc() : null,
                        'evaluated_at' => $existing->evaluated_at ? Carbon::parse((string) $existing->evaluated_at, 'UTC')->utc() : null,
                    ]
                );

                if ($incomingBoundary && $existingBoundary && $incomingBoundary->lt($existingBoundary)) {
                    Log::info('Ignored older SolaStock entitlement snapshot', [
                        'client_id' => $clientId,
                        'project_slug' => $projectSlug,
                        'incoming_boundary' => $incomingBoundary->toIso8601String(),
                        'existing_boundary' => $existingBoundary->toIso8601String(),
                    ]);

                    return;
                }
            }

            $values = [
                'payload' => json_encode($projectPayload, JSON_UNESCAPED_SLASHES),
                'version' => $version,
                'synced_at' => $syncedAt,
                'updated_at' => now('UTC'),
            ];

            foreach (['evaluated_at', 'pushed_at', 'valid_until', 'schema_version', 'source_version', 'state_hash'] as $column) {
                if (array_key_exists($column, $metadata) && Schema::connection('tenant')->hasColumn('tenant_entitlements_snapshots', $column)) {
                    $values[$column] = in_array($column, ['evaluated_at', 'pushed_at', 'valid_until'], true) && $metadata[$column]
                        ? Carbon::parse((string) $metadata[$column])->utc()
                        : $metadata[$column];
                }
            }

            DB::connection('tenant')->table('tenant_entitlements_snapshots')->updateOrInsert(
                ['client_id' => $clientId, 'project_slug' => $projectSlug],
                $values + ['created_at' => now('UTC')]
            );

            Cache::put($this->snapshotCacheKey($clientId, $projectSlug), [
                'payload' => $projectPayload,
                'version' => $version,
                'synced_at' => $syncedAt->toIso8601String(),
                'valid_until' => $metadata['valid_until'] ?? null,
                'state_hash' => $metadata['state_hash'] ?? null,
            ], self::CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('Failed to store SolaStock entitlement snapshot', [
                'client_id' => $clientId,
                'project_slug' => $projectSlug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getProjectSnapshot(int $clientId, ?string $projectSlug = null): ?array
    {
        $projectSlug ??= (string) config('inventory_entitlements.project_slug', 'inventory');
        $projectSlug = strtolower(trim($projectSlug));
        if ($clientId <= 0 || $projectSlug === '') {
            return null;
        }

        $cached = Cache::get($this->snapshotCacheKey($clientId, $projectSlug));
        if (is_array($cached) && isset($cached['payload']) && is_array($cached['payload'])) {
            return $cached['payload'];
        }

        try {
            if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
                return null;
            }

            $row = DB::connection('tenant')->table('tenant_entitlements_snapshots')
                ->where('client_id', $clientId)
                ->where('project_slug', $projectSlug)
                ->first();

            if (! $row) {
                return null;
            }

            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $validUntil = $row->valid_until ? Carbon::parse((string) $row->valid_until, 'UTC')->utc() : null;
            $pushedAt = $row->pushed_at ? Carbon::parse((string) $row->pushed_at, 'UTC')->utc() : null;
            $syncedAt = $row->synced_at ? Carbon::parse((string) $row->synced_at, 'UTC')->utc() : null;
            $ageAnchor = $pushedAt ?: $syncedAt;

            $payload['_snapshot'] = [
                'version' => (string) $row->version,
                'synced_at' => $syncedAt?->toIso8601String(),
                'evaluated_at' => $row->evaluated_at ? Carbon::parse((string) $row->evaluated_at, 'UTC')->utc()->toIso8601String() : null,
                'pushed_at' => $pushedAt?->toIso8601String(),
                'valid_until' => $validUntil?->toIso8601String(),
                'schema_version' => $row->schema_version ?? null,
                'source_version' => $row->source_version ?? null,
                'state_hash' => $row->state_hash ?? null,
                'stale' => $validUntil ? $validUntil->lte(now('UTC')) : false,
                'beyond_max_stale' => $ageAnchor ? $ageAnchor->copy()->addMinutes((int) config('inventory_entitlements.max_stale_minutes', 1440))->lte(now('UTC')) : false,
            ];

            return $payload;
        } catch (\Throwable $e) {
            Log::warning('Failed to load SolaStock entitlement snapshot', [
                'client_id' => $clientId,
                'project_slug' => $projectSlug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function currentClientId(): ?int
    {
        $request = request();
        $state = $request?->attributes->get('tenant_state');
        if (is_array($state) && (int) ($state['client_id'] ?? 0) > 0) {
            return (int) $state['client_id'];
        }

        $sessionClient = $request?->hasSession() ? $request->session()->get('client_id') : null;

        return $sessionClient ? (int) $sessionClient : null;
    }

    private function snapshotCacheKey(int $clientId, string $projectSlug): string
    {
        return self::SNAPSHOT_CACHE_PREFIX . $clientId . ':' . $projectSlug;
    }

    private function snapshotOrderBoundary(?Carbon $syncedAt, array $metadata): ?Carbon
    {
        foreach (['pushed_at', 'evaluated_at'] as $key) {
            if (! empty($metadata[$key])) {
                try {
                    if ($metadata[$key] instanceof Carbon) {
                        return $metadata[$key]->copy()->utc();
                    }

                    return Carbon::parse((string) $metadata[$key])->utc();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $syncedAt?->copy()->utc();
    }
}
