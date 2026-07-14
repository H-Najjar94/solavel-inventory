<?php

namespace App\Services\Entitlements;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EntitlementsCache
{
    private const SNAPSHOT_CACHE_PREFIX = 'entitlements:snapshot:';
    private const CACHE_TTL = 600;

    /** Reserved payload key carrying the monotonic central evaluation revision. */
    public const REVISION_KEY = '_revision';

    /* ---------------------------------------------------------------------
     * Verification states. The gate MUST distinguish these — collapsing them
     * into one boolean is what locked a paid Premium customer out of a feature
     * they owned when a push failed and was never retried.
     * ------------------------------------------------------------------- */

    /** Never received a verified snapshot. Fail CLOSED — we know nothing. */
    public const STATE_MISSING = 'missing';

    /** Snapshot is fresh and authoritative. */
    public const STATE_VERIFIED = 'verified';

    /** Refresh is overdue. Last-known-good is served; the infra failure is alerted. */
    public const STATE_GRACE = 'grace';

    /** Grace exhausted. Restrict, and say WHY — this is an infra failure, not a plan limit. */
    public const STATE_GRACE_EXPIRED = 'grace_expired';

    public function storeProjectSnapshot(
        int $clientId,
        string $projectSlug,
        array $projectPayload,
        string $version,
        ?CarbonInterface $syncedAt = null,
        array $metadata = []
    ): void {
        $projectSlug = strtolower(trim($projectSlug));
        if ($clientId <= 0 || $projectSlug === '') {
            return;
        }

        // Every timestamp we persist is an absolute UTC instant. `now()` is
        // Asia/Amman here and must never reach an entitlement column. This
        // REVERSES d0ea747 ("store snapshot timestamps in app time, not UTC"),
        // which is what put every live SolaStock row 3h into the future.
        $syncedAtUtc = ($syncedAt ? EntitlementClock::parse($syncedAt) : null) ?? EntitlementClock::now();

        try {
            if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
                return;
            }

            $incomingRevision = $this->revisionOf($metadata, $projectPayload);
            $incomingPushedAt = $this->orderingInstant($syncedAtUtc, $metadata);
            $incomingHash = isset($metadata['state_hash']) ? (string) $metadata['state_hash'] : null;

            $existing = DB::connection('tenant')
                ->table('tenant_entitlements_snapshots')
                ->where('client_id', $clientId)
                ->where('project_slug', $projectSlug)
                ->first();

            if ($existing) {
                $existingPayload = json_decode((string) $existing->payload, true);
                $existingRevision = is_array($existingPayload)
                    ? $this->revisionOf([], $existingPayload)
                    : null;
                $existingPushedAt = $this->orderingInstant(
                    EntitlementClock::parse($existing->synced_at ?? null),
                    [
                        'pushed_at' => $existing->pushed_at ?? null,
                        'evaluated_at' => $existing->evaluated_at ?? null,
                    ]
                );

                $decision = $this->orderingDecision(
                    $incomingRevision,
                    $existingRevision,
                    $incomingPushedAt,
                    $existingPushedAt
                );

                if ($decision === 'older') {
                    // NEVER silently discard. An out-of-order delivery is expected under
                    // retry; a *systematically* rejected snapshot means the ordering keys
                    // themselves are broken, and that must be visible.
                    Log::warning('Rejected out-of-order entitlement snapshot', [
                        'client_id' => $clientId,
                        'project_slug' => $projectSlug,
                        'reason' => 'incoming_snapshot_is_older',
                        'incoming_revision' => $incomingRevision,
                        'stored_revision' => $existingRevision,
                        'incoming_pushed_at_utc' => EntitlementClock::iso($incomingPushedAt),
                        'stored_pushed_at_utc' => EntitlementClock::iso($existingPushedAt),
                    ]);

                    return;
                }

                if ($decision === 'duplicate') {
                    // Idempotent: the same revision delivered twice (retry, replay, or a
                    // duplicate heartbeat) must not churn the row or the cache.
                    Log::debug('Ignored duplicate entitlement snapshot', [
                        'client_id' => $clientId,
                        'project_slug' => $projectSlug,
                        'revision' => $incomingRevision,
                        'state_hash' => $incomingHash,
                    ]);

                    return;
                }
            }

            // Carry the monotonic revision INSIDE the payload: the runtime tenant DB
            // user is DML-only (no DDL), so we cannot add a column for it. Epoch-millis
            // of central's evaluation is monotonic and timezone-proof by construction.
            $storedPayload = $projectPayload;
            if ($incomingRevision !== null) {
                $storedPayload[self::REVISION_KEY] = $incomingRevision;
            }

            $values = [
                'payload' => json_encode($storedPayload, JSON_UNESCAPED_SLASHES),
                'version' => $version,
                'synced_at' => EntitlementClock::format($syncedAtUtc),
            ];

            // Timestamps are guarded like the other optional columns: the table is
            // shared per-client across apps, and on some tenants it was created by a
            // sibling app WITHOUT created_at/updated_at — hardcoding them made every
            // snapshot push fail → stale entitlements.
            if (Schema::connection('tenant')->hasColumn('tenant_entitlements_snapshots', 'updated_at')) {
                $values['updated_at'] = EntitlementClock::format(EntitlementClock::now());
            }

            foreach (['evaluated_at', 'pushed_at', 'valid_until', 'schema_version', 'source_version', 'state_hash'] as $column) {
                if (! array_key_exists($column, $metadata)) {
                    continue;
                }
                if (! Schema::connection('tenant')->hasColumn('tenant_entitlements_snapshots', $column)) {
                    continue;
                }

                $values[$column] = in_array($column, ['evaluated_at', 'pushed_at', 'valid_until'], true)
                    ? EntitlementClock::format(EntitlementClock::parse($metadata[$column]))
                    : $metadata[$column];
            }

            $insertExtra = Schema::connection('tenant')->hasColumn('tenant_entitlements_snapshots', 'created_at')
                ? ['created_at' => EntitlementClock::format(EntitlementClock::now())]
                : [];

            DB::connection('tenant')->table('tenant_entitlements_snapshots')->updateOrInsert(
                ['client_id' => $clientId, 'project_slug' => $projectSlug],
                $values + $insertExtra
            );

            Cache::put($this->snapshotCacheKey($clientId, $projectSlug), [
                'payload' => $storedPayload,
                'version' => $version,
                'synced_at' => EntitlementClock::iso($syncedAtUtc),
                // pushed_at drives the whole freshness state machine. Omitting it here
                // made the cached read fall back to synced_at and disagree with the DB read.
                'pushed_at' => EntitlementClock::iso(EntitlementClock::parse($metadata['pushed_at'] ?? null)),
                'evaluated_at' => EntitlementClock::iso(EntitlementClock::parse($metadata['evaluated_at'] ?? null)),
                'valid_until' => EntitlementClock::iso(EntitlementClock::parse($metadata['valid_until'] ?? null)),
                'schema_version' => $metadata['schema_version'] ?? null,
                'source_version' => $metadata['source_version'] ?? null,
                'state_hash' => $metadata['state_hash'] ?? null,
            ], self::CACHE_TTL);
        } catch (\Throwable $e) {
            // A store failure MUST be loud: central treats a 200 as delivered, so a
            // silently swallowed write here freezes the snapshot and eventually
            // restricts the customer with nothing in central's failure table to
            // show for it. Rethrow so the sync receiver 500s and central retries.
            Log::error('Failed to store SolaStock entitlement snapshot', [
                'client_id' => $clientId,
                'project_slug' => $projectSlug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
            return $this->decorateSnapshot($cached['payload'], $cached);
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
            if (! is_array($payload)) {
                return null;
            }

            return $this->decorateSnapshot($payload, [
                'version' => $row->version ?? null,
                'synced_at' => $row->synced_at ?? null,
                'evaluated_at' => $row->evaluated_at ?? null,
                'pushed_at' => $row->pushed_at ?? null,
                'valid_until' => $row->valid_until ?? null,
                'schema_version' => $row->schema_version ?? null,
                'source_version' => $row->source_version ?? null,
                'state_hash' => $row->state_hash ?? null,
            ]);
        } catch (\Throwable $e) {
            // A READ failure is not proof of de-entitlement. Return null and let the
            // gate fail closed on paid features while free permissions keep working.
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
     * The monotonic central evaluation revision (epoch millis), if present.
     *
     * PRIMARY ordering key. Absolute and timezone-proof: unlike a wall-clock
     * string it cannot be reinterpreted into a different instant by a server in a
     * different timezone.
     */
    private function revisionOf(array $metadata, array $payload = []): ?int
    {
        foreach ([$metadata['revision'] ?? null, $payload[self::REVISION_KEY] ?? null] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * SECONDARY ordering key: the absolute UTC instant the snapshot was pushed.
     */
    private function orderingInstant(?CarbonImmutable $syncedAt, array $metadata): ?CarbonImmutable
    {
        foreach (['pushed_at', 'evaluated_at'] as $key) {
            if (! empty($metadata[$key])) {
                $parsed = EntitlementClock::parse($metadata[$key]);
                if ($parsed) {
                    return $parsed;
                }
            }
        }

        return $syncedAt;
    }

    /**
     * Decide whether an incoming snapshot is newer, a duplicate, or older.
     *
     * Revision wins whenever both sides have one. Timestamps are only a fallback,
     * because timestamps are the thing that was historically wrong.
     *
     * A snapshot that HAS a revision always beats a stored row that has none: the
     * revision only exists on snapshots produced by the fixed pusher, so this is
     * the CUTOVER rule. Without it, SolaStock's existing rows — which carry a
     * local-time `pushed_at` three hours in the FUTURE (d0ea747) — would reject
     * every corrected UTC snapshot as "older" and freeze the tenant permanently.
     *
     * @return string one of: newer|duplicate|older
     */
    private function orderingDecision(
        ?int $incomingRevision,
        ?int $existingRevision,
        ?CarbonImmutable $incomingPushedAt,
        ?CarbonImmutable $existingPushedAt
    ): string {
        if ($incomingRevision !== null && $existingRevision !== null) {
            return match (true) {
                $incomingRevision > $existingRevision => 'newer',
                $incomingRevision === $existingRevision => 'duplicate',
                default => 'older',
            };
        }

        if ($incomingRevision !== null && $existingRevision === null) {
            return 'newer';
        }

        if ($incomingRevision === null && $existingRevision !== null) {
            return 'older';
        }

        if ($incomingPushedAt && $existingPushedAt) {
            return match (true) {
                $incomingPushedAt->gt($existingPushedAt) => 'newer',
                $incomingPushedAt->eq($existingPushedAt) => 'duplicate',
                default => 'older',
            };
        }

        return 'newer';
    }

    /**
     * How long after `pushed_at` a snapshot is still VERIFIED.
     *
     * Falls back to SolaStock's legacy `inventory_entitlements.max_stale_minutes`
     * so an operator who still sets ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES keeps a
     * sane boundary — but that value no longer DENIES, it only opens grace.
     */
    private function staleAfterMinutes(): int
    {
        $configured = config('entitlements.stale_after_minutes');

        if ($configured === null || $configured === '') {
            $configured = config('inventory_entitlements.max_stale_minutes', 1440);
        }

        return (int) $configured;
    }

    private function graceMinutes(): int
    {
        return (int) config('entitlements.grace_minutes', 4320);
    }

    /**
     * Resolve the verification state for a snapshot's push instant.
     *
     * @return array{state:string,pushed_at:?CarbonImmutable,age_minutes:?int}
     */
    public function verificationState(?CarbonImmutable $pushedAt): array
    {
        if (! $pushedAt) {
            return ['state' => self::STATE_MISSING, 'pushed_at' => null, 'age_minutes' => null];
        }

        $staleAfter = $this->staleAfterMinutes();
        $grace = $this->graceMinutes();

        $ageMinutes = (int) floor(($pushedAt->diffInSeconds(EntitlementClock::now(), false)) / 60);

        // A pushed_at in the FUTURE is a clock/timezone defect, not freshness.
        // Clamp it rather than trusting it — and never let it deny anyone. Every
        // live SolaStock row is future-dated right now (d0ea747), so this clamp is
        // what keeps today's tenants working while the corrected pushes land.
        if ($ageMinutes < 0) {
            $ageMinutes = 0;
        }

        $state = match (true) {
            $ageMinutes <= $staleAfter => self::STATE_VERIFIED,
            $ageMinutes <= $staleAfter + $grace => self::STATE_GRACE,
            default => self::STATE_GRACE_EXPIRED,
        };

        return ['state' => $state, 'pushed_at' => $pushedAt, 'age_minutes' => $ageMinutes];
    }

    private function decorateSnapshot(array $payload, array $metadata): array
    {
        $validUntil = EntitlementClock::parse($metadata['valid_until'] ?? null);
        $pushedAt = EntitlementClock::parse($metadata['pushed_at'] ?? null)
            ?: EntitlementClock::parse($metadata['synced_at'] ?? null);

        $verification = $this->verificationState($pushedAt);
        $state = $verification['state'];

        // `valid_until` is central's advisory TTL (pushed_at + 4h). It marks the
        // snapshot unverified, but on its own it must NEVER restrict a paid
        // customer — that was the lockout. Restriction happens only when GRACE is
        // exhausted.
        $stale = $validUntil ? $validUntil->lte(EntitlementClock::now()) : false;
        if ($stale && $state === self::STATE_VERIFIED) {
            $state = self::STATE_GRACE;
        }

        if ($state === self::STATE_GRACE && config('entitlements.alert_when_stale', true)) {
            Log::warning('Entitlement snapshot is unverified; serving last-known-good', [
                'state' => $state,
                'pushed_at_utc' => EntitlementClock::iso($pushedAt),
                'age_minutes' => $verification['age_minutes'],
                'stale_after_minutes' => $this->staleAfterMinutes(),
                'grace_minutes' => $this->graceMinutes(),
                'alert' => 'entitlement_delivery_unhealthy',
            ]);
        }

        $payload['_snapshot'] = [
            'version' => isset($metadata['version']) ? (string) $metadata['version'] : null,
            'revision' => $payload[self::REVISION_KEY] ?? null,
            'synced_at' => EntitlementClock::iso(EntitlementClock::parse($metadata['synced_at'] ?? null)),
            'evaluated_at' => EntitlementClock::iso(EntitlementClock::parse($metadata['evaluated_at'] ?? null)),
            'pushed_at' => EntitlementClock::iso($pushedAt),
            'valid_until' => EntitlementClock::iso($validUntil),
            'schema_version' => $metadata['schema_version'] ?? null,
            'source_version' => $metadata['source_version'] ?? null,
            'state_hash' => $metadata['state_hash'] ?? null,
            'age_minutes' => $verification['age_minutes'],
            'verification_state' => $state,
            'stale' => $stale,
            // TRUE only when grace is exhausted. This is the ONLY freshness condition
            // under which a snapshot may restrict a feature the customer owns.
            'beyond_max_stale' => $state === self::STATE_GRACE_EXPIRED,
            'freshness' => match ($state) {
                self::STATE_GRACE_EXPIRED => 'entitlement_verification_stale',
                self::STATE_GRACE => 'snapshot_stale',
                default => 'fresh',
            },
            'reason_code' => match ($state) {
                self::STATE_GRACE_EXPIRED => 'entitlement_verification_stale',
                self::STATE_GRACE => 'snapshot_stale',
                default => null,
            },
        ];

        return $payload;
    }
}
