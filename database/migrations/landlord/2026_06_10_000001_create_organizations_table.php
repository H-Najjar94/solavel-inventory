<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LANDLORD (central registry) migration.
 *
 * organizations is the tenant registry: one row per tenant, mapping the central
 * organization id to its dedicated tenant database. Lives on the landlord
 * connection, NOT inside any tenant DB.
 *
 * Run with: php artisan tenancy:migrate-landlord  (see TenancyMigrate command)
 */
return new class extends Migration
{
    protected ?string $connection = null;

    public function __construct()
    {
        $this->connection = config('tenancy.central_connection', 'mysql');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('organizations')) {
            return; // additive / idempotent
        }

        $schema->create('organizations', function (Blueprint $table) {
            $table->id();
            // Reference to the central Solavel org (source of truth lives in central).
            $table->unsignedBigInteger('central_organization_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->nullable();
            // Dedicated tenant database name (e.g. tenant_000001).
            $table->string('database_name')->unique();
            $table->string('base_currency', 3)->default('SAR');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('organizations');
    }
};
