<?php
namespace App\Services\Stock;
use App\Services\Stock\Support\Decimal;
/** Immutable per-layer amounts behind one native unique ledger/role component. */
final class RestoredFifoLayerSlices
{
 public static function rounded(array $slices,string $componentAmount):array
 {
  if(!$slices)throw new \LogicException('Actual restored FIFO layers required.');
  $seen=[];$sum='0';
  foreach($slices as&$slice){
   $id=(int)($slice['cost_layer_id']??0);if($id<1||isset($seen[$id])||!Decimal::gt((string)($slice['remaining_quantity']??'0'),'0',8))throw new \LogicException('Distinct actual restored FIFO layers required.');
   $seen[$id]=true;$slice['posted_base_amount']=Decimal::round((string)$slice['exact_base_amount'],2);$sum=Decimal::add($sum,$slice['posted_base_amount'],8);
  }unset($slice);
  $residual=Decimal::sub($componentAmount,$sum,8);
  if(Decimal::gt(ltrim($residual,'-'),Decimal::mul((string)count($slices),'0.005',8),8))throw new \LogicException('Restored FIFO layer rounding exceeds bound.');
  $last=array_key_last($slices);$slices[$last]['posted_base_amount']=Decimal::add($slices[$last]['posted_base_amount'],$residual,2);
  return$slices;
 }
}
