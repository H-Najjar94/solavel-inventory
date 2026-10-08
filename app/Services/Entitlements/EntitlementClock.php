<?php

namespace App\Services\Entitlements;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The ONE clock entitlement code is allowed to read.
 *
 * Every entitlement timestamp — pushed_at, valid_until, evaluated_at, synced_at,
 * created_at, updated_at — is an absolute instant, so it is stored and compared
 * in UTC and nowhere else.
 *
 * This class exists because `now()` is timezone-dependent: these apps run with
 * `app.timezone = Asia/Amman`, so `now()` returned +03:00 wall-clock while
 * central's `pushed_at`/`valid_until` arrived as UTC. SolaStock then went one
 * step further (commit d0ea747, "store snapshot timestamps in app time, not
 * UTC") and coerced central's UTC instants into Asia/Amman wall clock before
 * writing them to a UTC column — leaving every live row three hours in the
 * FUTURE. A corrected UTC snapshot looks "older" than a future-dated row and
 * would be rejected forever, freezing the tenant.
 *
 * Rule: entitlement code calls EntitlementClock::now(), never now()/Carbon::now().
 */
final class EntitlementClock
{
    /** Test seam: freeze the clock. Null = real time. */
    private static ?CarbonImmutable $testNow = null;

    public static function now(): CarbonImmutable
    {
        if (self::$testNow !== null) {
            return self::$testNow;
        }

        // CarbonImmutable::now() does NOT honour Carbon::setTestNow() — they keep
        // separate statics. Without this bridge the clock would ignore Laravel's
        // travelTo()/setTestNow() and silently read the real wall clock inside
        // tests, which is precisely the sort of blind spot that let a timezone
        // defect live in this code for months.
        if (Carbon::hasTestNow()) {
            return CarbonImmutable::instance(Carbon::getTestNow())->utc();
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * Normalise any incoming value to an absolute UTC instant.
     *
     * Offset-aware strings ("2026-07-11T09:00:00+03:00") keep their instant.
     * Naive strings ("2026-07-11 09:00:00") are read as UTC, which is what
     * every entitlement column now stores.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(
                $value instanceof \DateTime ? $value : \DateTime::createFromInterface($value)
            )->utc();
        }

        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Canonical wire/storage format: UTC, unambiguous. */
    public static function format(?CarbonImmutable $at): ?string
    {
        return $at?->utc()->format('Y-m-d H:i:s');
    }

    public static function iso(?CarbonImmutable $at): ?string
    {
        return $at?->utc()->toIso8601String();
    }

    public static function setTestNow(?CarbonImmutable $at): void
    {
        self::$testNow = $at?->utc();
    }
}
