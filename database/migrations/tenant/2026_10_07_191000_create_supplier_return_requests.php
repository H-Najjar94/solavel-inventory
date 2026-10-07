<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  if(Schema::hasTable('supplier_return_requests'))return;
  Schema::create('supplier_return_requests',function(Blueprint$t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('operation_uuid');$t->unsignedBigInteger('finance_organization_id');
   $t->unsignedBigInteger('source_bill_id');$t->unsignedBigInteger('bill_journal_id');$t->unsignedBigInteger('finance_receipt_id');$t->unsignedBigInteger('goods_receipt_id');$t->uuid('receipt_mapping_uuid');
   $t->unsignedBigInteger('request_actor_id');$t->unsignedBigInteger('physical_actor_id')->nullable();$t->unsignedBigInteger('supplier_return_id')->nullable();$t->string('state',32)->default('requested');$t->char('payload_hash',64);$t->longText('payload');$t->timestamps();
   $t->unique(['organization_id','operation_uuid'],'supplier_return_request_identity');$t->index(['organization_id','state'],'supplier_return_request_status');$t->index(['organization_mapping_uuid','goods_receipt_id'],'supplier_return_request_source');
  });
 }
 public function down():void {} // Retain request and physical source audit.
};
