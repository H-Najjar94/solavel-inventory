<?php

namespace App\Services\Stock\Support;

/**
 * Deterministic decimal arithmetic via bcmath. No float math is used anywhere in
 * the stock/cost path. Quantities use scale 4; money totals use scale 2; we
 * compute at a higher scale (8) internally and round at the boundary.
 */
final class Decimal
{
    public const QTY_SCALE = 4;
    public const MONEY_SCALE = 2;
    public const COST_SCALE = 4;
    private const CALC_SCALE = 10;

    public static function add(string $a, string $b, int $scale = self::CALC_SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::CALC_SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::CALC_SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::CALC_SCALE): string
    {
        if (self::isZero($b)) {
            return '0';
        }

        return bcdiv($a, $b, $scale);
    }

    /** -1 if a<b, 0 if equal, 1 if a>b (at given scale). */
    public static function cmp(string $a, string $b, int $scale = self::CALC_SCALE): int
    {
        return bccomp($a, $b, $scale);
    }

    public static function isZero(string $a, int $scale = self::CALC_SCALE): bool
    {
        return bccomp($a, '0', $scale) === 0;
    }

    public static function gt(string $a, string $b, int $scale = self::CALC_SCALE): bool
    {
        return bccomp($a, $b, $scale) === 1;
    }

    public static function gte(string $a, string $b, int $scale = self::CALC_SCALE): bool
    {
        return bccomp($a, $b, $scale) >= 0;
    }

    public static function lt(string $a, string $b, int $scale = self::CALC_SCALE): bool
    {
        return bccomp($a, $b, $scale) === -1;
    }

    /** Round half-up to the given scale (bcmath truncates, so we add a guard). */
    public static function round(string $value, int $scale): string
    {
        if (str_contains($value, '.')) {
            $guard = '0.'.str_repeat('0', $scale).'5';
            $value = bcadd($value, (self::lt($value, '0') ? '-'.$guard : $guard), $scale + 1);
        }

        return bcadd($value, '0', $scale);
    }

    public static function qty(string $value): string
    {
        return self::round($value, self::QTY_SCALE);
    }

    public static function cost(string $value): string
    {
        return self::round($value, self::COST_SCALE);
    }

    public static function money(string $value): string
    {
        return self::round($value, self::MONEY_SCALE);
    }
}
