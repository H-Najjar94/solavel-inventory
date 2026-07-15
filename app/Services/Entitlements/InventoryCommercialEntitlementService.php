<?php

namespace App\Services\Entitlements;

use Illuminate\Support\Facades\Log;

/**
 * SolaStock's commercial gate.
 *
 * It answers one question — "may this tenant use this permission?" — and it
 * delegates the actual decision to EntitlementAccessDecision, which enforces the
 * paid-through rule:
 *
 *   A customer keeps every feature they are entitled to until `access_until`,
 *   however old our local copy of that entitlement has become.
 *
 * WHAT CHANGED, AND WHY
 * ---------------------
 * This class used to deny paid access when `_snapshot.beyond_max_stale` was true
 * — i.e. when OUR push pipeline had been broken for longer than 24h + 72h. That
 * was wrong twice over:
 *
 *   1. Snapshot age is infrastructure health, not subscription state. A snapshot
 *      pushed five weeks ago still knows exactly when the customer has paid
 *      through. Our pipeline breaking is our problem, not theirs.
 *   2. It made a delivery outage indistinguishable, to the customer, from a
 *      billing failure — dropping a paying Premium tenant into "restricted safe
 *      mode" with `entitlement_verification_stale`.
 *
 * There is now NO code path in this class in which the age of a snapshot can deny
 * access. Age is read exactly once, in alertIfUnhealthy(), and all it does is log.
 *
 * WHAT DID NOT CHANGE
 * -------------------
 * Every enforcement signal the old gate honoured is still honoured, just moved
 * into the decision engine: `accessible:false`, `commercially_entitled:false`,
 * revoked/suspended/cancelled/expired reason codes (flat or nested under
 * `status`), blocked `access_mode`s, a past `expires_at`, and an explicitly
 * blocked feature. A missing snapshot still fails CLOSED on every paid feature.
 * Free permissions and the free-tier fallback behave exactly as before.
 */
class InventoryCommercialEntitlementService
{
    private EntitlementAccessDecision $decisions;

    public function __construct(
        private EntitlementsCache $cache,
        ?EntitlementAccessDecision $decisions = null,
    ) {
        $this->decisions = $decisions ?? new EntitlementAccessDecision;
    }

    /**
     * Check a stock.* FEATURE key directly (not via a permission). Used by the
     * route-level feature gate for paid surfaces that share a base/free
     * permission and therefore cannot be gated through the permission map.
     *
     * Same decision engine, same paid-through + fail-closed semantics as
     * checkPermission — only the entry point differs (feature vs permission).
     *
     * @return array{allowed:bool,status:int,reason_code:string,access_mode:string,tier:?string,feature:?string,snapshot:array<string,mixed>|null}
     */
    public function checkFeature(string $featureKey): array
    {
        $clientId = $this->cache->currentClientId();
        $snapshot = $clientId ? $this->cache->getProjectSnapshot($clientId) : null;
        $meta = $snapshot !== null ? (array) ($snapshot['_snapshot'] ?? []) : null;

        $this->alertIfUnhealthy($meta);

        $decision = $this->decisions->decide($snapshot, $featureKey);

        // No snapshot at all: fail closed on the paid feature (a feature-gated
        // route is never a free permission).
        if ($decision['reason'] === EntitlementAccessDecision::DENY_NO_ENTITLEMENT) {
            return $this->decision(false, 'entitlement_service_unavailable', 'limited', null, $featureKey, null);
        }

        $accessMode = (string) ($snapshot['access_mode'] ?? 'full');

        if ($decision['allowed']) {
            return $this->decision(true, $this->grantReason($snapshot, $meta), $accessMode, $snapshot, $featureKey, $meta);
        }

        if ($this->decisions->isSubscriptionDenial($decision['reason'])) {
            return $this->decision(false, $decision['reason'], 'blocked', $snapshot, $featureKey, $meta);
        }

        return $this->decision(false, 'feature_not_in_plan', $accessMode, $snapshot, $featureKey, $meta);
    }

