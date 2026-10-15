<?php

namespace Tests\Unit\Integration;

use App\Models\Tenant\OpeningStockEntryLine;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use PHPUnit\Framework\TestCase;

final class StockMoneyPrecisionTest extends TestCase
{
    public function test_ledger_and_opening_models_preserve_three_decimals_without_changing_historical_raw_values(): void
    {
        foreach ([StockLedger::class, OpeningStockEntryLine::class] as $class) {
            $model = new $class();
            $model->setRawAttributes(['total_cost' => '12.345000']);
            self::assertSame('12.345', $model->total_cost);
            self::assertSame('12.345000', $model->getRawOriginal('total_cost') ?? $model->getAttributes()['total_cost']);
            $model->setRawAttributes(['total_cost' => '12.340000']);
            self::assertSame('12.340', $model->total_cost);
            self::assertSame('12.340000', $model->getAttributes()['total_cost']);
        }
    }

    public function test_quantity_cost_opening_value_and_negative_reversal_reconcile_at_three_decimals(): void
    {
        $value = Decimal::money(Decimal::mul('2.5000', '4.9380'));
        self::assertSame('12.345', $value);
        self::assertSame('0.000', Decimal::money(Decimal::add($value, Decimal::sub('0', $value))));
    }
}
