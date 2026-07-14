<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Snapshot freshness — INFRASTRUCTURE HEALTH ONLY
    |--------------------------------------------------------------------------
    |
    | These thresholds drive ALERTS, DIAGNOSTICS, retries, self-healing pulls and
    | admin visibility. They DO NOT expire paid access, and NOTHING below may be
    | used as an access boundary. Every value here answers "is OUR delivery
    | pipeline healthy?" — never "has the customer paid?".
    |
    | Paid access is bounded by ONE thing: `access_until`, the authoritative
    | paid-through date central derives from the real subscription/grant model and
    | signs into the entitlement. A snapshot that is three weeks old still knows
    | exactly when the customer has paid through — its age says something about OUR
    | pipeline, not about their subscription.
    |
    | The old model denied at 24h, then at 24h + 72h of "grace". Both turned our
    | own delivery failure into a customer-visible downgrade, and locked a paying
    | Premium customer out of a feature they owned. See EntitlementAccessDecision:
    | age is not consulted there, at all, by design.
    |
    */

    // How long after `pushed_at` a snapshot is still reported as VERIFIED.
    // The heartbeat runs every 30 minutes, so 24h already tolerates ~48
    // consecutive missed pushes before we even report the tenant as unhealthy.
    //
    // SolaStock's legacy key was ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES (read via
    // `inventory_entitlements.max_stale_minutes`). It is still honoured as the
    // default here so an operator who sets it keeps a sane ALERTING boundary — but
    // its meaning is now purely diagnostic. It denies nobody.
    'stale_after_minutes' => (int) env(
        'ENTITLEMENTS_STALE_AFTER_MINUTES',
        (int) env('ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES', 1440)
    ),

    // RETAINED FOR DIAGNOSTICS ONLY — no longer an access boundary.
    //
    // It now marks the point at which the delivery pipeline is considered badly
    // broken (worth paging someone), NOT the point at which a customer loses
    // access. ENTITLEMENTS_GRACE_MINUTES must never gate a paid feature again.
    'grace_minutes' => (int) env('ENTITLEMENTS_GRACE_MINUTES', 4320),

    // Emit a warning-level alert once a snapshot crosses `stale_after_minutes`.
    // Alerting is the ONLY thing staleness is allowed to do.
    'alert_when_stale' => (bool) env('ENTITLEMENTS_ALERT_WHEN_STALE', true),

    // Legacy key. The old implementation blanket-denied every non-core feature
    // once `pushed_at` was older than this. Retained ONLY so an operator who still
    // sets ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES gets a sane `stale_after`
    // ALERTING default — see EntitlementsCache::staleAfterMinutes().
    'max_stale_minutes' => (int) env(
        'ENTITLEMENTS_MAX_STALE_MINUTES',
        (int) env('ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES', 1440)
    ),

    /*
    |--------------------------------------------------------------------------
    | Entitlement signing
    |--------------------------------------------------------------------------
    |
    | Central signs the entitlement body; we verify it before honouring it, and we
    | REFUSE to let an entitlement that does not verify replace the last one that
    | did.
    |
    | This matters far more than it used to. The transport HMAC only proves
    | "central sent this over the wire"; it says nothing once the payload is at
    | rest in `tenant_entitlements_snapshots`. And that row now grants paid access
    | for WEEKS without a refresh, which makes it worth forging.
    |
    | `key_id` travels with the signature so a new key can be distributed here
    | before central starts signing with it.
    |
    */
    'signing' => [
        'key_id' => env('ENTITLEMENT_SIGNING_KEY_ID', 'v1'),

        'keys' => array_filter([
            'v1' => env('ENTITLEMENT_SIGNING_KEY_V1', env('ENTITLEMENT_SIGNING_KEY', '')),
            'v2' => env('ENTITLEMENT_SIGNING_KEY_V2', ''),
        ]),

        // During rollout, an entitlement that carries NO signature at all is a
        // pre-signing snapshot, not a forgery: accept it and flag it. A payload
        // that DOES carry a signature is always verified, regardless of this flag.
        // Flip this on once every app is signing.
        'require_signature' => (bool) env('ENTITLEMENT_REQUIRE_SIGNATURE', false),
    ],

];
