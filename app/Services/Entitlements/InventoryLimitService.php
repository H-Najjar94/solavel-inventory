<?php

namespace App\Services\Entitlements;

/**
 * The plan's NUMERIC limits (stock.max_items, stock.max_warehouses), read from
 * the same verified snapshot the boolean gate uses, and the grandfathered
 * "can I create one more?" decision built on top of them.
 *
 * THE GRANDFATHER CONTRACT (identical to SolaBooks PlanLimitService::canCreate*):
 *   - A null / missing / "unlimited" limit means no ceiling.
 *   - Otherwise a new record is allowed only while count(active) < limit.
 *   - A customer already AT or OVER the limit keeps and fully uses every existing
 *     record. The limit blocks the NEXT create, never the records already there.
 *     Nothing is ever disabled or deleted on a downgrade — growth stops, data stays.
 *
 * This is why enforcement is safe to switch on for the live over-limit tenants
 * (clients 2 & 18 already hold more warehouses than Free allows): they lose no
 * data and no access to what they have; they simply cannot add more until they
 * upgrade.
 *
 * DARK-LAUNCH: callers must themselves check config('entitlements.feature_enforcement')
 * — this service always reports the true limit/usage so the readiness audit can
 * see over-limit tenants even while enforcement is dark. Only the CREATE path
 * couples the decision to the flag.
 */
class InventoryLimitService
{
    /** Sentinel: the snapshot expresses "no ceiling" in several shapes. */
    private const UNLIMITED_TOKENS = ['unlimited', 'infinite', '∞', ''];

    public function __construct(private EntitlementsCache $cache)
    {
    }

    /**
     * The plan's numeric ceiling for a limit feature, or null when unlimited /
     * unknown. Reads features/flags exactly like EntitlementAccessDecision does,
     * so a limit stored as `5`, `"5"`, or `{value: 5}` all resolve to 5.
     */
    public function limit(string $featureKey): ?int
    {
        $clientId = $this->cache->currentClientId();
        $snapshot = $clientId ? $this->cache->getProjectSnapshot($clientId) : null;

        if ($snapshot === null) {
            // No snapshot: we cannot prove a ceiling. Fail OPEN on limits — a
            // missing snapshot must never fabricate a restriction a customer did
            // not agree to. (The boolean gate fails closed on paid features; a
            // numeric ceiling we cannot read is treated as absent, not as zero.)
            return null;
        }

        foreach (['features', 'flags', 'limits'] as $key) {
            $bag = (array) ($snapshot[$key] ?? []);
            if (! array_key_exists($featureKey, $bag)) {
                continue;
            }

            $raw = is_array($bag[$featureKey])
                ? ($bag[$featureKey]['value'] ?? null)
                : $bag[$featureKey];

            return $this->normalize($raw);
        }

        return null;
    }

    /**
     * May the org create one more of this limited resource, given how many it
     * already has? Grandfather rule: unlimited => always; else count < limit.
     *
     * @param  int  $currentCount  count of the org's existing ACTIVE records
     */
    public function canCreate(string $featureKey, int $currentCount): bool
    {
        $limit = $this->limit($featureKey);

        if ($limit === null) {
            return true; // unlimited / unknown ceiling
        }

        return $currentCount < $limit;
    }

    /**
     * Remaining headroom, or null when unlimited. Clamped at 0 so an already
     * over-limit (grandfathered) tenant reports 0 remaining, never a negative.
     */
    public function remaining(string $featureKey, int $currentCount): ?int
    {
        $limit = $this->limit($featureKey);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $currentCount);
    }

    /**
     * Usage descriptor for the UI / audit: {limit, used, remaining, unlimited,
     * over_limit}. `over_limit` is the grandfathered state — used > limit — that
     * must be shown ("5 / 1 — upgrade to add more") but never used to delete data.
     *
     * @return array{feature:string,limit:?int,used:int,remaining:?int,unlimited:bool,over_limit:bool}
     */
    public function usage(string $featureKey, int $currentCount): array
    {
        $limit = $this->limit($featureKey);
        $unlimited = $limit === null;

        return [
            'feature' => $featureKey,
            'limit' => $limit,
            'used' => $currentCount,
            'remaining' => $unlimited ? null : max(0, $limit - $currentCount),
            'unlimited' => $unlimited,
            'over_limit' => ! $unlimited && $currentCount > $limit,
        ];
    }

    private function normalize(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_string($raw) && in_array(strtolower(trim($raw)), self::UNLIMITED_TOKENS, true)) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        // A non-numeric, non-sentinel value is uninterpretable as a ceiling.
        return null;
    }
}
