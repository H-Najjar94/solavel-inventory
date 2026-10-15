<?php
namespace Tests\Unit\FinancialOrigins;
use App\Services\Documents\LandedCostService;
use PHPUnit\Framework\TestCase;
final class LandedCostMoneyPrecisionThreeTest extends TestCase
{
 public function test_largest_remainder_conserves_one_milliunit_and_stable_order():void {
  self::assertSame(['a'=>'0.001','b'=>'0.000'],LandedCostService::allocate('0.001',['a'=>'1','b'=>'1']));
  self::assertSame(['a'=>'33.334','b'=>'33.333','c'=>'33.333'],LandedCostService::allocate('100',['a'=>'1','b'=>'1','c'=>'1']));
  self::assertSame([1=>'0.022',2=>'0.021',3=>'0.007'],LandedCostService::allocate('.05',[1=>'3',2=>'3',3=>'1']));
 }
 public function test_native_rate_division_and_half_milliunit_component_bound_are_exact():void {
  $service=(new \ReflectionClass(LandedCostService::class))->newInstanceWithoutConstructor();self::assertSame('141.044',$service->toBase('100','.709'));
  $rounded=(new \ReflectionMethod($service,'rounded'))->invoke($service,[['destination_role'=>'inventory_asset','exact_base_amount'=>'0.0005','provenance'=>[]],['destination_role'=>'cogs','exact_base_amount'=>'0.0005','provenance'=>[]]],'0.001');self::assertCount(1,$rounded);self::assertSame('cogs',$rounded[0]['destination_role']);self::assertSame('0.001',$rounded[0]['posted_base_amount']);
 }
}
