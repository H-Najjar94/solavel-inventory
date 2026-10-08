<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 protected $connection='tenant';
 public function up():void {
  if(Schema::connection('tenant')->hasTable('integration_catalog_sync_states'))return;
  Schema::connection('tenant')->create('integration_catalog_sync_states',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->string('entity_type',24);$t->unsignedBigInteger('source_id');$t->uuid('source_uuid');
   $t->unsignedBigInteger('actor_id')->nullable();$t->char('source_revision',64);$t->longText('source_snapshot');$t->unsignedInteger('state_version')->default(1);
   $t->unsignedBigInteger('target_id')->nullable();$t->longText('target_baseline')->nullable();$t->longText('field_overrides')->nullable();$t->longText('delivery_ack')->nullable();
   $t->string('state',32)->default('pending');$t->unsignedInteger('attempts')->default(0);$t->string('last_error',191)->nullable();$t->uuid('lease_uuid')->nullable();$t->timestamp('lease_expires_at')->nullable();$t->timestamp('next_attempt_at')->nullable();$t->timestamps();
   $t->unique(['organization_mapping_uuid','entity_type','source_id'],'catalog_sync_source_unique');$t->unique('source_uuid','catalog_sync_uuid_unique');$t->index(['organization_id','state','next_attempt_at'],'catalog_sync_due');
  });
 }
 public function down():void { /* Durable identity/outcome evidence survives rollback. */ }
};
