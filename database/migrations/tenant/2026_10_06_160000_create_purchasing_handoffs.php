<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchasing_receiving_requests')) {
            Schema::create('purchasing_receiving_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->uuid('request_uuid')->unique();
                $t->uuid('organization_mapping_uuid');
                $t->unsignedBigInteger('finance_organization_id');
                $t->unsignedBigInteger('source_bill_id');
                $t->string('source_bill_number', 100);
                $t->string('source_revision', 64);
                $t->unsignedBigInteger('supplier_id');
                $t->string('currency_code', 3);
                $t->decimal('exchange_rate', 24, 12)->nullable();
                $t->date('exchange_rate_date')->nullable();
                $t->string('status', 30)->default('pending');
                $t->json('source_payload');
                $t->unsignedBigInteger('warehouse_id')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('approved_revision', 64)->nullable();
                $t->timestamps();
                $t->unique(['organization_id', 'source_bill_id'], 'prr_org_bill_unique');
            });
        }
        if (! Schema::hasTable('purchasing_receiving_request_lines')) {
            Schema::create('purchasing_receiving_request_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->unsignedBigInteger('receiving_request_id');
                $t->string('source_line_id', 100);
                $t->unsignedBigInteger('item_id');
                $t->unsignedBigInteger('entered_unit_id');
                $t->decimal('requested_qty', 20, 4);
                $t->decimal('received_qty', 20, 4)->default(0);
                $t->decimal('unit_cost', 20, 4);
                $t->json('approval_conversion')->nullable();
                $t->timestamps();
                $t->unique(['receiving_request_id', 'source_line_id'], 'prr_line_source_unique');
            });
        }
        if (! Schema::hasColumn('goods_receipts', 'receiving_request_id')) {
            Schema::table('goods_receipts', fn (Blueprint $t) => $t->unsignedBigInteger('receiving_request_id')->nullable()->index());
        }
        if (! Schema::hasColumn('goods_receipt_lines', 'receiving_request_line_id')) {
            Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->unsignedBigInteger('receiving_request_line_id')->nullable()->index());
        }
        if (! Schema::hasTable('purchasing_document_outbox')) {
            Schema::create('purchasing_document_outbox', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('organization_id');
                $t->uuid('event_uuid')->unique();
                $t->string('event_type', 80);
                $t->string('source_key', 150)->unique();
                $t->unsignedBigInteger('goods_receipt_id');
                $t->json('payload');
                $t->string('payload_hash', 64);
                $t->string('status', 30)->default('ready');
                $t->unsignedInteger('attempts')->default(0);
                $t->uuid('lease_token')->nullable();
                $t->timestamp('lease_expires_at')->nullable();
                $t->timestamp('next_attempt_at')->nullable();
                $t->text('last_error')->nullable();
                $t->json('receiver_response')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchasing_document_outbox');
        Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn('receiving_request_line_id'));
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropColumn('receiving_request_id'));
        Schema::dropIfExists('purchasing_receiving_request_lines');
        Schema::dropIfExists('purchasing_receiving_requests');
    }
};
