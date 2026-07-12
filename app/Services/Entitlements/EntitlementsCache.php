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

        $syncedAt ??= now();

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
                    $this->parseStored($existing->synced_at),
                    [
                        'pushed_at' => $this->parseStored($existing->pushed_at),
                        'evaluated_at' => $this->parseStored($existing->evaluated_at),
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
                'updated_at' => now(),
            ];

            foreach (['evaluated_at', 'pushed_at', 'valid_until', 'schema_version', 'source_version', 'state_hash'] as $column) {
                if (array_key_exists($column, $metadata) && Schema::connection('tenant')->hasColumn('tenant_entitlements_snapshots', $column)) {
                    $values[$column] = in_array($column, ['evaluated_at', 'pushed_at', 'valid_until'], true) && $metadata[$column]
                        ? Carbon::parse((string) $metadata[$column])->setTimezone(config('app.timezone'))
                        : $metadata[$column];
                }
            }

            DB::connection('tenant')->table('tenant_entitlements_snapshots')->updateOrInsert(
                ['client_id' => $clientId, 'project_slug' => $projectSlug],
                $values + ['created_at' => now()]
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
            $validUntil = $this->parseStored($row->valid_until);
            $pushedAt = $this->parseStored($row->pushed_at);
            $syncedAt = $this->parseStored($row->synced_at);
            $evaluatedAt = $this->parseStored($row->evaluated_at);
            $ageAnchor = $pushedAt ?: $syncedAt;

            $payload['_snapshot'] = [
                'version' => (string) $row->version,
                'synced_at' => $syncedAt?->toIso8601String(),
                'evaluated_at' => $evaluatedAt?->toIso8601String(),
                'pushed_at' => $pushedAt?->toIso8601String(),
                'valid_until' => $validUntil?->toIso8601String(),
                'schema_version' => $row->schema_version ?? null,
                'source_version' => $row->source_version ?? null,
                'state_hash' => $row->state_hash ?? null,
                'stale' => $validUntil ? $validUntil->lte(now()) : false,
                'beyond_max_stale' => $ageAnchor ? $ageAnchor->copy()->addMinutes((int) config('inventory_entitlements.max_stale_minutes', 1440))->lte(now()) : false,
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

    /**
     * Interpret a value stored in `tenant_entitlements_snapshots` as APP-local
     * time (Asia/Amman), matching how finance/hr/projects write theirs.
     *
     * These columns used to be written in UTC by this app alone, which left
     * SolaStock's rows a constant 3h behind its siblings for the same push —
     * harmless to SolaStock's own (self-consistent) staleness math, but it made
     * every cross-app freshness comparison and stale-snapshot monitor read
     * SolaStock as permanently stale. Write and read now both use app time.
     */
    private function parseStored(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            if ($value instanceof Carbon) {
                return $value->copy();
            }

            return Carbon::parse((string) $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function snapshotOrderBoundary(?Carbon $syncedAt, array $metadata): ?Carbon
    {
        foreach (['pushed_at', 'evaluated_at'] as $key) {
            if (! empty($metadata[$key])) {
                try {
                    if ($metadata[$key] instanceof Carbon) {
                        return $metadata[$key]->copy();
                    }

                    return Carbon::parse((string) $metadata[$key]);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $syncedAt?->copy();
    }
}
