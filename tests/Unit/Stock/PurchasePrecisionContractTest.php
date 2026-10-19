<?php

namespace Tests\Unit\Stock;

require_once dirname(__DIR__, 3).'/app/Services/Stock/Support/Decimal.php';
require_once dirname(__DIR__, 3).'/app/Services/Catalog/UnitConversionResolver.php';

use App\Services\Catalog\UnitConversionResolver;
use App\Services\Stock\Support\Decimal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PurchasePrecisionContractTest extends TestCase
{
    #[Test] public function quantities_and_costs_preserve_six_places(): void
    {
        self::assertSame(6,Decimal::QTY_SCALE); self::assertSame(6,Decimal::COST_SCALE);
        self::assertSame('1.234567',Decimal::qty('1.2345674'));
        self::assertSame('1.234568',Decimal::cost('1.2345675'));
        self::assertSame('0.000001', Decimal::value(Decimal::mul('0.000001', '1.000000')));
    }

    #[Test] public function new_snapshots_use_v2_while_v1_remains_recognized(): void
    {
        self::assertSame('solastock-unit-conversion.v2',UnitConversionResolver::CONTRACT_VERSION);
        self::assertSame(4,UnitConversionResolver::SUPPORTED_CONTRACT_PRECISIONS['solastock-unit-conversion.v1']);
        self::assertSame(6,UnitConversionResolver::SUPPORTED_CONTRACT_PRECISIONS['solastock-unit-conversion.v2']);
    }
}
