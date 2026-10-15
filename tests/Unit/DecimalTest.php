<?php

namespace Tests\Unit;

use App\Services\Stock\Support\Decimal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure decimal-math unit tests (no Laravel, no database). Proves the stock/cost
 * arithmetic is deterministic and float-free.
 */
class DecimalTest extends TestCase
{
    #[Test]
    public function weighted_average_math_is_exact(): void
    {
        // (10*5 + 10*7) / 20 = 6
        $num = Decimal::add(Decimal::mul('10', '5'), Decimal::mul('10', '7'));
        $avg = Decimal::div($num, '20');
        $this->assertSame('6.0000', Decimal::cost($avg));
    }

    #[Test]
    public function rounding_is_half_up_at_money_scale(): void
    {
        // 2.5 * 1.25 = 3.125, with the approved universal precision 3.
        $this->assertSame('3.125', Decimal::money(Decimal::mul('2.5', '1.25')));
        $this->assertSame('12.346', Decimal::money('12.3455'));
        $this->assertSame('-12.346', Decimal::money('-12.3455'));
    }

    #[Test]
    public function partial_reversal_costs_telescope_to_the_original_three_decimal_amount(): void
    {
        $original = '12.345';
        $prefix = Decimal::money(Decimal::div($original, '3', 12));
        $secondPrefix = Decimal::money(Decimal::mul(Decimal::div($original, '3', 12), '2', 12));
        $last = Decimal::sub($original, $secondPrefix, Decimal::MONEY_SCALE);
        $this->assertSame($original, Decimal::add(Decimal::add($prefix, Decimal::sub($secondPrefix, $prefix)), $last, Decimal::MONEY_SCALE));
        // Historical two-decimal totals retain their exact value.
        $this->assertSame('12.340', Decimal::money('12.34'));
    }

    #[Test]
    public function comparisons_and_zero_checks_work(): void
    {
        $this->assertTrue(Decimal::gt('5', '4.9999'));
        $this->assertTrue(Decimal::isZero('0.00000'));
        $this->assertFalse(Decimal::gt('1', '1'));
        $this->assertSame(-1, Decimal::cmp('1', '2'));
    }

    #[Test]
    public function fractional_quantity_scale_is_four(): void
    {
        $this->assertSame('2.5000', Decimal::qty('2.5'));
        $this->assertSame('0.3333', Decimal::qty(Decimal::div('1', '3')));
    }

    #[Test]
    public function division_by_zero_is_safe(): void
    {
        $this->assertSame('0', Decimal::div('5', '0'));
    }
}
