<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string { return config('tenancy.tenant_connection','tenant'); }
    public function up(): void
    {
        // NULL preserves each pre-existing record's behavior; never backfill commercial flags.
        foreach (['items', 'item_categories'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                if (! Schema::hasColumn($name, 'internal_consumption_account_id')) $t->unsignedBigInteger('internal_consumption_account_id')->nullable();
                if ($name === 'items') foreach (['available_for_sale', 'available_for_purchase', 'track_inventory'] as $flag) {
                    if (! Schema::hasColumn($name, $flag)) $t->boolean($flag)->nullable();
                }
            });
        }
        if (! Schema::hasColumn('stock_ledger', 'original_ledger_id')) Schema::table('stock_ledger', function (Blueprint $t) {
            $t->unsignedBigInteger('original_ledger_id')->nullable()->index();
        });
        if (! Schema::hasTable('internal_consumptions')) Schema::create('internal_consumptions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id')->index();
            $t->string('document_number', 50); $t->string('kind', 12)->default('issue');
            $t->unsignedBigInteger('original_issue_id')->nullable()->index();
            $t->date('document_date'); $t->unsignedBigInteger('warehouse_id')->index();
            $t->string('status', 24)->default('draft'); $t->string('reason', 500); $t->text('notes')->nullable();
            $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('project_id')->nullable();
            $t->string('recipient', 255)->nullable(); $t->uuid('submission_key'); $t->char('submission_hash', 64);
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable(); $t->unsignedBigInteger('posted_by')->nullable();
            $t->timestamp('posted_at')->nullable(); $t->boolean('accounting_connected')->default(false);
            $t->uuid('original_event_uuid')->nullable(); $t->timestamps();
            $t->unique(['organization_id', 'document_number']); $t->unique(['organization_id', 'submission_key']);
        });
        if (! Schema::hasTable('internal_consumption_lines')) Schema::create('internal_consumption_lines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id')->index();
            $t->unsignedBigInteger('internal_consumption_id')->index(); $t->unsignedBigInteger('original_line_id')->nullable()->index();
            $t->unsignedBigInteger('item_id');
            foreach (['variant_id','lot_id','serial_id','bin_id','ledger_id','expense_account_id','inventory_account_id','account_override_id'] as $c) $t->unsignedBigInteger($c)->nullable();
            $t->decimal('quantity', 18, 4); $t->decimal('unit_cost', 18, 6)->nullable(); $t->decimal('total_cost', 18, 2)->nullable();
            $t->json('conversion'); $t->string('account_source', 24)->nullable(); $t->text('notes')->nullable(); $t->timestamps();
        });
    }
    public function down(): void
    {
        // Transactional history and customer settings deliberately survive code rollback.
    }
};
