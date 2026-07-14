<?php

namespace App\Services\Entitlements;

class InventoryCommercialEntitlementService
{
    /**
     * Reason codes that mean central EXPLICITLY told us the customer no longer has
     * the subscription. These are authoritative on their own: they are a decision,
     * not a delivery symptom, so they are enforced immediately and are never
     * rescued by the grace window.
     */
    private const REVOKED_REASON_CODES = [
        'subscription_cancelled',
        'subscription_canceled',
        'subscription_suspended',
        'subscription_expired',
        'subscription_revoked',
        'subscription_inactive',
        'paid_expired_no_fallback',
        'cancelled',
        'canceled',
        'suspended',
        'revoked',
        'expired',
    ];

    /** Access modes that mean the same thing. */
    private const REVOKED_ACCESS_MODES = [
        'blocked',
        'suspended',
        'cancelled',
        'canceled',
        'revoked',
    ];

    public function __construct(private EntitlementsCache $cache)
    {
    }

    /**
     * @return array{allowed:bool,status:int,reason_code:string,access_mode:string,tier:?string,feature:?string,snapshot:array<string,mixed>|null}
     */
    public function checkPermission(string $permission): array
    {
        $feature = $this->featureForPermission($permission);
        $freePermissions = (array) config('inventory_entitlements.free_permissions', []);
        $safePermissions = (array) config('inventory_entitlements.restricted_safe_permissions', []);

        $clientId = $this->cache->currentClientId();
        $snapshot = $clientId ? $this->cache->getProjectSnapshot($clientId) : null;

        // (1) No snapshot EVER existed. We know nothing, so we fail closed on every
        //     paid feature. Free permissions keep working — a tenant with no
        //     snapshot is not a tenant we can prove is de-entitled.
        if (! $snapshot) {
            return $this->decision(
                in_array($permission, $freePermissions, true),
                'entitlement_service_unavailable',
                'limited',
                null,
                $feature,
                null
            );
        }

        $meta = (array) ($snapshot['_snapshot'] ?? []);

        // (2) EXPLICIT de-entitlement. Checked BEFORE freshness: a cancelled,
        //     suspended, expired or revoked subscription is central's decision, and
        //     a stale snapshot carrying that decision is still carrying a decision.
        //     Grace must never resurrect it.
        $revocation = $this->explicitRevocationReason($snapshot);
        if ($revocation !== null) {
            return $this->decision(
                in_array($permission, $freePermissions, true) && $this->hasFreeFallback($snapshot),
                $revocation,
                'blocked',
                $snapshot,
                $feature,
                $meta
            );
        }

        // (3) Grace EXHAUSTED. Only now does a freshness failure restrict anyone.
        //     Inside the grace window `beyond_max_stale` is false and we fall
        //     through to serve last-known-good — that is the July-incident fix.
        if ((bool) ($meta['beyond_max_stale'] ?? false)) {
            return $this->decision(
                in_array($permission, $safePermissions, true),
                'entitlement_verification_stale',
                'restricted_safe_mode',
                $snapshot,
                $feature,
                $meta
            );
        }

        $reason = (string) ($snapshot['reason_code'] ?? ($meta['stale'] ?? false ? 'snapshot_stale' : 'paid_active'));
        $accessMode = (string) ($snapshot['access_mode'] ?? 'full');
        $tier = (string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? '');

        if ((bool) ($meta['stale'] ?? false)) {
            $reason = 'snapshot_stale';
        }

        // (4) Ungated permission, or one central grants for free.
        if ($feature === null || in_array($permission, $freePermissions, true)) {
            return $this->decision(true, $reason, $accessMode, $snapshot, null, $meta);
        }

        // (5) Last-known-good POSITIVE. Serving this while unverified is the whole
        //     point of grace: a delivery outage must not revoke an owned feature.
        if ($this->featureAllowed($snapshot, $feature)) {
            return $this->decision(true, $reason, $accessMode, $snapshot, $feature, $meta);
        }

        // (6) The feature is explicitly NOT in the plan. A real plan limit — and it
        //     must never collapse into an infrastructure reason code.
        return $this->decision(false, 'feature_not_in_plan', $accessMode, $snapshot, $feature, $meta);
    }

    /**
     * Distinguish "your plan does not include this" from "we could not verify your
     * entitlement". Collapsing the two is what made an infra outage look to a
     * paying customer like a billing problem.
     */
    public function isInfrastructureDenial(string $reasonCode): bool
    {
        return in_array($reasonCode, [
            'entitlement_verification_stale',
            'entitlement_service_unavailable',
        ], true);
    }

    /**
     * The explicit de-entitlement reason, or null when the subscription is live.
     *
     * Reads both the flat SolaStock payload shape and central's nested `status`
     * block, because a snapshot that carries the revocation only in `status` is
     * still carrying a revocation.
     */
    private function explicitRevocationReason(array $snapshot): ?string
    {
        $status = (array) ($snapshot['status'] ?? []);

        $reasonCodes = array_values(array_filter([
            strtolower((string) ($snapshot['reason_code'] ?? '')),
            strtolower((string) ($status['reason_code'] ?? '')),
        ]));

        // A reason code that NAMES the revocation is the most specific answer, so
        // it wins over the generic fallbacks below. Central may deliver it flat or
        // nested under `status`.
        foreach ($reasonCodes as $code) {
            if (in_array($code, self::REVOKED_REASON_CODES, true)) {
                return $code;
            }
        }

        // Explicit boolean de-entitlement from central.
        foreach (['commercially_entitled', 'accessible'] as $key) {
            foreach ([$snapshot, $status] as $source) {
                if (array_key_exists($key, $source) && $source[$key] === false) {
                    return 'subscription_revoked';
                }
            }
        }

        // An expiry date already in the past is authoritative WITHOUT a fresh push:
        // expiry does not need to be re-delivered to become true.
        foreach ([$snapshot['expires_at'] ?? null, $status['expires_at'] ?? null] as $expiresAt) {
            $parsed = EntitlementClock::parse($expiresAt);
            if ($parsed !== null && $parsed->lte(EntitlementClock::now())) {
                return 'subscription_expired';
            }
        }

        $accessMode = strtolower((string) ($snapshot['access_mode'] ?? 'full'));
        if (in_array($accessMode, self::REVOKED_ACCESS_MODES, true)) {
            return $reasonCodes[0] ?? 'paid_expired_no_fallback';
        }

        return null;
    }

    private function featureForPermission(string $permission): ?string
    {
        $map = (array) config('inventory_entitlements.permission_features', []);

        return isset($map[$permission]) ? (string) $map[$permission] : null;
    }

    private function featureAllowed(array $snapshot, string $feature): bool
    {
        $blocked = (array) ($snapshot['blocked_features'] ?? []);
        if (in_array($feature, $blocked, true)) {
            return false;
        }

        $allowed = (array) ($snapshot['allowed_features'] ?? []);
        if (in_array($feature, $allowed, true)) {
            return true;
        }

        foreach (['features', 'flags'] as $key) {
            $features = (array) ($snapshot[$key] ?? []);
            if (array_key_exists($feature, $features)) {
                $value = is_array($features[$feature]) ? ($features[$feature]['value'] ?? false) : $features[$feature];

                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return (string) ($snapshot['access_mode'] ?? 'full') === 'full'
            && ! in_array((string) ($snapshot['effective_tier'] ?? $snapshot['tier'] ?? 'free'), ['free', ''], true);
    }

    private function hasFreeFallback(array $snapshot): bool
    {
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
