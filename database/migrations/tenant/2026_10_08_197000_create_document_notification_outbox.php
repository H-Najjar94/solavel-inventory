<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 protected $connection='tenant';
 public function up():void {
  if(!Schema::connection('tenant')->hasTable('integration_document_notification_outbox'))Schema::connection('tenant')->create('integration_document_notification_outbox',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->string('document_kind',16);$t->unsignedBigInteger('document_outbox_id');$t->char('transition_fingerprint',64);$t->longText('facts');$t->string('state',20)->default('pending');$t->unsignedInteger('attempts')->default(0);$t->timestamp('retry_at')->nullable();$t->uuid('lease_token')->nullable();$t->timestamp('lease_until')->nullable();$t->uuid('notification_thread_id')->nullable();$t->string('last_error',80)->nullable();$t->timestamp('delivered_at')->nullable();$t->timestamps();
   $t->unique(['organization_id','document_kind','document_outbox_id','transition_fingerprint'],'document_notification_transition_unique');$t->index(['organization_id','state','retry_at'],'document_notification_due');
  });
 }
 public function down():void {/* Durable incident and delivery evidence survives rollback. */}
};
