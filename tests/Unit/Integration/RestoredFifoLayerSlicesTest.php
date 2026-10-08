<?php
namespace Tests\Unit\Integration;
use App\Services\Stock\RestoredFifoLayerSlices;
use PHPUnit\Framework\TestCase;
final class RestoredFifoLayerSlicesTest extends TestCase
{
 public function test_each_native_layer_keeps_its_amount_and_rounding_reconciles():void
 {
  $rows=RestoredFifoLayerSlices::rounded([
   ['cost_layer_id'=>11,'remaining_quantity'=>'2','exact_base_amount'=>'0.334'],
   ['cost_layer_id'=>12,'remaining_quantity'=>'3','exact_base_amount'=>'0.334'],
  ],'0.67');
  self::assertSame([11,12],array_column($rows,'cost_layer_id'));
  self::assertSame('0.33',$rows[0]['posted_base_amount']);
  self::assertSame('0.34',$rows[1]['posted_base_amount']);
 }
 public function test_duplicate_native_layer_is_rejected():void
 {
  $this->expectException(\LogicException::class);
  RestoredFifoLayerSlices::rounded([
   ['cost_layer_id'=>11,'remaining_quantity'=>'2','exact_base_amount'=>'1'],
   ['cost_layer_id'=>11,'remaining_quantity'=>'3','exact_base_amount'=>'2'],
  ],'3');
 }
 public function test_unbounded_rounding_cannot_change_valuation():void
 {
  $this->expectException(\LogicException::class);
  RestoredFifoLayerSlices::rounded([['cost_layer_id'=>11,'remaining_quantity'=>'2','exact_base_amount'=>'1']],'2');
 }
}
