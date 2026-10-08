<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  if(!Schema::hasTable('purchasing_receiving_cancellations'))Schema::create('purchasing_receiving_cancellations',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('request_uuid');
   $t->unsignedBigInteger('finance_organization_id');$t->unsignedBigInteger('source_bill_id');$t->char('source_revision',64);$t->char('expected_revision',64)->nullable();
   $t->unsignedBigInteger('actor_id');$t->string('permission',20);$t->json('authority');$t->timestamps();
   $t->unique(['organization_mapping_uuid','request_uuid'],'prcancel_mapping_request_unique');
   $t->index(['organization_id','source_bill_id'],'prcancel_org_bill_index');
  });
 }
 public function down():void {} // Immutable cancellation evidence survives code rollback.
};
