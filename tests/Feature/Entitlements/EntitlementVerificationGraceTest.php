<?php

namespace Tests\Feature\Entitlements;

use App\Services\Entitlements\EntitlementClock;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The entitlement DELIVERY-HEALTH state machine for SolaStock.
 *
 * Regression cover for the July 2026 incident: a paying Premium customer was
 * denied a feature they owned because ONE central push failed, nothing retried
 * it, and 24h later the gate FAILED CLOSED.
 *
 * The first fix added a 72h "grace" window — which only moved the lockout from
 * hour 24 to hour 96. The real fix is that snapshot age may not deny AT ALL: the
 * customer keeps what they are entitled to until `access_until`, the authoritative
 * paid-through date. See EntitlementAccessDecision and PaidThroughAccessTest.
 *
 * Two tests in this file (marked REWRITTEN) used to assert denial once grace
 * expired. They encoded the old contract and now assert its inverse.
 *
 * The contract pinned down here:
 *   - an INFRASTRUCTURE failure must NEVER revoke a paid feature — at any age, and
 *   - a real de-entitlement must still be enforced immediately (no hole), and
 *   - SolaStock's live FUTURE-DATED rows (d0ea747 wrote Asia/Amman wall clock into
 *     a UTC column) must not freeze the tenant once correct UTC pushes arrive.
 *
 * NOTE: unlike the SolaProjects reference, this class does NOT bind the tenant
 * connection to sqlite. TenancySafetyGuard enforces a strict allow-list of
 * SolaStock test databases (tenant_990010/990003/990004), so a ':memory:' tenant
 * connection fails the base TestCase safety assertion. These tests therefore run
 * against the reserved tenant test DB, writing/deleting only their own snapshot
 * rows (DML only — no DDL).
 */
class EntitlementVerificationGraceTest extends TestCase
{
    /** Row key only — NOT a database. Isolated from the other entitlement tests. */
    private const CLIENT_ID = 990011;

    private const SLUG = 'inventory';

    /** A paid, feature-gated permission: not free, not restricted-safe. */
    private const PAID_PERMISSION = 'inventory.manage_shipments';

