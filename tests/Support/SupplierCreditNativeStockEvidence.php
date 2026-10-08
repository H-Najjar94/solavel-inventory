<?php
namespace Tests\Support;
use Illuminate\Support\Facades\{DB,Schema};
/** Read actual economic rows after native signed operations; never manufacture posted documents or journal evidence. */
final class SupplierCreditNativeStockEvidence
{
 public static function snapshot(int $organizationId):array
 {
  if(!app()->environment('testing')||$organizationId<1)throw new \RuntimeException('Exact isolated native test organization required');
  $db=DB::connection('tenant');$rows=[];
  foreach(['goods_receipts','goods_receipt_lines','stock_ledger','stock_balances','cost_layers','cost_layer_consumptions',
   'integration_purchase_cost_adjustments','integration_purchase_cost_adjustment_components','purchase_valuation_holds',
   'supplier_credit_value_effects']as$table){
   if(!Schema::connection('tenant')->hasTable($table))throw new \RuntimeException('Required native schema missing: '.$table);
   $rows[$table]=$db->table($table)->where('organization_id',$organizationId)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
  }
  return ['organization_id'=>$organizationId,'rows'=>$rows,'physical_hash'=>hash('sha256',json_encode([
   $rows['goods_receipts'],$rows['goods_receipt_lines'],$rows['stock_ledger'],array_map(fn($row)=>array_intersect_key($row,
    array_flip(['id','organization_id','item_id','warehouse_id','variant_id','lot_id','bin_id','on_hand_qty','reserved_qty'])),$rows['stock_balances'])],JSON_THROW_ON_ERROR))];
 }
 public static function assertPhysicalUnchanged(array $before,array $after):void
 {
  if($before['organization_id']!==$after['organization_id']||!hash_equals($before['physical_hash'],$after['physical_hash']))
   throw new \RuntimeException('Value-only supplier credit changed native physical documents or quantities');
 }
 public static function assertNativeEffect(array $snapshot,string $allocationUuid,string $direction):array
 {
  $effects=array_values(array_filter($snapshot['rows']['supplier_credit_value_effects'],fn($row)=>$row['allocation_uuid']===$allocationUuid&&$row['direction']===$direction));
  if(count($effects)!==1)throw new \RuntimeException('Missing or duplicate native value effect');
  $effect=$effects[0];
  if(!hash_equals($effect['snapshot_hash'],hash('sha256',$effect['snapshot'])))throw new \RuntimeException('Native effect audit hash differs');
  $adjustments=array_values(array_filter($snapshot['rows']['integration_purchase_cost_adjustments'],fn($row)=>$row['adjustment_uuid']===$effect['adjustment_uuid']));
  if(count($adjustments)!==1||$adjustments[0]['state']!==($direction==='reverse'?'reversed':'applied'))throw new \RuntimeException('Native adjustment not completed');
  $components=array_values(array_filter($snapshot['rows']['integration_purchase_cost_adjustment_components'],fn($row)=>$row['adjustment_uuid']===$effect['adjustment_uuid']));
  if(!$components)throw new \RuntimeException('Native effect has no persisted components');
  return ['effect'=>$effect,'adjustment'=>$adjustments[0],'components'=>$components,'valuation'=>json_decode($effect['snapshot'],true,512,JSON_THROW_ON_ERROR)];
 }
}
