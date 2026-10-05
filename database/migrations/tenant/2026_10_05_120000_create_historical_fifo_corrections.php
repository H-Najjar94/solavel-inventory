<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('historical_fifo_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->uuid('correction_uuid');
            $table->uuid('batch_id')->index();
            $table->char('plan_sha256', 64);
            $table->char('ledger_sha256', 64);
            $table->unsignedBigInteger('reviewed_by_central_id');
            $table->json('plan');
            $table->json('result')->nullable();
            $table->string('status', 20)->default('reviewed');
            $table->timestamps();
            $table->unique(['organization_id', 'correction_uuid'], 'historical_fifo_plan_org_uuid');
        });
        Schema::create('historical_fifo_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->uuid('correction_uuid');
            $table->string('source_id', 191);
            $table->json('causal_payload');
            $table->json('conversion_snapshot');
            $table->json('ledger_ids');
            $table->string('status', 20)->default('applied');
            $table->timestamps();
            $table->unique(['organization_id', 'correction_uuid'], 'historical_fifo_correction_org_uuid');
        });
        Schema::table('cost_layers', function (Blueprint $table) {
            $table->unsignedBigInteger('superseded_fifo_correction_id')->nullable()->index();
        });
        Schema::table('cost_layer_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('superseded_fifo_correction_id')->nullable()->index();
        });
    }
    public function down(): void
    {
        // Evidence and applied correction records must survive an application rollback.
        throw new RuntimeException('Historical FIFO evidence migration is forward-only.');
    }
};
