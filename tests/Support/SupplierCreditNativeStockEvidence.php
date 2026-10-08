<?php
namespace Tests\Support;

use Illuminate\Support\Facades\{DB,Schema};

/** Read-only actual scoped evidence, restricted to the root's original contained native kernel. */
final class SupplierCreditNativeStockEvidence
{
 public static function snapshot(int $org):array {
  $db=DB::connection('tenant');
  if(PHP_SAPI!=='cli' || !str_starts_with(base_path(),'/qualification/stock') || !app()->environment('testing') || $org<1
   || $db->getDatabaseName()!=='tenant_000100' || $db->selectOne('SELECT CURRENT_USER() AS actual_user')->actual_user!=='t_000100@localhost'
   || $db->transactionLevel()!==0 || (int)app(\App\Tenancy\OrganizationContext::class)->idOrFail()!==$org)
   throw new \LogicException('Original contained native Stock evidence only');
  $schema=Schema::connection('tenant');$raw=[];$columns=[];
  $required=['stock_ledger','stock_balances','cost_layers','reservations','integration_purchase_cost_adjustments',
   'integration_purchase_cost_adjustment_components','purchase_valuation_holds','supplier_credit_value_effects'];
  foreach($required as $table){
   if(!$schema->hasTable($table))throw new \LogicException('Required native evidence table missing: '.$table);
   $cols=$schema->getColumnListing($table);
   if(!in_array('organization_id',$cols,true)||!in_array('id',$cols,true))throw new \LogicException('Native evidence scope/key missing: '.$table);
   $columns[$table]=$cols;
   $raw[$table]=$db->table($table)->where('organization_id',$org)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
  }
  $physicalLedger=['id','organization_id','item_id','variant_id','warehouse_id','zone_id','bin_id','lot_id','serial_id',
   'direction','quantity','source_type','source_id','source_line_id','moved_at','posted_at','idempotency_key','balance_qty_after','created_by','created_at'];
  foreach(['id','organization_id','item_id','warehouse_id','direction','quantity','source_type','source_id','idempotency_key'] as $field)
   if(!in_array($field,$columns['stock_ledger'],true))throw new \LogicException('Actual native ledger physical column missing: '.$field);
  $physicalLedger=array_values(array_intersect($physicalLedger,$columns['stock_ledger']));
  $physicalBalances=['id','organization_id','item_id','variant_id','warehouse_id','lot_id','bin_id','on_hand_qty','reserved_qty','lot_key','bin_key','variant_key'];
  foreach(['id','organization_id','item_id','warehouse_id','on_hand_qty','reserved_qty'] as $field)
   if(!in_array($field,$columns['stock_balances'],true))throw new \LogicException('Actual native balance quantity column missing: '.$field);
  $physicalBalances=array_values(array_intersect($physicalBalances,$columns['stock_balances']));
  $project=static fn(array $rows,array $keys)=>array_map(static fn(array $row)=>array_replace(array_fill_keys($keys,null),array_intersect_key($row,array_flip($keys))),$rows);
  $physical=['organization_id'=>$org,'stock_ledger_columns'=>$physicalLedger,'stock_ledger_rows'=>$project($raw['stock_ledger'],$physicalLedger),
   'stock_balance_columns'=>$physicalBalances,'stock_balance_quantity_rows'=>$project($raw['stock_balances'],$physicalBalances)];
  $totals=['on_hand_qty'=>'0.00000000','reserved_qty'=>'0.00000000','total_value'=>'0.00000000'];
  foreach($raw['stock_balances'] as $row)foreach(array_keys($totals) as $field){
   if(!array_key_exists($field,$row))throw new \LogicException('Actual native quantity/value total column missing: '.$field);
   $totals[$field]=bcadd($totals[$field],(string)$row[$field],8);
  }
  return ['organization_id'=>$org,'physical_hash'=>hash('sha256',json_encode($physical,JSON_THROW_ON_ERROR)),
   'physical_projection'=>$physical,'schema_columns'=>$columns,'raw_rows'=>$raw,
   'row_counts'=>array_map('count',$raw),'balance_totals'=>$totals,
   'evidence_kind'=>'actual_native_scoped_rows_not_economic_pass'];
 }
}
