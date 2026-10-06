<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('purchase_valuation_holds')) {
            Schema::connection('tenant')->create('purchase_valuation_holds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->uuid('settlement_uuid');
                $table->unsignedInteger('plan_revision')->default(1);
                $table->string('purpose', 16);
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('receipt_id');
                $table->unsignedBigInteger('source_bill_id');
                $table->char('plan_fingerprint', 64);
                $table->string('state', 16)->default('active');
                $table->timestamps();
                $table->unique(['organization_id', 'settlement_uuid', 'purpose', 'plan_revision'], 'pvh_operation_unique');
                $table->index(['organization_id', 'item_id', 'warehouse_id', 'state'], 'pvh_pool_state');
            });
        }
    }

    public function down(): void
    {
        // Financial matching/hold audit survives application rollback.
    }
};
