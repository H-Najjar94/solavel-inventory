<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 protected $connection='tenant';
 public function up():void {
  if(Schema::connection('tenant')->hasTable('supplier_credit_value_effects'))return;
  Schema::connection('tenant')->create('supplier_credit_value_effects',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('organization_id');$t->uuid('organization_mapping_uuid');$t->uuid('allocation_uuid');$t->uuid('operation_uuid');
   $t->uuid('adjustment_uuid');$t->string('direction',8);$t->char('plan_fingerprint',64);$t->longText('snapshot');$t->char('snapshot_hash',64);$t->timestamps();
   $t->unique(['organization_id','adjustment_uuid','direction'],'supplier_credit_value_effect_unique');
  });
 }
 public function down():void { /* Native valuation/audit survives release rollback. */ }
};
