<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  $s=Schema::connection('tenant');
  if(!$s->hasTable('sales_fulfillment_request_lines'))return;
  foreach(['source_shipped_qty_base','source_cancelled_qty_base']as$column)if(!$s->hasColumn('sales_fulfillment_request_lines',$column))$s->table('sales_fulfillment_request_lines',fn(Blueprint $t)=>$t->decimal($column,24,4)->nullable());
 }
 public function down():void {} // Retain historical source baselines for safe rollback.
};
