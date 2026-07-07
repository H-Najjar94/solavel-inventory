<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_entitlements_snapshots')) {
            Schema::table('tenant_entitlements_snapshots', function (Blueprint $table): void {
                foreach ([
                    'evaluated_at' => fn () => $table->timestamp('evaluated_at')->nullable()->after('synced_at'),
                    'pushed_at' => fn () => $table->timestamp('pushed_at')->nullable()->after('evaluated_at'),
                    'valid_until' => fn () => $table->timestamp('valid_until')->nullable()->after('pushed_at'),
                    'schema_version' => fn () => $table->string('schema_version', 80)->nullable()->after('valid_until'),
                    'source_version' => fn () => $table->string('source_version', 191)->nullable()->after('schema_version'),
                    'state_hash' => fn () => $table->string('state_hash', 64)->nullable()->after('source_version'),
                    'created_at' => fn () => $table->timestamp('created_at')->nullable(),
                    'updated_at' => fn () => $table->timestamp('updated_at')->nullable(),
                ] as $column => $definition) {
                    if (! Schema::hasColumn('tenant_entitlements_snapshots', $column)) {
                        $definition();
                    }
                }
            });

            return;
        }

        Schema::create('tenant_entitlements_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('project_slug', 100)->index();
            $table->json('payload');
            $table->string('version', 191)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('schema_version', 80)->nullable();
            $table->string('source_version', 191)->nullable();
            $table->string('state_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->unique(['client_id', 'project_slug'], 'tenant_entitlements_snapshots_client_project_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlements_snapshots');
    }
};
