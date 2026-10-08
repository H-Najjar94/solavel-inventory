<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  $s=Schema::connection('tenant');
  if(!$s->hasTable('stock_cash_refund_demands'))$s->create('stock_cash_refund_demands',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->unsignedBigInteger('request_id');$t->uuid('request_uuid');
   $t->unsignedBigInteger('source_document_id');$t->unsignedBigInteger('source_journal_id');$t->char('source_revision',64);
   $t->unsignedBigInteger('refund_receipt_id');$t->uuid('operation_uuid');$t->unsignedBigInteger('actor_id');$t->char('payload_hash',64);$t->char('hold_fingerprint',64);
   $t->json('payload');$t->string('state',32);$t->unsignedBigInteger('refund_journal_id')->nullable();$t->unsignedBigInteger('reverse_actor_id')->nullable();$t->timestamps();
   $t->unique(['organization_id','operation_uuid'],'scrd_org_operation_unique');$t->unique(['organization_id','refund_receipt_id'],'scrd_org_refund_unique');$t->index(['organization_id','request_id','state'],'scrd_request_state_index');
  });
  if(!$s->hasTable('stock_cash_partial_returns'))$s->create('stock_cash_partial_returns',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('operation_uuid');$t->unsignedBigInteger('actor_id');$t->unsignedBigInteger('request_id');$t->unsignedBigInteger('shipment_id');$t->unsignedBigInteger('sales_return_id');$t->char('payload_hash',64);$t->json('payload');$t->string('state',32);$t->timestamps();
   $t->unique(['organization_id','operation_uuid'],'scpr_operation_unique');$t->unique(['organization_id','sales_return_id'],'scpr_return_unique');
  });

 }
 public function down():void { /* Preserve native hold and refund audit on rollback. */ }
};
