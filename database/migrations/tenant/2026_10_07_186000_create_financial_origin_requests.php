<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        $schema=Schema::connection('tenant');
        if(!$schema->hasTable('stock_financial_origin_requests')) $schema->create('stock_financial_origin_requests',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('organization_mapping_uuid'); $t->uuid('request_uuid');
            $t->string('source_document_type',32); $t->unsignedBigInteger('source_document_id'); $t->string('source_document_number',191);
            $t->unsignedBigInteger('source_journal_id'); $t->char('source_revision',64); $t->string('side',16);
            $t->string('status',32)->default('pending'); $t->json('source_payload'); $t->unsignedBigInteger('party_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable(); $t->unsignedBigInteger('sales_order_id')->nullable();
            $t->timestamp('approved_at')->nullable(); $t->unsignedBigInteger('approved_by')->nullable(); $t->char('approved_revision',64)->nullable();
            $t->timestamps();
            $t->unique(['organization_id','request_uuid'],'sfor_org_uuid_unique');
            $t->unique(['organization_id','organization_mapping_uuid','source_document_type','source_document_id'],'sfor_source_unique');
            $t->index(['organization_id','status'],'sfor_org_status_index');
        });
        if(!$schema->hasTable('stock_financial_origin_lines')) $schema->create('stock_financial_origin_lines',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->unsignedBigInteger('request_id'); $t->unsignedBigInteger('source_document_line_id');
            $t->unsignedBigInteger('item_id'); $t->unsignedBigInteger('unit_id'); $t->decimal('unit_conversion_factor',24,10); $t->decimal('requested_quantity',24,4);
            $t->decimal('fulfilled_quantity',24,4)->default(0); $t->decimal('cancelled_quantity',24,4)->default(0); $t->decimal('unit_price',24,8);
            $t->unsignedBigInteger('sales_order_line_id')->nullable(); $t->timestamps();
            $t->unique(['organization_id','request_id','source_document_line_id'],'sfol_source_unique');
        });
        if(!$schema->hasTable('stock_financial_origin_commands')) $schema->create('stock_financial_origin_commands',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('operation_uuid'); $t->uuid('request_uuid');
            $t->string('source_document_type',32); $t->unsignedBigInteger('source_document_id'); $t->unsignedBigInteger('source_journal_id');
            $t->unsignedBigInteger('actor_id'); $t->char('payload_hash',64); $t->json('payload'); $t->string('status',32)->default('pending');
            $t->unsignedBigInteger('shipment_id')->nullable(); $t->unsignedBigInteger('goods_receipt_id')->nullable(); $t->json('response')->nullable(); $t->json('native_line_links')->nullable(); $t->timestamps();
            $t->unique(['organization_id','operation_uuid'],'sfoc_org_operation_unique');
            $t->index(['organization_id','request_uuid'],'sfoc_request_index');
        });
        if(!$schema->hasTable('stock_financial_origin_outbox')) $schema->create('stock_financial_origin_outbox',function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->uuid('organization_mapping_uuid'); $t->uuid('event_uuid'); $t->uuid('operation_uuid'); $t->string('event_type',64);
            $t->string('source_document_type',32); $t->unsignedBigInteger('source_document_id'); $t->unsignedBigInteger('source_journal_id');
            $t->string('physical_document_type',32); $t->unsignedBigInteger('physical_document_id'); $t->string('external_source_key',191);
            $t->char('payload_hash',64); $t->json('payload'); $t->string('status',32)->default('pending'); $t->unsignedInteger('attempts')->default(0);
            $t->uuid('lease_uuid')->nullable(); $t->timestamp('lease_expires_at')->nullable(); $t->timestamp('next_attempt_at')->nullable();
            $t->text('last_error')->nullable(); $t->json('response')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
            $t->unique(['organization_id','event_uuid'],'sfoo_org_event_unique'); $t->unique(['organization_id','external_source_key'],'sfoo_source_unique');
            $t->index(['organization_id','status','next_attempt_at'],'sfoo_due_index');
        });

    }
    public function down(): void { /* Durable source and command audit is preserved across rollback. */ }
};
