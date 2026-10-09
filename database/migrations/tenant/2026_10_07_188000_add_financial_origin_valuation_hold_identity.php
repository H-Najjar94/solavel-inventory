<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        // The canonical registry runs the existing 081000 prerequisite first.
        if (!$schema->hasTable('purchase_valuation_holds')) {
            throw new RuntimeException('Purchase valuation hold prerequisite is missing.');
        }
        $schema->table('purchase_valuation_holds', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_bill_id')->nullable()->change();
        });
        foreach (['source_document_type', 'source_document_id', 'source_journal_id'] as $column) {
            if (!$schema->hasColumn('purchase_valuation_holds', $column)) {
                $schema->table('purchase_valuation_holds', function (Blueprint $table) use ($column): void {
                    if ($column === 'source_document_type') $table->string($column, 32)->nullable();
                    else $table->unsignedBigInteger($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Hold provenance and pending financial recovery survive application rollback.
    }
};
