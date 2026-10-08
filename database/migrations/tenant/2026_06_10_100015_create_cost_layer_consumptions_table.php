<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records exactly which FIFO cost layers (and how much of each) every outbound
 * ledger movement consumed. This lets a reversal RESTORE the precise layers an
 * OUT drew down — preserving FIFO order and valuation — instead of recreating a
 * single blended-cost layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('cost_layer_consumptions') or Schema::create('cost_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('ledger_id');      // the OUT stock_ledger row
            $table->unsignedBigInteger('cost_layer_id');  // the layer it drew from
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();

            $table->index(['organization_id', 'ledger_id'], 'clc_org_ledger_idx');
            $table->index(['organization_id', 'cost_layer_id'], 'clc_org_layer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_layer_consumptions');
    }
};
