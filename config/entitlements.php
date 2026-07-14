<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entitlement snapshot verification
    |--------------------------------------------------------------------------
    |
    | Child apps gate paid features on a LOCAL `tenant_entitlements_snapshots`
    | row that central re-pushes on a heartbeat. If that delivery path breaks
    | (queue, scheduler, network, auth, tenant DB), the snapshot stops being
    | refreshed even though the customer's plan never changed.
    |
    | The freshness state machine below exists so that an INFRASTRUCTURE failure
    | is never silently converted into a paid-customer lockout. See
    | EntitlementsCache::verificationState().
    |
    |   pushed_at ........................... last authoritative push (UTC)
    |   + stale_after_minutes ............... snapshot is UNVERIFIED past here.
    |                                         Last-known-good is still served,
    |                                         and the infra failure is alerted.
    |   + grace_minutes ..................... GRACE ENDS. Access drops to
    |                                         restricted-safe-mode with
    |                                         `entitlement_verification_stale`.
    |
    | Within the grace window we serve the last VERIFIED snapshot. That is a
    | deliberate trade: a temporary delivery outage must not revoke a feature the
    | customer already owns. It is NOT a hole in enforcement — an explicitly
    | disabled feature, an explicitly revoked/suspended/expired subscription, and
    | a *missing* snapshot are all still denied immediately, regardless of
    | freshness. Only a LAST-KNOWN-GOOD POSITIVE survives, and only until grace
    | expires.
    |
    */

    // How long after `pushed_at` a snapshot is still considered VERIFIED.
    // The heartbeat runs every 30 minutes, so 24h already tolerates ~48
    // consecutive missed pushes before we even enter the grace window.
    //
    // SolaStock's legacy key was ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES (read via
    // `inventory_entitlements.max_stale_minutes`). It is honoured as the default
    // here so an operator who still sets it keeps a sane `stale_after` boundary —
    // but note its MEANING changed: it no longer denies, it only marks the
    // snapshot unverified and opens the grace window.
    'stale_after_minutes' => (int) env(
        'ENTITLEMENTS_STALE_AFTER_MINUTES',
        (int) env('ENTITLEMENT_SNAPSHOT_MAX_STALE_MINUTES', 1440)
    ),

    // Additional window past `stale_after_minutes` during which a last-known-good
    // POSITIVE entitlement is preserved while the delivery failure is alerted.
    // Default 72h: long enough to survive a weekend outage, short enough that a
    // genuinely de-entitled tenant cannot coast indefinitely.
    'grace_minutes' => (int) env('ENTITLEMENTS_GRACE_MINUTES', 4320),

    // Emit a warning-level alert once a snapshot crosses `stale_after_minutes`
    // (i.e. BEFORE it reaches the hard boundary and starts restricting customers).
    'alert_when_stale' => (bool) env('ENTITLEMENTS_ALERT_WHEN_STALE', true),

];
