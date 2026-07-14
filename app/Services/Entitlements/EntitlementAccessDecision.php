<?php

namespace App\Services\Entitlements;

/**
 * THE ACCESS DECISION.
 *
 * One rule, and it is a commercial rule, not an infrastructure one:
 *
 *   A customer keeps every feature they are entitled to until the authoritative
 *   paid-through date (`access_until`), no matter how old our local copy of that
 *   entitlement has become.
 *
 * Snapshot age is infrastructure health metadata. It is reported, alerted on, and
 * used to trigger retries and self-healing pulls — and it NEVER expires paid
 * access. The previous model denied at 24h (then 96h with grace), which meant our
 * own delivery pipeline breaking looked exactly like the customer's subscription
 * lapsing. A snapshot that is three weeks old still knows precisely when the
 * customer has paid through; staleness does not erase that knowledge.
 *
 * `access_until = null` means UNBOUNDED (free tier, perpetual grant, or a
 * subscription with no end date). Absence of an expiry is NOT an expiry.
 *
 * ---------------------------------------------------------------------------
 * SolaStock adaptation
 * ---------------------------------------------------------------------------
 * The decision logic below is the SolaProjects reference engine. What differs is
 * only the SHAPE of the payload it reads, because SolaStock's snapshot slice
 * predates the shared schema and expresses the same facts differently:
 *
 *   paid-through   `access_until` (new) | `expires_at` | `status.expires_at`
 *   de-entitled    `reason_code` | `status.reason_code` | `access_mode`
 *   feature in plan `allowed_features`/`blocked_features` lists, `features`/`flags`
 *                   maps, or a tier fallback
 *
 * Every one of those signals was an enforcement signal in the old gate, so every
 * one of them is still read here. An enforcement signal we stop reading is an
 * enforcement signal we have lost.
 */
class EntitlementAccessDecision
{
    /* ---- outcomes -------------------------------------------------------- */

    public const ALLOW = 'allow';

    /** No verified entitlement has ever existed. We know nothing → fail closed. */
    public const DENY_NO_ENTITLEMENT = 'no_entitlement';

    /** The signature/tenant identity does not verify and we hold no prior valid state. */
    public const DENY_UNVERIFIED = 'entitlement_unverified';

    /** The paid-through date has passed. This is a real, commercial expiry. */
    public const DENY_EXPIRED = 'subscription_expired';

    /** Central explicitly suspended or revoked this subscription. */
    public const DENY_REVOKED = 'subscription_revoked';

    /** The subscription status is not access-eligible. */
    public const DENY_NOT_ELIGIBLE = 'subscription_not_access_eligible';

    /** The plan simply does not include this feature. */
    public const DENY_NOT_IN_PLAN = 'feature_not_in_plan';

    /**
     * Statuses under which the customer may still use what they bought.
     * Mirrors central's EntitlementAccessWindow::ACCESS_ELIGIBLE_STATUSES.
     *
     * `past_due` is deliberately eligible — it is the dunning window. Central cuts
     * a customer off by moving them OUT of this list and pushing a newer revision;
     * a tenant must not infer it.
     */
    public const ACCESS_ELIGIBLE_STATUSES = ['trial', 'trialing', 'active', 'past_due', 'granted'];

    /**
     * Central reason codes that mean access was explicitly withdrawn. Retained
     * alongside `revoked_at`/`suspended_at` because central still emits them, and
     * an enforcement signal we stop reading is an enforcement signal we have lost.
     *
     * The last four are SolaStock's own historical codes, carried over verbatim
     * from InventoryCommercialEntitlementService so nothing that used to deny
     * stops denying.
     */
    public const REVOKED_REASON_CODES = [
        'subscription_cancelled', 'subscription_canceled', 'subscription_suspended',
        'subscription_expired', 'subscription_revoked', 'plan_revoked',
        'suspended', 'revoked', 'cancelled', 'canceled',
        // SolaStock legacy codes.
        'subscription_inactive', 'paid_expired_no_fallback', 'expired',
    ];

    /**
     * SolaStock's `access_mode` is its own way of saying "no longer entitled".
     *
     * HARD withdrawals only. These outrank the paid-through date exactly as
     * `revoked_at`/`suspended_at` do — being blocked is a decision, not a clock.
     *
     * NOTE what is deliberately NOT here: `cancelled`/`canceled`. A cancellation
     * is not a withdrawal of the time already paid for — it is a decision to stop
     * renewing. It is handled in the ELIGIBILITY step below, AFTER `access_until`,
     * so a customer who cancels mid-period keeps the period they bought. That is
     * the whole point of `cancel_at_period_end`.
     */
    public const REVOKED_ACCESS_MODES = ['blocked', 'suspended', 'revoked'];

