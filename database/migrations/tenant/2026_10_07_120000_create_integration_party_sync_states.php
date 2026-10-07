<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  if(Schema::hasTable('integration_party_sync_states')) return;
  Schema::create('integration_party_sync_states',function(Blueprint $t):void {
   $t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('central_client_id');$t->unsignedBigInteger('central_organization_id');
   $t->uuid('organization_mapping_uuid');$t->string('entity_type',16);$t->string('source_app',16);$t->unsignedBigInteger('source_id');
   $t->unsignedBigInteger('target_id')->nullable();$t->uuid('mapping_uuid')->nullable();
   $t->char('source_revision',64);$t->json('source_fields');$t->json('field_baselines')->nullable();$t->json('field_overrides')->nullable();
   $t->string('status',24)->default('pending');$t->unsignedBigInteger('state_version')->default(1);$t->unsignedInteger('attempts')->default(0);
   $t->timestamp('next_attempt_at')->nullable();$t->string('last_error',128)->nullable();$t->timestamps();
   $t->unique(['organization_mapping_uuid','entity_type','source_app','source_id'],'ips_identity_unique');
   $t->index(['organization_id','status','next_attempt_at'],'ips_due_index');
   $t->index(['organization_mapping_uuid','entity_type','source_app','target_id'],'ips_target_index');
  });
 }
 public function down():void { /* Immutable identity and retry audit survive code rollback. */ }
};

