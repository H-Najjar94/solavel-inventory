<?php
namespace Tests\Unit\Integration;
use App\Services\Stock\RestoredFifoCarrying;
use PHPUnit\Framework\TestCase;
final class RestoredFifoCarryingTest extends TestCase
{
 public function test_historical_return_does_not_reprice_remaining_units():void
 {self::assertSame('4.9000',RestoredFifoCarrying::unitCost('9','5','1','4'));}
 public function test_unchanged_native_cost_remains_unchanged():void
 {self::assertSame('4.0000',RestoredFifoCarrying::unitCost('9','4','1','4'));}
 public function test_fully_consumed_layer_restores_original_carrying():void
 {self::assertSame('4.0000',RestoredFifoCarrying::unitCost('0','5','1','4'));}
 public function test_negative_native_quantity_is_rejected():void
 {$this->expectException(\LogicException::class);RestoredFifoCarrying::unitCost('-1','5','1','4');}
}
