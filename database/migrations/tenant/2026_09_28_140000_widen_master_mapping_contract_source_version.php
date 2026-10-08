<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('integration_master_data_mappings')) {
            return;
        }

        Schema::connection('tenant')->table('integration_master_data_mappings', function (Blueprint $table): void {
            // Canonical category identifiers contain the complete hierarchy.
            // Preserve that identity exactly; never truncate or hash it.
            $table->string('contract_source_version', 512)->default('phase2.v1')->change();
        });
    }

    public function down(): void
    {
        // Deliberately irreversible: narrowing could truncate canonical identities
        // written after this migration and make distinct paths collide.
    }
};