    /** Access modes that mean "cancelled" — soft, and bounded by `access_until`. */
    public const CANCELLED_ACCESS_MODES = ['cancelled', 'canceled'];

    /**
     * Decide access for one feature, given the app slice of the last VERIFIED
     * entitlement.
     *
     * @param  array<string,mixed>|null  $entitlement  null = none ever received
     * @param  string  $featureKey  '' = the permission is not feature-gated
     * @return array{allowed:bool,reason:string,access_until:?string}
     */
    public function decide(?array $entitlement, string $featureKey, bool $signatureValid = true): array
    {
        $now = EntitlementClock::now();

        // 1. Nothing has ever been verified. Fail closed — this is the ONE case
        //    where absence of information denies.
        if ($entitlement === null || $entitlement === []) {
            return $this->result(false, self::DENY_NO_ENTITLEMENT, null);
        }

        // 2. The entitlement is present but does not verify, and there is no prior
        //    valid state to fall back on. An unverifiable entitlement grants nothing.
        if (! $signatureValid) {
            return $this->result(false, self::DENY_UNVERIFIED, null);
        }

        $statusBlock = (array) ($entitlement['status'] ?? []);
        $accessUntilRaw = $entitlement['access_until'] ?? null;

        // The paid-through date. Central publishes it as `access_until`; older
        // revisions expressed the same fact as `expires_at` / `status.expires_at` /
        // `status.expired_at`. Honour ALL of them — a customer whose expiry is only
        // expressed the old way is still expired, and dropping that signal would
        // hand them free access.
        $accessUntil = EntitlementClock::parse($accessUntilRaw)
            ?? EntitlementClock::parse($entitlement['expires_at'] ?? null)
            ?? EntitlementClock::parse($statusBlock['expires_at'] ?? $statusBlock['expired_at'] ?? null);

        // 3. Explicit revocation / suspension. Central told us, in a revision we
        //    actually received. This outranks everything below it: a customer who
        //    was revoked does not get to keep access just because they had paid
        //    through a later date.
        $revokedAt = EntitlementClock::parse($entitlement['revoked_at'] ?? null);
        $suspendedAt = EntitlementClock::parse($entitlement['suspended_at'] ?? null);

        foreach ([$revokedAt, $suspendedAt] as $at) {
            if ($at !== null && $at->lte($now)) {
                return $this->result(false, self::DENY_REVOKED, $accessUntilRaw);
            }
        }

        // Central's OTHER ways of saying "no longer access-eligible". These predate
        // access_until and are still emitted, so they remain authoritative.
        //
        // The SPECIFIC reason is checked first: "subscription_cancelled" tells the
        // customer (and support) something true and actionable, where the generic
        // "not eligible" does not. SolaStock may deliver it flat OR nested under
        // `status`, so both are read.
        foreach ([$entitlement['reason_code'] ?? null, $statusBlock['reason_code'] ?? null] as $code) {
            $code = strtolower(trim((string) $code));

            if ($code !== '' && in_array($code, self::REVOKED_REASON_CODES, true)) {
                return $this->result(false, $code, $accessUntilRaw);
            }
        }

        // `accessible: false` / `commercially_entitled: false` is central telling us
        // plainly that this customer may not use this app. SolaStock puts these flat
        // or under `status`; the old gate read both, so we read both.
        foreach (['accessible', 'commercially_entitled'] as $key) {
            foreach ([$entitlement, $statusBlock] as $source) {
                if (array_key_exists($key, $source) && ! $this->toBool($source[$key])) {
                    return $this->result(false, self::DENY_NOT_ELIGIBLE, $accessUntilRaw);
                }
            }
        }

        // SolaStock's hard `access_mode` withdrawals — blocked/suspended/revoked.
        $accessMode = strtolower(trim((string) ($entitlement['access_mode'] ?? 'full')));

        if (in_array($accessMode, self::REVOKED_ACCESS_MODES, true)) {
            return $this->result(false, self::DENY_REVOKED, $accessUntilRaw);
        }

        // 4. The paid-through date. THE commercial boundary — and the only clock
        //    that may expire a paid customer.
        //
        //    NOTE the ordering: this is checked BEFORE eligibility, because
        //    `cancel_at_period_end` leaves the status as `canceled` while the
        //    customer is still legitimately paid through the end of the period.
        //    They cancelled; they did not ask for a refund.
        if ($accessUntil !== null && $accessUntil->lte($now)) {
            return $this->result(false, self::DENY_EXPIRED, $accessUntilRaw);
        }

        // 5. Status must be access-eligible — UNLESS they are inside a
        //    cancel-at-period-end window, which step 4 has just proved.
        $subscriptionStatus = strtolower(trim((string) ($entitlement['subscription_status'] ?? '')));
        $cancelAtPeriodEnd = $this->toBool($entitlement['cancel_at_period_end'] ?? false);

        $statusEligible = $subscriptionStatus === ''   // no status published (legacy/free) → do not block
            || in_array($subscriptionStatus, self::ACCESS_ELIGIBLE_STATUSES, true)
            || $cancelAtPeriodEnd;                     // paid through, cancelled at period end

        // A `cancelled` access_mode is the same statement in SolaStock's vocabulary:
        // it may deny, but only once the paid-through date has actually passed —
        // and step 4 proved it has not.
        $modeEligible = ! in_array($accessMode, self::CANCELLED_ACCESS_MODES, true)
            || $cancelAtPeriodEnd
            || $accessUntil !== null;                  // still inside a paid period

        if (! $statusEligible || ! $modeEligible) {
            return $this->result(false, self::DENY_NOT_ELIGIBLE, $accessUntilRaw);
        }

        // 6. Finally: is the feature actually in the plan? A downgrade removes ONLY
        //    the features the newer revision removed — everything still flagged on
        //    stays on.
        //
        //    An empty feature key means the permission is not feature-gated at all;
        //    the caller decides what that means (SolaStock allows it).
        if ($featureKey === '' || ! $this->featureInPlan($entitlement, $featureKey)) {
            return $this->result(false, self::DENY_NOT_IN_PLAN, $accessUntilRaw);
        }

        // Age is deliberately NOT consulted anywhere above.
        return $this->result(true, self::ALLOW, $accessUntilRaw);
    }