    private const PAID_FEATURE = 'stock.sales_fulfillment';

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
            $this->markTestSkipped('tenant_entitlements_snapshots not migrated on the test tenant.');
        }

        $this->originalTimezone = date_default_timezone_get();

        Config::set('cache.default', 'array');
        // 24h to "unverified", then 72h to "badly unhealthy". Both are ALERTING
        // thresholds now — neither restricts anyone.
        Config::set('entitlements.stale_after_minutes', 1440);
        Config::set('entitlements.grace_minutes', 4320);

        Cache::flush();
        $this->deleteRows();
    }

    protected function tearDown(): void
    {
        $this->deleteRows();

        Carbon::setTestNow();
        EntitlementClock::setTestNow(null);
        date_default_timezone_set($this->originalTimezone);
        Cache::flush();

        parent::tearDown();
    }

    /* =====================================================================
     * The state distinctions
     * ================================================================== */

    public function test_paid_feature_is_enabled_with_a_fresh_snapshot(): void
    {
        $this->freeze('2026-07-11 09:00:00');
        $this->storeSnapshot(pushedAt: '2026-07-11 08:30:00');

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('paid_active', $decision['reason_code']);
        $this->assertSame(EntitlementsCache::STATE_VERIFIED, $decision['snapshot']['verification_state']);
    }

    public function test_explicitly_disabled_feature_is_denied_even_when_fresh(): void
    {
        $this->freeze('2026-07-11 09:00:00');
        $this->storeSnapshot(
            pushedAt: '2026-07-11 08:30:00',
            extra: ['allowed_features' => [], 'blocked_features' => [self::PAID_FEATURE]],
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed']);
        $this->assertSame(402, $decision['status']);
        $this->assertSame('feature_not_in_plan', $decision['reason_code']);
        $this->assertFalse($this->gate()->isInfrastructureDenial($decision['reason_code']));
    }

    public function test_explicit_subscription_revocation_is_denied_even_inside_grace(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        // Old enough to be in GRACE, and central had ALREADY told us the
        // subscription was cancelled. Grace must not resurrect that access.
        $this->storeSnapshot(
            pushedAt: '2026-07-09 09:00:00',
            extra: [
                'accessible' => false,
                'commercially_entitled' => false,
                'status' => ['reason_code' => 'subscription_cancelled'],
            ],
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed'], 'grace must never resurrect a cancelled subscription.');
        $this->assertSame('subscription_cancelled', $decision['reason_code']);
    }

    public function test_expired_subscription_is_denied_even_though_snapshot_says_enabled(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        // The subscription's own end date is in the past. Authoritative without a
        // fresh push — expiry does not need to be re-delivered to be true.
        $this->storeSnapshot(
            pushedAt: '2026-07-11 08:30:00',
            extra: ['status' => ['reason_code' => 'paid_active', 'expires_at' => '2026-07-10T00:00:00+00:00']],
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('subscription_expired', $decision['reason_code']);
    }

    public function test_stale_last_known_good_snapshot_survives_inside_grace(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        // Pushed 48h ago: past the 24h verified boundary, inside the 72h grace.
        // THIS is the July incident. The old code dropped to restricted-safe mode.
        $this->storeSnapshot(pushedAt: '2026-07-09 09:00:00');

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertTrue(
            $decision['allowed'],
            'A paid feature must survive a temporary entitlement delivery failure.'
        );
        $this->assertSame('snapshot_stale', $decision['reason_code']);
        $this->assertSame(EntitlementsCache::STATE_GRACE, $decision['snapshot']['verification_state']);
        $this->assertFalse($decision['snapshot']['beyond_max_stale']);
    }

    /**
     * REWRITTEN — this was `test_access_is_restricted_once_the_configured_grace_expires`
     * and it asserted the exact opposite of what it asserts now.
     *
     * It encoded the OLD contract: past 24h + 72h, the gate denied writes with
     * `entitlement_verification_stale` and dropped the tenant into restricted safe
     * mode. But "grace expired" only ever meant OUR push pipeline had been down for
     * four days. It is a statement about our infrastructure, not about whether the
     * customer paid — and turning it into a denial is precisely the July incident
     * this file was written to prevent, merely deferred by 72 hours.
     *
     * The new contract: age NEVER denies. It is reported and alerted on, and the
     * customer keeps everything they are entitled to until `access_until`.
     */
    public function test_expired_grace_no_longer_restricts_anyone(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        // 24h stale boundary + 72h grace = 96h. Pushed 97h ago ⇒ "grace exhausted".
        $this->storeSnapshot(pushedAt: '2026-07-07 08:00:00');

        $gate = $this->gate();
        $write = $gate->checkPermission(self::PAID_PERMISSION);
        $read = $gate->checkPermission('inventory.view_stock');

        // The snapshot is REPORTED as badly unhealthy…
        $this->assertSame(EntitlementsCache::STATE_GRACE_EXPIRED, $write['snapshot']['verification_state']);
        $this->assertTrue($write['snapshot']['beyond_max_stale']);

        // …and it restricts nobody. The customer's plan never changed; our pipeline
        // broke, and that is ours to fix.
        $this->assertTrue($write['allowed'], 'a four-day-old snapshot must not revoke a paid write.');
        $this->assertTrue($read['allowed']);
        $this->assertSame('snapshot_stale', $write['reason_code']);
        $this->assertNotSame('restricted_safe_mode', $write['access_mode']);
    }

    /**
     * REWRITTEN — was `test_grace_window_is_configurable`, which asserted that
     * shrinking `grace_minutes` DENIED a paying customer sooner.
     *
     * `grace_minutes` is now an ALERTING threshold: it tunes how quickly we call our
     * own delivery pipeline unhealthy. It is no longer an access boundary, so
     * shrinking it must change the reported HEALTH and nothing else.
     */
    public function test_grace_window_configures_health_reporting_not_access(): void
    {
        Config::set('entitlements.grace_minutes', 60); // alert sooner
        $this->freeze('2026-07-11 09:00:00');

        // 48h old: inside the DEFAULT 72h alerting window, outside this 1h one.
        $this->storeSnapshot(pushedAt: '2026-07-09 09:00:00');

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        // The health state flips…
        $this->assertSame(EntitlementsCache::STATE_GRACE_EXPIRED, $decision['snapshot']['verification_state']);

        // …and access does not.
        $this->assertTrue(
            $decision['allowed'],
            'tightening an ALERTING threshold must never deny a paying customer.'
        );
    }

    public function test_legacy_max_stale_minutes_key_still_drives_the_stale_after_boundary(): void
    {
        // SolaStock's pre-existing config key must keep working as the `stale_after`
        // default — but it now only opens GRACE, it no longer denies on its own.
        Config::set('entitlements.stale_after_minutes', null);
        Config::set('inventory_entitlements.max_stale_minutes', 60);
        Config::set('entitlements.grace_minutes', 4320);

        $this->freeze('2026-07-11 09:00:00');
        $this->storeSnapshot(pushedAt: '2026-07-11 06:00:00'); // 3h old

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertSame(EntitlementsCache::STATE_GRACE, $decision['snapshot']['verification_state']);
        $this->assertTrue($decision['allowed'], 'the legacy key must not resurrect the hard lockout.');
    }

    public function test_no_snapshot_at_all_fails_closed_for_paid_features(): void
    {
        $this->freeze('2026-07-11 09:00:00');
        // Nothing stored.

        $gate = $this->gate();
        $paid = $gate->checkPermission(self::PAID_PERMISSION);
        $free = $gate->checkPermission('inventory.view_items');

        $this->assertFalse($paid['allowed'], 'a missing snapshot must fail CLOSED on paid features.');
        $this->assertSame('entitlement_service_unavailable', $paid['reason_code']);
        $this->assertTrue($free['allowed'], 'free permissions keep working without a snapshot.');
    }

    /* =====================================================================
     * UTC storage and monotonic ordering
     * ================================================================== */

    public function test_newer_snapshot_replaces_older_one(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        $this->storeSnapshot(pushedAt: '2026-07-11 07:00:00', version: 'v1');
        $this->storeSnapshot(pushedAt: '2026-07-11 08:00:00', version: 'v2');

        $this->assertSame('v2', $this->storedRow()->version);
    }

    public function test_out_of_order_delivery_cannot_overwrite_a_newer_snapshot(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        $this->storeSnapshot(pushedAt: '2026-07-11 08:00:00', version: 'v2');
        // A delayed retry of the OLDER push arrives late. It must not win.
        $this->storeSnapshot(pushedAt: '2026-07-11 07:00:00', version: 'v1');

        $this->assertSame('v2', $this->storedRow()->version);
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $this->freeze('2026-07-11 09:00:00');

        $this->storeSnapshot(pushedAt: '2026-07-11 08:00:00', version: 'v1');
        $first = $this->storedRow();

        // The SAME revision delivered again (a retry after a timeout that had in
        // fact succeeded). Must be a no-op, not a second write.
        $this->storeSnapshot(pushedAt: '2026-07-11 08:00:00', version: 'v1-replay');

        $this->assertSame(
            1,
            DB::connection('tenant')->table('tenant_entitlements_snapshots')
                ->where('client_id', self::CLIENT_ID)->count()
        );
        $this->assertSame('v1', $this->storedRow()->version, 'a duplicate revision must not rewrite the row.');
        $this->assertSame((string) $first->updated_at, (string) $this->storedRow()->updated_at);
    }

    public function test_a_newer_snapshot_is_not_rejected_when_the_server_runs_in_asia_amman(): void
    {
        // The regression: app tz is +03:00, so `now()` produced a wall clock three
        // hours ahead of central's UTC `pushed_at`. Ordering compared the two and
        // could reject a genuinely newer snapshot as "older".
        date_default_timezone_set('Asia/Amman');
        Config::set('app.timezone', 'Asia/Amman');

        $this->freeze('2026-07-11 09:00:00');

        $this->storeSnapshot(pushedAt: '2026-07-11 07:00:00', version: 'v1');
        $this->storeSnapshot(pushedAt: '2026-07-11 08:00:00', version: 'v2');

        $row = $this->storedRow();

        $this->assertSame('v2', $row->version, 'a newer snapshot must win regardless of server timezone.');

        // And the persisted instants are UTC, not +03:00 wall clock.
        $this->assertSame('2026-07-11 08:00:00', CarbonImmutable::parse((string) $row->pushed_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-11 08:00:00', CarbonImmutable::parse((string) $row->synced_at)->format('Y-m-d H:i:s'));
    }

    public function test_a_legacy_row_with_a_future_local_timestamp_does_not_freeze_the_tenant(): void
    {
        // THE LIVE SOLASTOCK DEFECT. d0ea747 wrote pushed_at in Asia/Amman wall
        // clock into a UTC column, leaving every production row 3h in the FUTURE.
        // A corrected UTC snapshot looks "older" than that and, under pure
        // timestamp ordering, would be rejected FOREVER — freezing the tenant at
        // whatever the last bad row said. The revision cutover must win.
        $this->freeze('2026-07-11 09:00:00');

        DB::connection('tenant')->table('tenant_entitlements_snapshots')->insert([
            'client_id' => self::CLIENT_ID,
            'project_slug' => self::SLUG,
            // No `_revision` key: a legacy, pre-fix row.
            'payload' => json_encode([
                'reason_code' => 'paid_active',
                'access_mode' => 'full',
                'effective_tier' => 'premium',
                'blocked_features' => [self::PAID_FEATURE],
            ]),
            'version' => 'legacy',
            'pushed_at' => '2026-07-11 12:00:00', // 3h in the FUTURE
            'synced_at' => '2026-07-11 12:00:00',
        ]);

        $this->storeSnapshot(pushedAt: '2026-07-11 09:00:00', version: 'fixed');

        $this->assertSame(
            'fixed',
            $this->storedRow()->version,
            'a corrected UTC snapshot must replace a future-dated legacy row, not be rejected as older.'
        );
        $this->assertTrue($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    public function test_a_future_pushed_at_never_denies_access(): void
    {
        // Today EVERY live SolaStock row is future-dated. Negative age must clamp to
        // zero, never be treated as "so old that grace expired".
        $this->freeze('2026-07-11 09:00:00');
        $this->storeSnapshot(pushedAt: '2026-07-11 12:00:00');

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertSame(EntitlementsCache::STATE_VERIFIED, $decision['snapshot']['verification_state']);
        $this->assertSame(0, $decision['snapshot']['age_minutes']);
        $this->assertTrue($decision['allowed']);
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    private function freeze(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        EntitlementClock::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The real cache (real DB reads), with the client id forced. */
    private function gate(): InventoryCommercialEntitlementService
    {
        $cache = new class extends EntitlementsCache {
            public function currentClientId(): ?int
            {
                return EntitlementVerificationGraceTest::clientId();
            }
        };

        return new InventoryCommercialEntitlementService($cache);
    }

    public static function clientId(): int
    {
        return self::CLIENT_ID;
    }

    private function storedRow(): object
    {
        return DB::connection('tenant')
            ->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', self::SLUG)
            ->first();
    }

    private function storeSnapshot(string $pushedAt, string $version = 'v1', array $extra = []): void
    {
        Cache::flush();

        $pushed = CarbonImmutable::parse($pushedAt, 'UTC');

        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            self::SLUG,
            $extra + [
                'access_mode' => 'full',
                'effective_tier' => 'premium',
                'reason_code' => 'paid_active',
                'allowed_features' => [self::PAID_FEATURE],
            ],
            $version,
            $pushed,
            [
                // Monotonic revision, exactly as the fixed pusher/receiver emits it.
                'revision' => $pushed->getTimestampMs(),
                'evaluated_at' => $pushed->toIso8601String(),
                'pushed_at' => $pushed->toIso8601String(),
                'valid_until' => $pushed->addHours(4)->toIso8601String(),
                'schema_version' => '2026-07-07',
                'source_version' => 'test',
                'state_hash' => 'hash-' . $version,
            ]
        );

        Cache::flush();
    }

    private function deleteRows(): void
    {
        DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->delete();
    }
}
