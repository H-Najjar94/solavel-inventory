<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 protected $connection='tenant';
 public function up():void {if(!Schema::connection('tenant')->hasTable('sales_notification_outbox'))Schema::connection('tenant')->create('sales_notification_outbox',function(Blueprint $t){$t->id();$t->unsignedBigInteger('organization_id');$t->unsignedBigInteger('request_id');$t->char('transition_fingerprint',64);$t->string('state',20)->default('pending');$t->unsignedInteger('attempts')->default(0);$t->timestamp('retry_at')->nullable();$t->uuid('lease_token')->nullable();$t->timestamp('lease_until')->nullable();$t->uuid('notification_thread_id')->nullable();$t->string('last_error',80)->nullable();$t->timestamp('delivered_at')->nullable();$t->timestamps();$t->unique(['organization_id','request_id','transition_fingerprint'],'sales_notification_transition_unique');$t->index(['organization_id','state','retry_at'],'sales_notification_due');});}
 public function down():void {/* Durable notification delivery audit survives rollback. */}
};
