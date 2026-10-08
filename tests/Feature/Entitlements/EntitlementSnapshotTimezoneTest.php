<?php

namespace Tests\Feature\Entitlements;

use App\Services\Entitlements\EntitlementClock;
use App\Services\Entitlements\EntitlementsCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `tenant_entitlements_snapshots` timestamps are absolute UTC instants.
 *
 * ── Why this file changed ────────────────────────────────────────────────────
 * It used to assert the OPPOSITE: `test_snapshot_timestamps_are_stored_in_app_time_not_utc`
 * pinned the behaviour introduced by d0ea747 ("store snapshot timestamps in app
 * time, not UTC"), whose stated goal was to stop SolaStock reading as permanently
 * stale next to finance/hr/projects.
 *
 * That fix was wrong, and this test was locking the bug in. `pushed_at` /
 * `synced_at` / `evaluated_at` / `valid_until` are UTC DATETIME columns. Writing
 * Asia/Amman WALL CLOCK into them does not change the timezone of the column — it
 * just stores the wrong instant. Verified in production: SolaStock's live rows are
 * three hours in the FUTURE (stored 19:30 when real UTC was 16:30).
 *
 * That is an ordering hazard, not a cosmetic one. Snapshot ordering compares
 * `pushed_at`, so once the corrected pusher writes real UTC, a genuinely NEWER
 * snapshot looks OLDER than the future-dated row already on disk and is rejected —
 * forever. The tenant's entitlements freeze at whatever the last bad row said.
 *
 * The correct fix for the cross-app skew is for EVERY app to store the same
 * absolute instant (UTC), which is what EntitlementClock now guarantees. So these
 * tests now assert the UTC contract, and the ordering cutover that rescues the
 * already-poisoned rows is covered in EntitlementVerificationGraceTest.
 */
class EntitlementSnapshotTimezoneTest extends TestCase
{
    private const CLIENT_ID = 990004;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
            $this->markTestSkipped('tenant_entitlements_snapshots not migrated on the test tenant.');
        }

        Cache::flush();
        $this->deleteRows();
    }

    protected function tearDown(): void
    {
        $this->deleteRows();
        EntitlementClock::setTestNow(null);
        Cache::flush();

        parent::tearDown();
    }

    private function deleteRows(): void
    {
        DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->delete();
    }

    public function test_snapshot_timestamps_are_stored_as_absolute_utc(): void
    {
        // An offset-aware instant: 12:00 in Amman is 09:00 UTC. The column must
        // receive the UTC instant (09:00), NOT the local wall clock (12:00).
        $pushedAt = CarbonImmutable::parse('2026-07-11 12:00:00', 'Asia/Amman');

        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            'inventory',
            ['flags' => ['rentals' => true]],
            'v-test',
            $pushedAt,
            [
                'evaluated_at' => $pushedAt->toIso8601String(),
                'pushed_at' => $pushedAt->toIso8601String(),
            ],
        );

        $row = DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', 'inventory')
            ->first();

        $this->assertNotNull($row, 'snapshot row was not written');

        // If d0ea747's app-local coercion ever comes back, these read 12:00:00 and fail.
        $this->assertSame(
            '2026-07-11 09:00:00',
            CarbonImmutable::parse((string) $row->synced_at)->format('Y-m-d H:i:s'),
            'synced_at must be the absolute UTC instant, not Asia/Amman wall clock.'
        );

        $this->assertSame(
            '2026-07-11 09:00:00',
            CarbonImmutable::parse((string) $row->pushed_at)->format('Y-m-d H:i:s'),
            'pushed_at must be the absolute UTC instant, not Asia/Amman wall clock.'
        );

        $this->assertSame(
            '2026-07-11 09:00:00',
            CarbonImmutable::parse((string) $row->evaluated_at)->format('Y-m-d H:i:s'),
            'evaluated_at must be the absolute UTC instant, not Asia/Amman wall clock.'
        );
    }

    public function test_a_freshly_written_snapshot_does_not_read_back_as_stale(): void
    {
        $now = EntitlementClock::now();

        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            'inventory',
            ['flags' => []],
            'v-test',
            $now,
            ['pushed_at' => $now->toIso8601String()],
        );

        // Read through the DB path, not the write-through cache — the DB path is
        // where the timezone is re-interpreted, and it is what a cold app (or a
        // staleness monitor) actually hits.
        Cache::flush();

        $payload = app(EntitlementsCache::class)->getProjectSnapshot(self::CLIENT_ID, 'inventory');

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('_snapshot', $payload);
        $this->assertSame(
            EntitlementsCache::STATE_VERIFIED,
            $payload['_snapshot']['verification_state'],
            'a snapshot written just now must read back as VERIFIED.'
        );
        $this->assertFalse(
            $payload['_snapshot']['beyond_max_stale'],
            'a snapshot written just now must not read back as beyond max stale.'
        );
        $this->assertFalse(
            $payload['_snapshot']['stale'],
            'a snapshot written just now must not read back as stale.'
        );
    }

    public function test_an_older_snapshot_does_not_overwrite_a_newer_one(): void
    {
        $cache = app(EntitlementsCache::class);
        $now = EntitlementClock::now();

        $cache->storeProjectSnapshot(
            self::CLIENT_ID, 'inventory', ['flags' => ['new' => true]], 'v-new',
            $now, ['pushed_at' => $now->toIso8601String()],
        );

        // A retry/replay of an OLDER push must be ignored, not applied.
        $cache->storeProjectSnapshot(
            self::CLIENT_ID, 'inventory', ['flags' => ['old' => true]], 'v-old',
            $now->subHour(), ['pushed_at' => $now->subHour()->toIso8601String()],
        );

        $row = DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', 'inventory')
            ->first();

        $this->assertSame('v-new', $row->version, 'an older replayed snapshot clobbered a newer one.');
    }
}
