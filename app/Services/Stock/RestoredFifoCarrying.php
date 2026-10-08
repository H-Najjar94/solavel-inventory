<?php
namespace App\Services\Stock;
use App\Services\Stock\Support\Decimal;
/** Native OUT inverse restores captured historical carrying, keeping remaining layer value intact. */
final class RestoredFifoCarrying
{
 public static function unitCost(string $remaining,string $currentUnitCost,string $restored,string $historicalUnitCost):string
 {
  foreach([$remaining,$currentUnitCost,$historicalUnitCost]as$value)if(Decimal::lt($value,'0',8))throw new \LogicException('Nonnegative native FIFO carrying required.');
  if(!Decimal::gt($restored,'0',8))throw new \LogicException('Positive native FIFO restoration required.');
  $value=Decimal::add(Decimal::mul($remaining,$currentUnitCost,12),Decimal::mul($restored,$historicalUnitCost,12),12);
  $quantity=Decimal::add($remaining,$restored,8);$cost=Decimal::cost(Decimal::div($value,$quantity,12));
  if(Decimal::cmp(Decimal::money(Decimal::mul($quantity,$cost,12)),Decimal::money($value),2)!==0)throw new \LogicException('Historical FIFO carrying cannot be restored within canonical cost precision.');
  return$cost;
 }
}
