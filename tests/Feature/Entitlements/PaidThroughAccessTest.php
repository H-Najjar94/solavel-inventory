<?php

namespace Tests\Feature\Entitlements;

use App\Http\Controllers\Api\Tenancy\SyncEventsController;
use App\Services\Entitlements\EntitlementClock;
use App\Services\Entitlements\EntitlementSigner;
use App\Services\Entitlements\EntitlementsCache;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PAID-THROUGH ACCESS — SolaStock.
 *
 * The rule: a customer keeps what they paid for until `access_until`, however old
 * the local entitlement copy becomes. Snapshot age is infrastructure health and
 * may never expire paid access.
 *
 * These tests exist because the previous two models both got this wrong — first by
 * denying at 24h, then by denying at 24h + 72h of "grace". Both turned a broken
 * delivery pipeline into a customer-visible downgrade: a paying Premium tenant was
 * dropped into "restricted safe mode" because OUR push failed and nothing retried.
 *
 * Note the setUp: the staleness thresholds are set to ONE MINUTE. Every snapshot in
 * this file is therefore wildly "expired" under the old model. If any code path can
 * still deny on age, these tests fail.
 *
 * Like the other SolaStock entitlement tests, this runs against the reserved MySQL
 * test tenant (TenancySafetyGuard forbids a ':memory:' tenant connection), writing
 * and deleting only its own snapshot rows. DML only — no DDL.
 */
class PaidThroughAccessTest extends TestCase
{
    /** Row key only — NOT a database. Isolated from the other entitlement tests. */
    private const CLIENT_ID = 990012;

    private const SLUG = 'inventory';

    /** A paid, feature-gated permission. */
    private const PAID_PERMISSION = 'inventory.manage_shipments';

    private const PAID_FEATURE = 'stock.sales_fulfillment';

    /** A second paid feature, used to prove a downgrade is surgical. */
    private const OTHER_PERMISSION = 'inventory.view_reports';

    private const OTHER_FEATURE = 'inventory.reports';

