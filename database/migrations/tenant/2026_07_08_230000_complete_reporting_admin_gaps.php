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
        if (! Schema::hasTable('inventory_alerts')) {
            Schema::create('inventory_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('alert_key');
                $table->string('type');
                $table->string('severity')->default('warning');
                $table->string('title');
                $table->text('message')->nullable();
                $table->string('route')->nullable();
                $table->json('channels')->nullable();
                $table->json('metadata')->nullable();
                $table->string('status')->default('open');
                $table->timestamp('triggered_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->unsignedBigInteger('acknowledged_by')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'alert_key'], 'inventory_alerts_org_key_uniq');
                $table->index(['organization_id', 'status', 'severity'], 'inventory_alerts_org_status_sev_idx');
            });
        }

        if (Schema::hasTable('inventory_scheduled_reports')) {
            Schema::table('inventory_scheduled_reports', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_scheduled_reports', 'last_run_at')) {
                    $table->timestamp('last_run_at')->nullable()->after('next_run_at');
                }
                if (! Schema::hasColumn('inventory_scheduled_reports', 'last_delivered_at')) {
                    $table->timestamp('last_delivered_at')->nullable()->after('last_run_at');
                }
                if (! Schema::hasColumn('inventory_scheduled_reports', 'last_status')) {
                    $table->string('last_status')->nullable()->after('last_delivered_at');
                }
                if (! Schema::hasColumn('inventory_scheduled_reports', 'last_error')) {
                    $table->text('last_error')->nullable()->after('last_status');
                }
                if (! Schema::hasColumn('inventory_scheduled_reports', 'last_payload')) {
                    $table->json('last_payload')->nullable()->after('last_error');
                }
            });
        }

        if (! Schema::hasTable('inventory_currency_rates')) {
            Schema::create('inventory_currency_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('currency_code', 3);
                $table->decimal('rate_to_base', 18, 8);
                $table->date('effective_date');
                $table->timestamps();

                $table->unique(['organization_id', 'currency_code', 'effective_date'], 'inventory_currency_rates_org_ccy_date_uniq');
            });
        }

        if (! Schema::hasTable('inventory_custom_roles')) {
            Schema::create('inventory_custom_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('key');
                $table->string('name');
                $table->json('permissions');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'key'], 'inventory_custom_roles_org_key_uniq');
            });
        }

        if (! Schema::hasTable('inventory_user_role_assignments')) {
            Schema::create('inventory_user_role_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'user_id'], 'inventory_role_assignments_org_user_uniq');
                $table->index(['organization_id', 'role_id'], 'inventory_role_assignments_org_role_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_user_role_assignments');
        Schema::dropIfExists('inventory_custom_roles');
        Schema::dropIfExists('inventory_currency_rates');
        Schema::dropIfExists('inventory_alerts');
    }
};
