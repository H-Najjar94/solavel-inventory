<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('purchasing_receiving_commands')) {
            Schema::connection('tenant')->create('purchasing_receiving_commands', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->uuid('operation_uuid');
                $table->unsignedBigInteger('receiving_request_id');
                $table->unsignedBigInteger('source_bill_id');
                $table->unsignedBigInteger('actor_id');
                $table->char('payload_hash', 64);
                $table->json('payload');
                $table->string('status', 24)->default('prepared');
                $table->unsignedBigInteger('goods_receipt_id')->nullable();
                $table->timestamps();
                $table->unique(['organization_id', 'operation_uuid'], 'prc_org_operation_unique');
                $table->index(['organization_id', 'source_bill_id'], 'prc_org_bill_index');
            });
        }
    }

    public function down(): void
    {
        // Receiving command identity is permanent audit evidence. Rollback code only.
    }
};
