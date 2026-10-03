<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable('integration_connection_wizard_runs')) {
            throw new RuntimeException('connection_wizard_base_schema_required');
        }
        // Some older imported baselines recorded the original migration without
        // its draft columns. A new ledger entry repairs that schema using the
        // canonical additive implementation; approvals/history are never reset.
        $migration = require __DIR__.'/2026_08_03_093000_enable_pre_mapping_connection_wizard_drafts.php';
        $migration->up();
        foreach (['tenant_database_identity', 'draft_version', 'lock_version', 'discarded_at'] as $column) {
            if (! $schema->hasColumn('integration_connection_wizard_runs', $column)) {
                throw new RuntimeException('connection_wizard_draft_schema_incomplete');
            }
        }
    }

    public function down(): void
    {
        // Preserve permanent setup and approval evidence on downgrade/rollback.
    }
};