    /**
     * @return array{allowed:bool,status:int,reason_code:string,access_mode:string,tier:?string,feature:?string,snapshot:array<string,mixed>|null}
     */
    public function checkPermission(string $permission): array
    {
        $feature = $this->featureForPermission($permission);

        // "Free" means: not gated on the plan at all. Either no feature is mapped
        // to the permission, or central grants it to everyone.
        $isFree = $feature === null
            || in_array($permission, (array) config('inventory_entitlements.free_permissions', []), true);

        $clientId = $this->cache->currentClientId();
        $snapshot = $clientId ? $this->cache->getProjectSnapshot($clientId) : null;
        $meta = $snapshot !== null ? (array) ($snapshot['_snapshot'] ?? []) : null;

        // Snapshot age lives HERE and only here. It alerts; it never denies.
        $this->alertIfUnhealthy($meta);

        // The entitlement stored at rest was signature-verified at ingest (see
        // SyncEventsController), and an unverifiable one is never allowed to
        // replace a valid one — so by construction what we read here has verified.
        $decision = $this->decisions->decide($snapshot, (string) $feature);

        // (1) No snapshot EVER existed. We know nothing, so we fail closed on every
        //     paid feature. Free permissions keep working — a tenant with no
        //     snapshot is not a tenant we can prove is de-entitled.
        if ($decision['reason'] === EntitlementAccessDecision::DENY_NO_ENTITLEMENT) {
            return $this->decision($isFree, 'entitlement_service_unavailable', 'limited', null, $feature, null);
        }

        $accessMode = (string) ($snapshot['access_mode'] ?? 'full');

        // (2) Allowed. The feature is in the plan and the subscription is live.
        if ($decision['allowed']) {
            return $this->decision(true, $this->grantReason($snapshot, $meta), $accessMode, $snapshot, $feature, $meta);
        }

        // (3) A SUBSCRIPTION-level denial: revoked, suspended, cancelled, expired,
        //     or not access-eligible. A free permission survives this only when
        //     central left a free-tier fallback in place — unchanged from before.
        if ($this->decisions->isSubscriptionDenial($decision['reason'])) {
            return $this->decision(
                $isFree && $this->hasFreeFallback($snapshot),
                $decision['reason'],
                'blocked',
                $snapshot,
                $feature,
                $meta
            );
        }

        // (4) A FEATURE-level denial: the plan does not include it. Free and
        //     ungated permissions were never gated on the plan, so they are
        //     unaffected — this is the old "ungated permission" branch.
        if ($isFree) {
            return $this->decision(true, $this->grantReason($snapshot, $meta), $accessMode, $snapshot, null, $meta);
        }

        // A real plan limit — and it must never collapse into an infrastructure
        // reason code, because that is what made an outage look like a billing
        // problem to the customer.
        return $this->decision(false, 'feature_not_in_plan', $accessMode, $snapshot, $feature, $meta);
    }

    /**
     * Distinguish "your plan does not include this" from "we could not verify your
     * entitlement". Collapsing the two is what made an infra outage look to a
     * paying customer like a billing problem.
     *
     * NOTE `entitlement_verification_stale` is GONE from this list — not because it
     * stopped being an infrastructure reason, but because it can no longer be a
     * DENIAL reason at all. Staleness does not deny.
     */
    public function isInfrastructureDenial(string $reasonCode): bool
    {
        return in_array($reasonCode, [
            'entitlement_service_unavailable',
            EntitlementAccessDecision::DENY_UNVERIFIED,
        ], true);
    }

    /**
     * The snapshot is old. Say so — loudly, to us — and then get out of the way.
     *
     * This is the ONLY place the gate looks at age, and its only effect is a log
     * line. A broken delivery pipeline is an incident for us to fix, not a reason
     * to downgrade a customer who has paid.
     *
     * @param  array<string,mixed>|null  $meta
     */
    private function alertIfUnhealthy(?array $meta): void
    {
        if ($meta === null || ! config('entitlements.alert_when_stale', true)) {
            return;
        }

        $state = (string) ($meta['verification_state'] ?? '');

        if (! in_array($state, [EntitlementsCache::STATE_GRACE, EntitlementsCache::STATE_GRACE_EXPIRED], true)) {
            return;
        }

        Log::warning('SolaStock entitlement snapshot is stale; serving it anyway (age does not gate access)', [
            'verification_state' => $state,
            'age_minutes' => $meta['age_minutes'] ?? null,
            'pushed_at_utc' => $meta['pushed_at'] ?? null,
            'alert' => 'entitlement_delivery_unhealthy',
        ]);
    }

    /**
     * The reason code attached to a GRANT. Staleness is still surfaced here — the
     * caller may want to show an "we are having trouble reaching billing" banner —
     * but it is a label on an ALLOWED decision, never a denial.
     *
     * @param  array<string,mixed>|null  $meta
     */
    private function grantReason(?array $snapshot, ?array $meta): string
    {
        if ((bool) ($meta['stale'] ?? false)) {
            return 'snapshot_stale';
        }

        return (string) ($snapshot['reason_code'] ?? 'paid_active');
    }

    private function featureForPermission(string $permission): ?string
    {
        $map = (array) config('inventory_entitlements.permission_features', []);

        return isset($map[$permission]) ? (string) $map[$permission] : null;
    }

    private function hasFreeFallback(?array $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        return in_array((string) ($snapshot['reason_code'] ?? ''), ['free_native', 'free_tier', 'paid_expired_free_fallback'], true)
            || in_array((string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? ''), ['free'], true);
    }

    private function decision(bool $allowed, string $reason, string $mode, ?array $snapshot, ?string $feature, ?array $meta = null): array
    {
        return [
            'allowed' => $allowed,
            'status' => $allowed ? 200 : 402,
            'reason_code' => $reason,
            'access_mode' => $mode,
            'tier' => $snapshot['effective_tier'] ?? $snapshot['tier'] ?? null,
            'feature' => $feature,
            'snapshot' => $meta ?? ($snapshot['_snapshot'] ?? null),
        ];
    }
}
