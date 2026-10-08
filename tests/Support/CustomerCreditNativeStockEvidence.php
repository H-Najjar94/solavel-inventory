<?php
namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use App\Models\Tenant\{FulfillmentDemandCommand,FulfillmentRequest,FulfillmentRequestLine,SalesOrder,SalesOrderLine,Reservation};

/** Actual native read-only demand/physical evidence; never admissible outside the contained QA kernel. */
final class CustomerCreditNativeStockEvidence
{
 public static function snapshot(int $org): array
 {
  $db=DB::connection('tenant');
  if(PHP_SAPI!=='cli'||!str_starts_with(base_path(),'/qualification/stock')||!app()->environment('testing')||$org<1
   ||$db->getDatabaseName()!=='tenant_000100'||$db->selectOne('SELECT CURRENT_USER() AS actual_user')->actual_user!=='t_000100@localhost'
   ||$db->transactionLevel()!==0||(int)app(\App\Tenancy\OrganizationContext::class)->idOrFail()!==$org)
   throw new \LogicException('Contained native customer-credit evidence only');
  $tables=['commands'=>(new FulfillmentDemandCommand)->getTable(),'requests'=>(new FulfillmentRequest)->getTable(),
   'request_lines'=>(new FulfillmentRequestLine)->getTable(),'orders'=>(new SalesOrder)->getTable(),
   'order_lines'=>(new SalesOrderLine)->getTable(),'reservations'=>(new Reservation)->getTable(),
   'stock_ledger'=>'stock_ledger','stock_balances'=>'stock_balances'];
  $db->statement('SET TRANSACTION READ ONLY');$db->beginTransaction();
  try {
   $raw=[];$columns=[];$schema=$db->getSchemaBuilder();
   foreach($tables as $key=>$table){
    if(!$schema->hasTable($table))throw new \LogicException('Native table missing: '.$table);
    $cols=$schema->getColumnListing($table);
    if(!in_array('organization_id',$cols,true)||!in_array('id',$cols,true))throw new \LogicException('Native scope/key missing: '.$table);
    $columns[$key]=$cols;$raw[$key]=$db->table($table)->where('organization_id',$org)->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all();
   }
   $ledger=['id','organization_id','item_id','variant_id','warehouse_id','zone_id','bin_id','lot_id','serial_id','direction','quantity',
    'source_type','source_id','source_line_id','moved_at','posted_at','idempotency_key','balance_qty_after','created_by','created_at'];
   $balances=['id','organization_id','item_id','variant_id','warehouse_id','lot_id','bin_id','on_hand_qty','reserved_qty','lot_key','bin_key','variant_key'];
   foreach(['stock_ledger'=>['direction','quantity','source_type','source_id','idempotency_key'],'stock_balances'=>['on_hand_qty','reserved_qty']] as $key=>$required)
    foreach($required as$field)if(!in_array($field,$columns[$key],true))throw new \LogicException('Actual physical field missing: '.$key.'.'.$field);
   $ledger=array_values(array_intersect($ledger,$columns['stock_ledger']));$balances=array_values(array_intersect($balances,$columns['stock_balances']));
   $project=static fn(array$rows,array$keys)=>array_map(static fn(array$row)=>array_replace(array_fill_keys($keys,null),array_intersect_key($row,array_flip($keys))),$rows);
   // Reservations may legitimately change on demand credit; report them independently of on-hand physical movement.
   $physical=['organization_id'=>$org,'ledger_columns'=>$ledger,'ledger_rows'=>$project($raw['stock_ledger'],$ledger),
    'balance_columns'=>array_values(array_diff($balances,['reserved_qty'])),'balance_rows'=>$project($raw['stock_balances'],array_values(array_diff($balances,['reserved_qty'])))];
   $quantities=['on_hand_qty'=>'0.00000000','reserved_qty'=>'0.00000000'];
   foreach($raw['stock_balances'] as$row)foreach(array_keys($quantities) as$key)$quantities[$key]=bcadd($quantities[$key],(string)$row[$key],8);
   return ['organization_id'=>$org,'native_model_tables'=>$tables,'schema_columns'=>$columns,'raw_rows'=>$raw,
    'physical_hash'=>hash('sha256',json_encode($physical,JSON_THROW_ON_ERROR)),'physical_projection'=>$physical,
    'quantity_totals'=>$quantities,'row_counts'=>array_map('count',$raw),'evidence_kind'=>'actual_native_rows_requires_financial_and_demand_reconciliation'];
  } finally {$db->rollBack();}
 }
}