    /** Whether a denial reason is a real commercial decision (vs our own infrastructure). */
    public function isCommercialDenial(string $reason): bool
    {
        return in_array($reason, array_merge(self::REVOKED_REASON_CODES, [
            self::DENY_EXPIRED,
            self::DENY_REVOKED,
            self::DENY_NOT_ELIGIBLE,
            self::DENY_NOT_IN_PLAN,
        ]), true);
    }

    /**
     * Whether a denial is about the SUBSCRIPTION rather than about the plan's
     * feature list. The caller needs the distinction: a free permission survives a
     * "this feature is not in your plan" (it was never in a plan) but not a
     * "your subscription is gone".
     */
    public function isSubscriptionDenial(string $reason): bool
    {
        return $reason !== self::DENY_NOT_IN_PLAN
            && $reason !== self::ALLOW
            && $reason !== self::DENY_NO_ENTITLEMENT;
    }

    /**
     * SolaStock's feature-in-plan resolution, carried over verbatim from the old
     * gate so a downgrade keeps meaning exactly what it meant before.
     *
     * Precedence: explicit block > explicit allow > feature/flag map > tier.
     */
    private function featureInPlan(array $entitlement, string $feature): bool
    {
        $blocked = (array) ($entitlement['blocked_features'] ?? []);
        if (in_array($feature, $blocked, true)) {
            return false;
        }

        $allowed = (array) ($entitlement['allowed_features'] ?? []);
        if (in_array($feature, $allowed, true)) {
            return true;
        }

        foreach (['features', 'flags'] as $key) {
            $features = (array) ($entitlement[$key] ?? []);

            if (array_key_exists($feature, $features)) {
                $value = is_array($features[$feature])
                    ? ($features[$feature]['value'] ?? false)
                    : $features[$feature];

                return $this->toBool($value);
            }
        }

        // Nothing said anything about this feature. Fall back to the tier: a paid
        // tier on a full-access snapshot includes it; free does not.
        return (string) ($entitlement['access_mode'] ?? 'full') === 'full'
            && ! in_array((string) ($entitlement['effective_tier'] ?? $entitlement['tier'] ?? 'free'), ['free', ''], true);
    }

    private function result(bool $allowed, string $reason, mixed $accessUntil): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'access_until' => $accessUntil !== null ? (string) $accessUntil : null,
        ];
    }

    private function toBool(mixed $v): bool
    {
        return match (true) {
            is_bool($v) => $v,
            is_int($v) => $v === 1,
            is_string($v) => in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true),
            default => false,
        };
    }
}
