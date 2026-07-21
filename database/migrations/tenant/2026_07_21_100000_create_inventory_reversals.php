<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('inventory_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('reversal_number', 80);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 80)->nullable();
            $table->date('reversal_date');
            $table->string('status', 30)->default('posted');
            $table->string('reason', 500);
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->dateTime('posted_at');
            $table->string('posted_guard_key')->unique();
            $table->uuid('original_event_uuid')->nullable();
            $table->uuid('reversal_event_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reversal_number'], 'ir_org_number_unique');
            $table->unique(['organization_id', 'source_type', 'source_id'], 'ir_org_source_unique');
            $table->index(['organization_id', 'source_type', 'source_id'], 'ir_org_source_idx');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_id')->nullable()->after('posted_guard_key');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversal_id');
            $table->dateTime('reversed_at')->nullable()->after('reversed_by');
            $table->index(['organization_id', 'reversal_id'], 'grn_org_reversal_idx');
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_id')->nullable()->after('posted_guard_key');
            $table->index(['organization_id', 'reversal_id'], 'adj_org_reversal_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_sales_return_id')->nullable()->after('posted_guard_key');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversal_sales_return_id');
            $table->dateTime('reversed_at')->nullable()->after('reversed_by');
            $table->index(['organization_id', 'reversal_sales_return_id'], 'ship_org_reversal_idx');
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->boolean('is_source_reversal')->default(false)->after('shipment_id');
            $table->unsignedBigInteger('source_reversal_shipment_id')->nullable()->after('is_source_reversal');
            $table->uuid('original_event_uuid')->nullable()->after('posted_guard_key');
            $table->uuid('reversal_event_uuid')->nullable()->after('original_event_uuid');
            $table->unique(['organization_id', 'source_reversal_shipment_id'], 'sr_org_shipment_reversal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sr_org_shipment_reversal_unique');
            $table->dropColumn(['is_source_reversal', 'source_reversal_shipment_id', 'original_event_uuid', 'reversal_event_uuid']);
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('ship_org_reversal_idx');
            $table->dropColumn(['reversal_sales_return_id', 'reversed_by', 'reversed_at']);
        });
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropIndex('adj_org_reversal_idx');
            $table->dropColumn('reversal_id');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropIndex('grn_org_reversal_idx');
            $table->dropColumn(['reversal_id', 'reversed_by', 'reversed_at']);
        });
        Schema::dropIfExists('inventory_reversals');
    }
};
