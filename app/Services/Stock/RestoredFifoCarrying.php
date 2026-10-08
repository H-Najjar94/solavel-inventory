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
  return Decimal::cost(Decimal::div($value,Decimal::add($remaining,$restored,8),12));
 }
}
