<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchasingDocumentOutbox;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\ReceivingRequestLine;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockLedger;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\OperationalReceiving;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Purchasing\PurchasingBillAuthority;
use App\Services\Purchasing\ReceivingRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StockTestFactory as F;
use Tests\Support\TenantTestManager;
use Tests\TestCase;
use Tests\Traits\TenantAware;

final class PurchasingHandoffTest extends TestCase
{
    use TenantAware;

    private IntegrationOrganizationMapping $mapping;

    private $warehouse;

    private $item;

    private $supplier;

    private $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTenantA();
        DB::connection('tenant')->table('organizations')->insert(['id' => 14, 'central_org_id' => TenantTestManager::ORG_A, 'setup_status' => 'complete', 'finance_setup_completed_at' => now()]);
        $this->mapping = IntegrationOrganizationMapping::create(['mapping_uuid' => (string) Str::uuid(), 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'tenant_database_identity' => DB::connection('tenant')->getDatabaseName(), 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'contract_version' => 'solastock-journal.v2', 'status' => 'verified', 'activation_state' => 'active', 'base_currency_code' => 'JOD', 'verified_at' => now()]);
        IntegrationSetting::create(['integration' => 'solabooks', 'mode' => 'active', 'solabooks_organization_id' => 14, 'meta' => ['client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'signing_key_id' => 'test-key', 'finance_currency_contract' => ['base_currency_code' => 'JOD', 'enabled_currency_codes' => ['JOD', 'USD'], 'currency_precisions' => ['JOD' => 2, 'USD' => 2], 'money_scale' => 2, 'rate_scale' => 8, 'inventory_valuation_basis' => FinanceBaseValuation::BASIS]]]);
        $this->warehouse = F::warehouse();
        $this->unit = Unit::create(['code' => 'PUR-EACH', 'name' => 'Each', 'kind' => 'count', 'is_active' => true]);
        $this->item = F::averageItem(['base_unit_id' => $this->unit->id]);
        $this->supplier = Supplier::create(['code' => 'PUR-SUP', 'name' => 'Supplier', 'is_active' => true]);
        $this->master('item', $this->item->id, 701);
        $this->master('unit', $this->unit->id, 702);
        $this->master('supplier', $this->supplier->id, 703);
        foreach (['inventory_asset' => 100, 'grni' => 200] as $role => $id) {
            DB::connection('tenant')->table('accounts')->insert(['id' => $id, 'organization_id' => 14, 'code' => (string) $id, 'name' => $role, 'type' => $role === 'grni' ? 'liability' : 'asset', 'is_active' => true, 'is_postable' => true]);
            $a = IntegrationAccountMapping::create(['integration' => 'solabooks', 'mapping_type' => $role, 'solabooks_account_id' => $id, 'status' => 'verified']);
            $this->master('account_role', $a->id, $id);
        }
    }

    private function master($type, $stock, $finance): void
    {
        IntegrationMasterDataMapping::create(['mapping_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $this->mapping->mapping_uuid, 'central_client_id' => 7, 'central_organization_id' => TenantTestManager::ORG_A, 'finance_organization_id' => 14, 'solastock_organization_id' => TenantTestManager::ORG_A, 'entity_type' => $type, 'solastock_record_id' => (string) $stock, 'solabooks_record_id' => (string) $finance, 'status' => 'verified']);
    }

    private function data(): array
    {
        return ['request_uuid' => (string) Str::uuid(), 'source_bill_id' => 800, 'source_bill_number' => 'DRAFT-800', 'source_revision' => str_repeat('a', 64), 'supplier_external_id' => 703, 'currency_code' => 'JOD', 'lines' => [['source_line_id' => '801', 'item_external_id' => 701, 'unit_external_id' => 702, 'quantity' => '4.0000', 'unit_cost' => '2.5000']]];
    }

    private function draft(ReceivingRequest $r, string $qty): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->createDraft(['receiving_request_id' => $r->id, 'warehouse_id' => $this->warehouse->id, 'supplier_id' => $r->supplier_id, 'receipt_date' => '2026-10-06'], [['receiving_request_line_id' => $r->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => $qty, 'accepted_qty' => $qty, 'unit_cost' => '2.5000']]);
    }

    public function test_bill_request_is_idempotent_and_moves_no_stock_or_journal(): void
    {
        $s = app(ReceivingRequestService::class);
        $d = $this->data();
        $one = $s->upsert($d);
        $two = $s->upsert($d);
        $this->assertSame($one['id'], $two['id']);
        $this->assertSame('pending', $one['status']);
        $this->assertSame('0.0000', $one['lines'][0]['received_qty']);
        $this->assertSame(1, ReceivingRequest::count());
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, PurchasingDocumentOutbox::count());
    }

    public function test_partial_final_native_receipts_retries_and_durable_document_handoff(): void
    {
        $s = app(ReceivingRequestService::class);
        $s->upsert($this->data());
        $r = ReceivingRequest::sole();
        $grns = app(GoodsReceiptService::class);
        $first = $this->draft($r, '1');
        $this->assertSame(0, StockLedger::count());
        $grns->post($first);
        $grns->post($first);
        $this->assertSame('partial', $r->fresh()->status);
        $this->assertSame('1.0000', $r->lines()->sole()->received_qty);
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, PurchasingDocumentOutbox::count());
        $second = $this->draft($r, '3');
        $grns->post($second);
        $grns->post($second);
        $this->assertSame('complete', $r->fresh()->status);
        $this->assertSame('4.0000', $r->lines()->sole()->received_qty);
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(2, PurchasingDocumentOutbox::count());
        $this->assertSame('4.0000', StockBalance::sole()->on_hand_qty);
        $e = PurchasingDocumentOutbox::query()->first();
        $this->assertSame('purchasing.v1', $e->payload['schema_version']);
        $this->assertSame(800, $e->payload['receipt']['source_bill_id']);
        $this->assertSame(703, $e->payload['receipt']['supplier_external_id']);
        $this->assertSame(701, $e->payload['receipt']['lines'][0]['item_external_id']);
        $this->assertSame('1.0000', $e->payload['receipt']['lines'][0]['quantity']);
        $this->assertSame('2.5000', $e->payload['receipt']['lines'][0]['unit_cost']);
        $this->assertSame(2, IntegrationOutboxEvent::where('event_type', 'grn.posted')->count());
    }

    public function test_over_receipt_rolls_back_all_physical_and_outbox_effects(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $r = ReceivingRequest::sole();
        $g = $this->draft($r, '5');
        try {
            app(GoodsReceiptService::class)->post($g);
            $this->fail('Over receipt permitted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }$this->assertSame('draft', $g->fresh()->status);
        $this->assertSame('0.0000', $r->lines()->sole()->received_qty);
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, PurchasingDocumentOutbox::count());
    }

    public function test_request_revision_cas_and_existing_receipt_prevent_silent_overwrite(): void
    {
        $s = app(ReceivingRequestService::class);
        $d = $this->data();
        $s->upsert($d);
        $d['source_revision'] = str_repeat('b', 64);
        try {
            $s->upsert($d);
            $this->fail('CAS required');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }$d['expected_revision'] = str_repeat('a', 64);
        $s->upsert($d);
        $r = ReceivingRequest::sole();
        $this->draft($r, '1');
        $d['expected_revision'] = str_repeat('b', 64);
        $d['source_revision'] = str_repeat('c', 64);
        $this->expectException(ValidationException::class);
        $s->upsert($d);
    }

    public function test_cancel_preserves_partial_receipt_and_blocks_future_receiving(): void
    {
        $s = app(ReceivingRequestService::class);
        $s->upsert($this->data());
        $r = ReceivingRequest::sole();
        $g = $this->draft($r, '1');
        app(GoodsReceiptService::class)->post($g);
        $s->cancel($r, $r->source_revision);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame('1.0000', $r->lines()->sole()->received_qty);
        $this->assertSame(1, StockLedger::count());
        $this->expectException(ValidationException::class);
        $this->draft($r, '1');
    }

    public function test_unknown_mapping_does_not_create_partial_request(): void
    {
        $d = $this->data();
        $d['lines'][0]['item_external_id'] = 999999;
        try {
            app(ReceivingRequestService::class)->upsert($d);
            $this->fail('Unknown mapping permitted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.item_external_id', $e->errors());
        }$this->assertSame(0, ReceivingRequest::count());
        $this->assertSame(0, ReceivingRequestLine::count());
    }

    public function test_foreign_organization_cannot_read_or_update_request(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $id = ReceivingRequest::sole()->id;
        app(OrganizationContext::class)->set(TenantTestManager::ORG_B);
        $this->assertNull(ReceivingRequest::find($id));
        $this->assertSame(0, ReceivingRequest::count());
    }

    public function test_explicit_purchase_approval_allows_receiver_only_and_locks_warehouse_and_price(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $r = ReceivingRequest::sole();
        $this->mock(InventoryPermissionService::class, fn ($mock) => $mock->shouldReceive('can')->andReturn(false));
        $receiving = app(OperationalReceiving::class);
        $data = ['receiving_request_id' => $r->id, 'warehouse_id' => $this->warehouse->id, 'supplier_id' => $r->supplier_id, 'lines' => [['receiving_request_line_id' => $r->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'unit_cost' => '2.5000', 'received_qty' => '1']]];
        try {
            $receiving->prepare($data);
            $this->fail('Unapproved request allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        app(ReceivingRequestService::class)->approve($r, $this->warehouse->id);
        $this->assertTrue(app(ReceivingRequestService::class)->status($r->fresh())['approved']);
        $prepared = $receiving->prepare($data);
        $this->assertSame('2.5000', (string) $prepared['lines'][0]['unit_cost']);
        $g = $this->draft($r, '1');
        $receiving->posting($g);
        app(GoodsReceiptService::class)->post($g);
        $this->assertSame('partial', $r->fresh()->status);
        $data['lines'][0]['unit_cost'] = '3';
        $this->expectException(HttpException::class);
        $receiving->prepare($data);
    }

    public function test_revision_edit_invalidates_purchase_approval_without_rewriting_receipts(): void
    {
        $d = $this->data();
        app(ReceivingRequestService::class)->upsert($d);
        $r = ReceivingRequest::sole();
        app(ReceivingRequestService::class)->approve($r, $this->warehouse->id);
        $d['expected_revision'] = $d['source_revision'];
        $d['source_revision'] = str_repeat('d', 64);
        $d['lines'][0]['quantity'] = '5';
        app(ReceivingRequestService::class)->upsert($d);
        $this->assertFalse(app(ReceivingRequestService::class)->status($r->fresh())['approved']);
        $this->assertNull($r->fresh()->warehouse_id);
        $this->assertSame(0, StockLedger::count());
    }

    public function test_native_receipt_reversal_preserves_documents_and_creates_one_reversal_handoff(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $r = ReceivingRequest::sole();
        $g = $this->draft($r, '1');
        app(GoodsReceiptService::class)->post($g);
        $service = app(InventoryReversalService::class);
        $reverse = $service->reverseGoodsReceipt($g, 'Supplier delivery corrected');
        $same = $service->reverseGoodsReceipt($g, 'Repeated click');
        $this->assertSame($reverse->id, $same->id);
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertSame('0.0000', $r->lines()->sole()->received_qty);
        $this->assertSame(2, StockLedger::count());
        $this->assertSame('0.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame(2, PurchasingDocumentOutbox::count());
        $event = PurchasingDocumentOutbox::where('event_type', 'purchasing.receipt.reversed')->sole();
        $this->assertSame(800, $event->payload['receipt']['source_bill_id']);
        $this->assertSame('2.5000', $event->payload['receipt']['lines'][0]['unit_cost']);
    }

    public function test_standalone_receipt_without_supplier_is_durable_missing_information_not_invented_bill(): void
    {
        $g = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'], [['item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => '1', 'unit_cost' => '2.5000']]);
        app(GoodsReceiptService::class)->post($g);
        $e = PurchasingDocumentOutbox::sole();
        $this->assertNull($e->payload['receipt']['source_bill_id']);
        $this->assertNull($e->payload['receipt']['supplier_external_id']);
        $this->assertContains('supplier_mapping', $e->payload['receipt']['missing_information']);
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, IntegrationOutboxEvent::where('event_type', 'grn.posted')->count());
    }

    public function test_conflicted_verified_mapping_is_rejected_without_partial_request(): void
    {
        IntegrationMasterDataMapping::where('entity_type', 'item')->update(['conflict_code' => 'manual_review']);
        try {
            app(ReceivingRequestService::class)->upsert($this->data());
            $this->fail('Conflict accepted');
        } catch (ValidationException) {
            $this->assertSame(0, ReceivingRequest::count());
        }
    }

    public function test_purchasing_bill_receipt_scope_never_authorizes_unbound_sources(): void
    {
        request()->attributes->set('verified_workspace_action', 'purchasing.bill.receipt');
        request()->attributes->set('purchasing_authority', ['receipt_ids' => [17]]);
        $this->assertTrue(PurchasingBillAuthority::receipt(17));
        $this->assertFalse(PurchasingBillAuthority::receipt(18));
        request()->attributes->set('verified_workspace_action', 'finance-sources.receipt');
        $this->assertFalse(PurchasingBillAuthority::receipt(17));
    }

    public function test_entered_box_units_preserve_partial_status_cost_and_physical_base_quantity(): void
    {
        $box = Unit::create(['code' => 'PUR-BOX', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $this->master('unit', $box->id, 704);
        UnitConversion::create(['item_id' => $this->item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $this->unit->id, 'factor' => '10']);
        $d = $this->data();
        $d['lines'][0]['unit_external_id'] = 704;
        $d['lines'][0]['quantity'] = '2';
        $d['lines'][0]['unit_cost'] = '25';
        app(ReceivingRequestService::class)->upsert($d);
        $r = ReceivingRequest::sole();
        app(ReceivingRequestService::class)->approve($r, $this->warehouse->id);
        $g = app(GoodsReceiptService::class)->createDraft(['receiving_request_id' => $r->id, 'supplier_id' => $r->supplier_id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'], [['receiving_request_line_id' => $r->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $box->id, 'entered_qty' => '1', 'received_qty' => '1', 'accepted_qty' => '1', 'unit_cost' => '25']]);
        app(GoodsReceiptService::class)->post($g);
        $status = app(ReceivingRequestService::class)->status($r->fresh());
        $this->assertSame('1.0000', $status['lines'][0]['received_qty']);
        $this->assertSame('1.0000', $status['receipts'][0]['lines'][0]['received_qty']);
        $this->assertSame('10.0000', $status['receipts'][0]['lines'][0]['received_base_qty']);
        $this->assertSame('10.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('2.5000', StockLedger::sole()->unit_cost);
        $line = PurchasingDocumentOutbox::sole()->payload['receipt']['lines'][0];
        $this->assertSame('1.0000', $line['quantity']);
        $this->assertSame('25.0000', $line['unit_cost']);
        $this->assertSame(704, $line['unit_external_id']);
    }

    public function test_changed_conversion_requires_explicit_request_reapproval_before_receiving(): void
    {
        $box = Unit::create(['code' => 'PUR-BOX2', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $this->master('unit', $box->id, 704);
        $c = UnitConversion::create(['item_id' => $this->item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $this->unit->id, 'factor' => '10']);
        $d = $this->data();
        $d['lines'][0]['unit_external_id'] = 704;
        app(ReceivingRequestService::class)->upsert($d);
        $r = ReceivingRequest::sole();
        app(ReceivingRequestService::class)->approve($r, $this->warehouse->id);
        $c->update(['factor' => '12']);
        $attributes = ['receiving_request_id' => $r->id, 'supplier_id' => $r->supplier_id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'];
        $lines = [['receiving_request_line_id' => $r->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $box->id, 'received_qty' => '1', 'accepted_qty' => '1', 'unit_cost' => '2.5000']];
        try {
            app(GoodsReceiptService::class)->createDraft($attributes, $lines);
            $this->fail('Changed conversion accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.entered_unit_id', $e->errors());
        }
        app(ReceivingRequestService::class)->approve($r, $this->warehouse->id);
        $g = app(GoodsReceiptService::class)->createDraft($attributes, $lines);
        $this->assertSame('12.00000000', $g->lines->sole()->unit_conversion_factor);
        $this->assertSame(0, StockLedger::count());
    }

    public function test_foreign_receiving_request_keeps_currency_and_dated_fx_base_valuation(): void
    {
        DB::connection('tenant')->table('exchange_rates')->insert(['organization_id' => 14, 'base_currency_code' => 'JOD', 'quote_currency_code' => 'USD', 'rate' => '1.25000000', 'rate_date' => '2026-10-06', 'source' => 'manual']);
        $d = $this->data();
        $d['currency_code'] = 'USD';
        $d['exchange_rate'] = '1.25000000';
        $d['exchange_rate_date'] = '2026-10-06';
        app(ReceivingRequestService::class)->upsert($d);
        $g = $this->draft(ReceivingRequest::sole(), '1');
        app(GoodsReceiptService::class)->post($g);
        $e = PurchasingDocumentOutbox::sole();
        $this->assertSame('USD', $e->payload['receipt']['currency_code']);
        $this->assertSame('1.25000000', $e->payload['receipt']['exchange_rate']);
        $this->assertSame('2.5000', $e->payload['receipt']['lines'][0]['unit_cost']);
        $this->assertSame('2.0000', StockLedger::sole()->unit_cost);
    }

    public function test_missing_foreign_dated_fx_blocks_receipt_without_stock_or_document_handoff(): void
    {
        $d = $this->data();
        $d['currency_code'] = 'USD';
        app(ReceivingRequestService::class)->upsert($d);
        $g = $this->draft(ReceivingRequest::sole(), '1');
        try {
            app(GoodsReceiptService::class)->post($g);
            $this->fail('Missing FX accepted');
        } catch (ValidationException) {
            $this->assertSame(0, StockLedger::count());
            $this->assertSame(0, PurchasingDocumentOutbox::count());
            $this->assertSame('draft', $g->fresh()->status);
        }
    }

    public function test_signed_document_delivery_uses_separate_endpoint_and_exact_immutable_payload(): void
    {
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        config()->set('services.solabooks.journal_entries_url', 'https://finance.example.invalid/api/v1/journal-entries');
        $setting = IntegrationSetting::sole();
        $meta = $setting->meta;
        $meta['api_key_encrypted'] = Crypt::encryptString('isolated-api-key');
        $meta['signing_secret_encrypted'] = Crypt::encryptString('isolated-purchasing-secret-at-least-32-characters');
        $meta['signing_protocol_version'] = 'v1';
        $setting->update(['meta' => $meta]);
        app(ReceivingRequestService::class)->upsert($this->data());
        $g = $this->draft(ReceivingRequest::sole(), '1');
        app(GoodsReceiptService::class)->post($g);
        $event = PurchasingDocumentOutbox::sole();
        $event->update(['status' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinute()]);
        Http::fake(['https://finance.example.invalid/api/v1/purchasing/receipts' => Http::response(['success' => true, 'data' => ['bill_id' => 999, 'status' => 'draft']], 200)]);
        $result = app(SolaBooksOutboxDeliveryService::class)->sendPurchasingDocument($event);
        $this->assertTrue($result['successful']);
        $this->assertSame(999, $result['data']['bill_id']);
        Http::assertSent(function ($request) use ($event) {
            $this->assertSame($event->source_key, $request->header('Idempotency-Key')[0]);
            $this->assertSame('purchasing.receipt.confirmed', $request->header('X-Solavel-Event-Type')[0]);
            $this->assertSame(hash('sha256', $request->body()), $request->header('X-Solavel-Content-SHA256')[0]);
            $this->assertSame(SolaStockJournalContract::canonicalJson($event->payload), $request->body());

            return str_ends_with($request->url(), '/purchasing/receipts');
        });
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, PurchasingDocumentOutbox::count());
    }

    public function test_document_transport_refuses_event_without_current_processing_lease(): void
    {
        config()->set('integration_safety.solabooks_delivery_enabled', true);
        app(ReceivingRequestService::class)->upsert($this->data());
        $g = $this->draft(ReceivingRequest::sole(), '1');
        app(GoodsReceiptService::class)->post($g);
        Http::fake();
        try {
            app(SolaBooksOutboxDeliveryService::class)->sendPurchasingDocument(PurchasingDocumentOutbox::sole());
            $this->fail('Unclaimed event delivered');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
            $this->assertSame(1, StockLedger::count());
        }
    }

    public function test_purchase_order_duplicate_source_lines_cannot_collectively_over_receive(): void
    {
        $po = PurchaseOrder::create(['po_number' => 'QA-PO-OVER', 'status' => 'approved', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => '2026-10-06', 'currency_code' => 'JOD', 'integration_currency_code' => 'JOD']);
        $attributes = app(UnitConversionResolver::class)->normalizeLine(['item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'ordered_qty' => '4', 'unit_price' => '2.5000'], 'ordered_qty');
        $source = $po->lines()->create($attributes);
        $line = ['purchase_order_line_id' => $source->id, 'item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => '3', 'accepted_qty' => '3', 'unit_cost' => '2.5000'];
        $g = app(GoodsReceiptService::class)->createDraft(['purchase_order_id' => $po->id, 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'], [$line, $line]);
        try {
            app(GoodsReceiptService::class)->post($g);
            $this->fail('Combined over receipt permitted');
        } catch (\RuntimeException) {
            $this->assertSame(0, StockLedger::count());
            $this->assertSame('0.0000', $source->fresh()->received_qty);
            $this->assertSame('draft', $g->fresh()->status);
        }
    }

    public function test_paused_connection_preserves_local_handoff_for_later_delivery(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $r = ReceivingRequest::sole();
        IntegrationSetting::sole()->update(['mode' => 'paused']);
        $g = $this->draft($r, '1');
        app(GoodsReceiptService::class)->post($g);
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, PurchasingDocumentOutbox::count());
        $this->assertSame('ready', PurchasingDocumentOutbox::sole()->status);
        $this->assertSame(800, PurchasingDocumentOutbox::sole()->payload['receipt']['source_bill_id']);
    }
}
