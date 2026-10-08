<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10: narrow the locking reads of the open-order claim (OpenOrderClaims).
 * Without these, the FOR UPDATE scans by sales_order_id and by customer (pending claim holder)
 * walked and next-key-locked every request row of the organization.
 * - sfr_org_order_index:          (organization_id, sales_order_id)              — assertClaimable, shipment hand-off lookup
 * - sfr_org_customer_order_index: (organization_id, customer_id, sales_order_id) — holder(): customer + sales_order_id IS NULL
 * Additive and idempotent; no data is touched.
 */
return new class extends Migration {
 public function getConnection():?string{return config('tenancy.tenant_connection','tenant');}
 public function up():void{
  $s=Schema::connection($this->getConnection());
  if(!$s->hasTable('sales_fulfillment_requests'))return;
  if(!$this->indexExists('sales_fulfillment_requests','sfr_org_order_index'))$s->table('sales_fulfillment_requests',fn(Blueprint$t)=>$t->index(['organization_id','sales_order_id'],'sfr_org_order_index'));
  if(!$this->indexExists('sales_fulfillment_requests','sfr_org_customer_order_index'))$s->table('sales_fulfillment_requests',fn(Blueprint$t)=>$t->index(['organization_id','customer_id','sales_order_id'],'sfr_org_customer_order_index'));
 }
 public function down():void{} // Indexes are harmless to older code; retained for safe rollback.
 private function indexExists(string $table,string $index):bool{
  $connection=DB::connection($this->getConnection());
  return $connection->table('information_schema.statistics')->where('table_schema',$connection->getDatabaseName())->where('table_name',$table)->where('index_name',$index)->exists();
 }
};
