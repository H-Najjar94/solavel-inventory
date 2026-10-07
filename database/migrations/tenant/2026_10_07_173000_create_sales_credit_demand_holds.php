<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function getConnection():?string{return config('tenancy.tenant_connection','tenant');}
 public function up():void {
  foreach(['sales_fulfillment_request_lines','sales_order_lines']as$table)if(!Schema::hasColumn($table,'cancelled_qty'))Schema::table($table,fn(Blueprint$t)=>$t->decimal('cancelled_qty',18,4)->default(0));
  if(!Schema::hasTable('sales_fulfillment_demand_commands'))Schema::create('sales_fulfillment_demand_commands',function(Blueprint$t){$t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('operation_uuid');$t->unsignedBigInteger('fulfillment_request_id');$t->unsignedBigInteger('source_invoice_id');$t->unsignedBigInteger('credit_note_id');$t->unsignedBigInteger('actor_id');$t->char('credit_revision',64);$t->char('payload_hash',64);$t->char('hold_fingerprint',64);$t->json('payload');$t->string('state');$t->unsignedBigInteger('credit_journal_id')->nullable();$t->timestamps();$t->unique(['organization_id','operation_uuid'],'sfdc_org_operation_unique');$t->index(['organization_id','credit_note_id'],'sfdc_org_credit_index');$t->index(['organization_id','fulfillment_request_id','state'],'sfdc_request_state_index');});
 }
 public function down():void{} // Credit/source demand history survives rollback.
};
