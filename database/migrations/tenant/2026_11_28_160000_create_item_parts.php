<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item Parts: catalog metadata linking a parent item to the parts that belong to it.
 * Additive and re-runnable. SolaCount keeps its own `inventory_item_parts` in the same
 * tenant database; both are read as one union (see ItemPartService).
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasTable('item_parts')) {
            return;
        }
        $schema->create('item_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('parent_item_id');
            $table->unsignedBigInteger('part_item_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'parent_item_id', 'part_item_id'], 'item_parts_org_parent_part_unique');
            $table->index(['organization_id', 'part_item_id'], 'item_parts_org_part_index');
        });
    }

    public function down(): void
    {
        // Additive release: never drop data.
    }
};