    private const SIGNING_KEY = 'solastock-test-signing-key';

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('tenant')->hasTable('tenant_entitlements_snapshots')) {
            $this->markTestSkipped('tenant_entitlements_snapshots not migrated on the test tenant.');
        }

        $this->originalTimezone = date_default_timezone_get();

        Config::set('cache.default', 'array');

        // Deliberately ABSURD staleness thresholds. Under the old model virtually
        // every snapshot below would be denied; under the paid-through model these
        // numbers may do nothing but alert.
        Config::set('entitlements.stale_after_minutes', 1);
        Config::set('entitlements.grace_minutes', 1);

        Config::set('entitlements.signing.key_id', 'v1');
        Config::set('entitlements.signing.keys', ['v1' => self::SIGNING_KEY]);
        Config::set('entitlements.signing.require_signature', false);

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
        Mockery::close();

        parent::tearDown();
    }

    /* ================================================================
     * The core rule: age is not an expiry
     * ============================================================= */

    #[Test]
    public function premium_stays_enabled_when_the_snapshot_is_weeks_old_but_still_paid_through(): void
    {
        $this->freeze('2026-07-14 09:00:00');

        // Pushed 5 WEEKS ago. Under the old model this was denied twice over.
        // The customer is paid through September.
        $this->storeSnapshot(
            pushedAt: '2026-06-09 09:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertTrue(
            $decision['allowed'],
            'A five-week-old snapshot that is still paid through September must NOT deny.'
        );
        $this->assertSame(200, $decision['status']);
    }

    #[Test]
    public function an_old_valid_snapshot_never_denies_on_age_alone(): void
    {
        $this->freeze('2026-07-14 09:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-01-01 00:00:00',  // over six months stale
            accessUntil: null,                 // unbounded subscription
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        // The snapshot is reported as UNHEALTHY…
        $this->assertSame(EntitlementsCache::STATE_GRACE_EXPIRED, $decision['snapshot']['verification_state']);
        $this->assertTrue($decision['snapshot']['beyond_max_stale']);

        // …and that health signal must not deny anyone.
        $this->assertTrue($decision['allowed'], 'Delivery health must never gate paid access.');
    }

    #[Test]
    public function unbounded_access_until_is_not_treated_as_expired(): void
    {
        $this->freeze('2026-07-14 09:00:00');

        // access_until = null means UNBOUNDED (free/perpetual). The absence of an
        // expiry is not an expiry.
        $this->storeSnapshot(pushedAt: '2026-07-14 08:00:00', accessUntil: null);

        $this->assertTrue($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    /* ================================================================
     * Commercial boundaries — these MUST still deny
     * ============================================================= */

    #[Test]
    public function access_is_denied_immediately_after_access_until(): void
    {
        $this->freeze('2026-09-29 06:11:01'); // one second past

        $this->storeSnapshot(
            pushedAt: '2026-09-29 06:00:00',  // perfectly FRESH snapshot
            accessUntil: '2026-09-29T06:11:00+00:00',
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed']);
        $this->assertSame(402, $decision['status']);
        $this->assertSame('subscription_expired', $decision['reason_code']);

        // And it is a COMMERCIAL denial, not an infrastructure one.
        $this->assertFalse($this->gate()->isInfrastructureDenial($decision['reason_code']));
    }

    #[Test]
    public function cancel_at_period_end_keeps_access_through_access_until(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        // Cancelled — but paid through September. They cancelled; they did not ask
        // for a refund.
        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
            extra: ['subscription_status' => 'canceled', 'cancel_at_period_end' => true],
        );

        $this->assertTrue($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    #[Test]
    public function cancel_at_period_end_denies_once_the_period_actually_ends(): void
    {
        $this->freeze('2026-09-30 00:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
            extra: ['subscription_status' => 'canceled', 'cancel_at_period_end' => true],
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('subscription_expired', $decision['reason_code']);
    }

    #[Test]
    public function immediate_revocation_denies_even_though_paid_through_is_in_the_future(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        // Revoked on 2026-07-20 despite being paid through September. An explicit
        // revocation OUTRANKS the paid-through date — a chargeback or a ToS
        // termination must take effect now, not in September.
        $this->storeSnapshot(
            pushedAt: '2026-07-20 10:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
            extra: ['revoked_at' => '2026-07-20T10:00:00+00:00'],
        );

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('subscription_revoked', $decision['reason_code']);
    }

    #[Test]
    public function suspension_denies(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-07-20 10:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
            extra: ['suspended_at' => '2026-07-25T00:00:00+00:00', 'subscription_status' => 'suspended'],
        );

        $this->assertFalse($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    #[Test]
    public function explicit_de_entitlement_still_denies_regardless_of_paid_through(): void
    {
        // The pre-existing SolaStock enforcement signals — kept, because an
        // enforcement signal we stop reading is an enforcement signal we have lost.
        $this->freeze('2026-08-01 09:00:00');

        foreach ([
            ['accessible' => false],
            ['commercially_entitled' => false],
            ['status' => ['reason_code' => 'subscription_cancelled']],
            ['reason_code' => 'subscription_suspended'],
            ['access_mode' => 'blocked'],
        ] as $deEntitlement) {
            $this->deleteRows();
            Cache::flush();

            $this->storeSnapshot(
                pushedAt: '2026-07-20 10:00:00',
                accessUntil: '2026-09-29T06:11:00+00:00', // paid through the future
                extra: $deEntitlement,
            );

            $this->assertFalse(
                $this->gate()->checkPermission(self::PAID_PERMISSION)['allowed'],
                'An explicit de-entitlement must deny even when access_until is in the future: '
                    . json_encode($deEntitlement)
            );
        }
    }

    /* ================================================================
     * Plan changes
     * ============================================================= */

    #[Test]
    public function a_downgrade_removes_only_the_features_the_newer_revision_removed(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: null,
            flags: [self::PAID_FEATURE => true, self::OTHER_FEATURE => true],
            version: 'premium',
        );

        // Newer revision: downgraded plan. Sales fulfilment is gone; reports stay.
        $this->storeSnapshot(
            pushedAt: '2026-08-01 08:00:00',
            accessUntil: null,
            flags: [self::PAID_FEATURE => false, self::OTHER_FEATURE => true],
            version: 'standard',
        );

        $gate = $this->gate();
        $removed = $gate->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($removed['allowed']);
        $this->assertSame('feature_not_in_plan', $removed['reason_code']);
        $this->assertTrue(
            $gate->checkPermission(self::OTHER_PERMISSION)['allowed'],
            'A downgrade must remove ONLY what the newer revision removed.'
        );
    }

    #[Test]
    public function a_renewal_extends_access_until(): void
    {
        $this->freeze('2026-09-30 09:00:00'); // past the OLD access_until

        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: '2026-09-29T06:11:00+00:00',
            version: 'v1',
        );

        // Expired under the old window…
        $this->assertFalse($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);

        // …renewal publishes a later access_until.
        $this->storeSnapshot(
            pushedAt: '2026-09-28 09:00:00',
            accessUntil: '2026-12-29T06:11:00+00:00',
            version: 'v2',
        );

        $this->assertTrue($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    #[Test]
    public function no_entitlement_at_all_fails_closed(): void
    {
        $this->freeze('2026-07-14 09:00:00');
        // Nothing stored: we know nothing, so we deny paid features.

        $gate = $this->gate();
        $paid = $gate->checkPermission(self::PAID_PERMISSION);
        $free = $gate->checkPermission('inventory.view_items');

        $this->assertFalse($paid['allowed'], 'A missing snapshot must fail CLOSED on paid features.');
        $this->assertSame('entitlement_service_unavailable', $paid['reason_code']);
        $this->assertTrue($gate->isInfrastructureDenial($paid['reason_code']));

        $this->assertTrue($free['allowed'], 'Free permissions keep working without a snapshot.');
    }

    /* ================================================================
     * Signature: an unverifiable entitlement never replaces a valid one
     * ============================================================= */

    #[Test]
    public function a_correctly_signed_entitlement_is_applied(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        $response = $this->push($this->signedPayload(
            clientId: self::CLIENT_ID,
            version: 'signed-v1',
            accessUntil: '2026-12-01T00:00:00+00:00',
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('signed-v1', $this->storedRow()->version);
        $this->assertTrue($this->gate()->checkPermission(self::PAID_PERMISSION)['allowed']);
    }

    #[Test]
    public function an_invalid_signature_does_not_replace_the_last_valid_entitlement(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        // A good entitlement is in place: paid through December.
        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: '2026-12-01T00:00:00+00:00',
            version: 'trusted',
        );

        // An attacker (or a corrupted pipeline) pushes a FORGED entitlement that
        // would strip the customer's feature. The body is mutated after signing, so
        // the signature no longer matches its canonical bytes.
        $forged = $this->signedPayload(
            clientId: self::CLIENT_ID,
            version: 'forged',
            accessUntil: '2020-01-01T00:00:00+00:00', // long expired
        );
        $forged['projects'][self::SLUG]['flags'][self::PAID_FEATURE] = false;

        $response = $this->push($forged);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('entitlement_signature_invalid', $response->getData(true)['code']);

        // The PREVIOUS, valid entitlement is untouched — and the customer still has
        // the feature they paid for.
        Cache::flush();
        $this->assertSame('trusted', $this->storedRow()->version);
        $this->assertTrue(
            $this->gate()->checkPermission(self::PAID_PERMISSION)['allowed'],
            'A forged entitlement must not be able to downgrade a paying customer.'
        );
    }

    #[Test]
    public function an_entitlement_signed_for_another_tenant_is_rejected(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-07-01 09:00:00',
            accessUntil: '2026-12-01T00:00:00+00:00',
            version: 'trusted',
        );

        // Correctly signed — but issued for a DIFFERENT client, then replayed into
        // this tenant. A valid signature is not enough; it must be OUR entitlement.
        $replayed = $this->signedPayload(
            clientId: 990099,
            version: 'replayed',
            accessUntil: '2030-01-01T00:00:00+00:00',
        );

        $response = $this->push($replayed, envelopeClientId: self::CLIENT_ID);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('entitlement_tenant_mismatch', $response->getData(true)['code']);

        Cache::flush();
        $this->assertSame('trusted', $this->storedRow()->version);
    }

    #[Test]
    public function an_unsigned_entitlement_is_accepted_during_rollout_but_rejected_once_required(): void
    {
        $this->freeze('2026-08-01 09:00:00');

        $unsigned = $this->payload(self::CLIENT_ID, 'unsigned-v1', '2026-12-01T00:00:00+00:00');

        // Rollout: central has not started signing yet. A pre-signing snapshot is
        // not a forgery — accept it and flag it.
        $this->assertSame(200, $this->push($unsigned)->getStatusCode());
        $this->assertSame('unsigned-v1', $this->storedRow()->version);

        // Once every app is signing, an unsigned payload IS a red flag.
        Config::set('entitlements.signing.require_signature', true);

        $later = $this->payload(self::CLIENT_ID, 'unsigned-v2', '2026-12-01T00:00:00+00:00');
        $later['pushed_at'] = '2026-08-01T08:00:00+00:00';
        $later['revision'] = CarbonImmutable::parse('2026-08-01 08:00:00', 'UTC')->getTimestampMs();

        $response = $this->push($later);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('entitlement_signature_missing', $response->getData(true)['code']);
        $this->assertSame('unsigned-v1', $this->storedRow()->version, 'the previous entitlement is retained.');
    }

    /* ================================================================
     * Timezone independence
     * ============================================================= */

    #[Test]
    #[DataProvider('timezones')]
    public function paid_through_is_evaluated_in_utc_under_any_server_timezone(string $tz): void
    {
        date_default_timezone_set($tz);
        Config::set('app.timezone', $tz);

        // 08:00 UTC. In Asia/Amman that is 11:00 local — a naive comparison against
        // a local wall clock would get this wrong by three hours in one direction
        // or the other.
        $this->freeze('2026-09-29 08:00:00');

        $this->storeSnapshot(
            pushedAt: '2026-01-01 00:00:00',                // ancient, deliberately
            accessUntil: '2026-09-29T09:00:00+00:00',       // one hour of access left, in UTC
        );

        $this->assertTrue(
            $this->gate()->checkPermission(self::PAID_PERMISSION)['allowed'],
            "Access must still be live in {$tz}: access_until is one hour away in UTC."
        );

        // …and an hour later it is gone, in every timezone.
        $this->freeze('2026-09-29 09:00:01');
        Cache::flush();

        $decision = $this->gate()->checkPermission(self::PAID_PERMISSION);

        $this->assertFalse($decision['allowed'], "Access must have expired in {$tz}.");
        $this->assertSame('subscription_expired', $decision['reason_code']);
    }

    public static function timezones(): array
    {
        return [
            'Asia/Amman' => ['Asia/Amman'],
            'UTC' => ['UTC'],
            'America/Los_Angeles' => ['America/Los_Angeles'],
            'Pacific/Kiritimati' => ['Pacific/Kiritimati'],
        ];
    }

    /* ================================================================
     * Helpers
     * ============================================================= */

    private function freeze(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        EntitlementClock::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The real cache (real DB reads), with the client id forced. */
    private function gate(): InventoryCommercialEntitlementService
    {
        Cache::flush();

        $cache = new class extends EntitlementsCache
        {
            public function currentClientId(): ?int
            {
                return PaidThroughAccessTest::clientId();
            }
        };

        return new InventoryCommercialEntitlementService($cache);
    }

    public static function clientId(): int
    {
        return self::CLIENT_ID;
    }

    private function storedRow(): ?object
    {
        return DB::connection('tenant')
            ->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->where('project_slug', self::SLUG)
            ->first();
    }

    /**
     * The SolaStock project slice, as central now publishes it.
     *
     * @param  array<string,bool>|null  $flags
     */
    private function slice(?string $accessUntil, ?array $flags = null, array $extra = []): array
    {
        return $extra + [
            'app' => self::SLUG,
            'flags' => $flags ?? [self::PAID_FEATURE => true, self::OTHER_FEATURE => true],
            'effective_tier' => 'premium',
            'access_mode' => 'full',
            'reason_code' => 'paid_active',
            'subscription_status' => 'active',
            'plan_id' => 9,
            'access_until' => $accessUntil,
            'cancel_at_period_end' => false,
        ];
    }

    private function storeSnapshot(
        string $pushedAt,
        ?string $accessUntil,
        ?array $flags = null,
        string $version = 'v1',
        array $extra = [],
    ): void {
        Cache::flush();

        $pushed = CarbonImmutable::parse($pushedAt, 'UTC');

        app(EntitlementsCache::class)->storeProjectSnapshot(
            self::CLIENT_ID,
            self::SLUG,
            $this->slice($accessUntil, $flags, $extra),
            $version,
            $pushed,
            [
                'revision' => $pushed->getTimestampMs(),
                'evaluated_at' => $pushed->toIso8601String(),
                'pushed_at' => $pushed->toIso8601String(),
                'valid_until' => $pushed->addHours(4)->toIso8601String(),
                'schema_version' => '2026-07-14',
                'source_version' => 'test',
                'state_hash' => 'hash-' . $version,
            ]
        );

        Cache::flush();
    }

    /** The full sync payload central pushes (the thing that gets signed). */
    private function payload(int $clientId, string $version, ?string $accessUntil): array
    {
        $pushed = CarbonImmutable::parse('2026-08-01 08:30:00', 'UTC');

        return [
            'client_id' => $clientId,
            'version' => $version,
            'revision' => $pushed->getTimestampMs(),
            'pushed_at' => $pushed->toIso8601String(),
            'evaluated_at' => $pushed->toIso8601String(),
            'valid_until' => $pushed->addHours(4)->toIso8601String(),
            'projects' => [
                self::SLUG => $this->slice($accessUntil),
            ],
        ];
    }

    private function signedPayload(int $clientId, string $version, ?string $accessUntil): array
    {
        return app(EntitlementSigner::class)->sign($this->payload($clientId, $version, $accessUntil));
    }

    /** Drive the real ingest receiver. */
    private function push(array $payload, ?int $envelopeClientId = null): \Illuminate\Http\JsonResponse
    {
        $clientId = $envelopeClientId ?? (int) $payload['client_id'];

        $request = Request::create('/inventory/api/tenancy/sync/events', 'POST', [
            'event_id' => 'paid-through-' . uniqid(),
            'event_action' => 'upsert',
            'resource_type' => 'entitlements_snapshot',
            'resource_id' => (string) $clientId,
            'client_id' => $clientId,
            'tenant_db' => 'tenant_990010',
            'payload' => $payload,
        ]);

        $tenants = Mockery::mock(TenantManager::class);
        $tenants->shouldReceive('switchToDatabase')->andReturnNull();

        $controller = new SyncEventsController($tenants, app(EntitlementsCache::class), app(EntitlementSigner::class));

        Cache::flush();

        return $controller($request);
    }

    private function deleteRows(): void
    {
        DB::connection('tenant')->table('tenant_entitlements_snapshots')
            ->where('client_id', self::CLIENT_ID)
            ->delete();
    }
}
