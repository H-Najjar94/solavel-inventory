<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive derived projections; original ledger/unit-price/history values are never rewritten. */
return new class extends Migration {
    public function getConnection(): ?string { return 'tenant'; }
    public function up(): void
    {
        $s=Schema::connection('tenant');
        foreach(['cost_layers','cost_layer_consumptions']as$table)if(!$s->hasTable($table))throw new RuntimeException('Missing native FIFO table: '.$table);
        if(!$s->hasColumn('cost_layers','remaining_base_value'))$s->table('cost_layers',fn(Blueprint $t)=>$t->decimal('remaining_base_value',24,6)->nullable());
        if(!$s->hasColumn('cost_layers','posted_basis_provenance'))$s->table('cost_layers',fn(Blueprint $t)=>$t->json('posted_basis_provenance')->nullable());
        if(!$s->hasColumn('cost_layer_consumptions','posted_base_amount'))$s->table('cost_layer_consumptions',fn(Blueprint $t)=>$t->decimal('posted_base_amount',24,6)->nullable());
    }
    // Once used, these columns are financial reversal evidence. Retain them for
    // forward recovery; reverting application code cannot safely discard them.
    public function down(): void {}
};
