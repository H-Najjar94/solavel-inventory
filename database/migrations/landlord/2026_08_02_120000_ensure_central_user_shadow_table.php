<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('tenancy.central_connection', 'mysql');
        if (! Schema::connection($connection)->hasTable('users')) {
            Schema::connection($connection)->create('users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('client_id')->nullable()->index();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('phone')->nullable();
                $table->string('identification_number')->nullable();
                $table->text('address')->nullable();
                $table->string('password');
                $table->string('status')->default('active');
                $table->timestamp('email_verified_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });

            return;
        }

        foreach (['phone' => 'string', 'identification_number' => 'string', 'address' => 'text'] as $column => $type) {
            if (! Schema::connection($connection)->hasColumn('users', $column)) {
                Schema::connection($connection)->table('users', function (Blueprint $table) use ($column, $type): void {
                    $type === 'text' ? $table->text($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // The central user registry is shared evidence and is never dropped by
        // the SolaStock application migration rollback.
    }
};
