<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.tenant_connection', 'tenant');
    }

    public function up(): void
    {
        Schema::create('integration_tax_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('integration')->default('solabooks');
            $table->string('tax_code', 50);
            $table->enum('treatment', ['standard', 'zero', 'exempt']);
            $table->unsignedBigInteger('solabooks_tax_id')->nullable();
            $table->string('solabooks_tax_code', 50)->nullable();
            $table->unsignedBigInteger('input_tax_account_id')->nullable();
            $table->unsignedBigInteger('output_tax_account_id')->nullable();
            $table->enum('status', ['unmapped', 'mapped', 'inactive'])->default('unmapped');
            $table->timestamps();

            $table->unique(['organization_id', 'integration', 'tax_code'], 'itm_org_int_code_uniq');
            $table->index(['organization_id', 'status'], 'itm_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tax_mappings');
    }
};
