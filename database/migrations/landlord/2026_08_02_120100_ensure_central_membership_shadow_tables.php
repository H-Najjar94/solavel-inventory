<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('tenancy.central_connection', 'mysql');
        $schema = Schema::connection($connection);

        foreach (['client_id' => 'unsignedBigInteger', 'display_name' => 'string', 'legal_name' => 'string'] as $column => $type) {
            if (! $schema->hasColumn('organizations', $column)) {
                $schema->table('organizations', function (Blueprint $table) use ($column, $type): void {
                    $type === 'unsignedBigInteger'
                        ? $table->unsignedBigInteger($column)->nullable()->index()
                        : $table->string($column)->nullable();
                });
            }
        }
        if (! $schema->hasColumn('users', 'organization_id')) {
            $schema->table('users', fn (Blueprint $table) => $table->unsignedBigInteger('organization_id')->nullable()->index());
        }
        if (! $schema->hasTable('user_organizations')) {
            $schema->create('user_organizations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('organization_id');
                $table->string('role')->default('client_member');
                $table->string('status')->nullable()->default('active');
                $table->timestamps();
                $table->unique(['user_id', 'organization_id']);
            });
        }
    }

    public function down(): void
    {
        // Central shadow identities are shared evidence and are never removed
        // by an application migration rollback.
    }
};
