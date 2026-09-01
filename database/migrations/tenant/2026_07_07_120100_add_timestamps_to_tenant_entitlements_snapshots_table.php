<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Shared Core owns this table. Earlier SolaStock releases added Laravel
        // timestamps that are absent from the canonical Shared Core capability.
        // Keep the represented migration as an intentional no-op so a fresh
        // SolaStock history produces the canonical shape and never alters a
        // SolaCount-provisioned shared table. EntitlementsCache already treats
        // these legacy columns as optional.
    }

    public function down(): void
    {
        // Shared Core columns are never removed by SolaStock.
    }
};
