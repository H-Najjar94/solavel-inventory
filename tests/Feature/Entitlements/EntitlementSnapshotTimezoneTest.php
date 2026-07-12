<?php

namespace Tests\Feature\Entitlements;

use App\Services\Entitlements\EntitlementsCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SolaStock used to write `tenant_entitlements_snapshots` timestamps in UTC
 * while finance/hr/projects wrote app-local (Asia/Amman) time. Its own reads
 * were self-consistent, so nothing broke inside SolaStock — but every row sat a
 * constant 3h behind its siblings for the SAME central push, which made
 * cross-app freshness checks and any stale-snapshot monitor read SolaStock as
 * permanently stale. Timestamps must now round-trip in app time.
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

        DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->delete();
    }

    protected function tearDown(): void
    {
        DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->delete();

        parent::tearDown();
    }

    public function test_snapshot_timestamps_are_stored_in_app_time_not_utc(): void
    {
        $syncedAt = now();

        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            'inventory',
            ['flags' => ['rentals' => true]],
            'v-test',
            $syncedAt,
            ['evaluated_at' => $syncedAt->toIso8601String()],
        );

        $row = DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', 'inventory')
            ->first();

        $this->assertNotNull($row, 'snapshot row was not written');

        // The stored wall-clock string must match app-local time. If the old
        // ->utc() coercion came back, this reads 3h behind and fails.
        $this->assertSame(
            $syncedAt->format('Y-m-d H:i:s'),
            Carbon::parse((string) $row->synced_at)->format('Y-m-d H:i:s'),
            'synced_at was not stored in app time (Asia/Amman) — UTC skew regressed.'
        );

        $this->assertSame(
            $syncedAt->format('Y-m-d H:i:s'),
            Carbon::parse((string) $row->evaluated_at)->format('Y-m-d H:i:s'),
            'evaluated_at was not stored in app time — UTC skew regressed.'
        );
    }

    public function test_a_freshly_written_snapshot_does_not_read_back_as_stale(): void
    {
        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            'inventory',
            ['flags' => []],
            'v-test',
            now(),
            ['pushed_at' => now()->toIso8601String()],
        );

        // Read through the DB path, not the write-through cache — the DB path
        // is where the timezone is re-interpreted, and it is what a cold app
        // (or a staleness monitor) actually hits.
        Cache::flush();

        $payload = app(EntitlementsCache::class)->getProjectSnapshot(self::CLIENT_ID, 'inventory');

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('_snapshot', $payload);
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

        $cache->storeProjectSnapshot(
            self::CLIENT_ID, 'inventory', ['flags' => ['new' => true]], 'v-new',
            now(), ['pushed_at' => now()->toIso8601String()],
        );

        // A retry/replay of an OLDER push must be ignored, not applied.
        $cache->storeProjectSnapshot(
            self::CLIENT_ID, 'inventory', ['flags' => ['old' => true]], 'v-old',
            now()->subHour(), ['pushed_at' => now()->subHour()->toIso8601String()],
        );

        $row = DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', 'inventory')
            ->first();

        $this->assertSame('v-new', $row->version, 'an older replayed snapshot clobbered a newer one.');
    }
}
