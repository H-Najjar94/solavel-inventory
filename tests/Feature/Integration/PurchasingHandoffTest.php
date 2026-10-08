<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
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
use App\Models\User;
use App\Services\Access\CentralAppAccess;
use App\Services\Access\InventoryPermissionService;
use App\Services\Access\OperationalReceiving;
use App\Services\Access\WarehouseAccessService;
use App\Services\Catalog\UnitConversionResolver;
use App\Services\Documents\GoodsReceiptService;
use App\Services\Documents\InventoryReversalService;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use App\Services\Integration\FinanceBaseValuation;
use App\Services\Integration\FinancialLineAllocationService;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\InventoryWorkspace\WorkspaceDispatcher;
use App\Services\Purchasing\FinanceReceivingService;
use App\Services\Purchasing\PurchasingBillAuthority;
use App\Services\Purchasing\ReceivingRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
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

    private function cancelledGeneration(): array
    {
        $old = $this->data() + ['source_status'=>'posted','billing_policy'=>'billed-unreceived-v1','posted_bill_journal_id'=>99];
        $proof = ['command'=>'cancel','request_uuid'=>$old['request_uuid'],'command_source_revision'=>$old['source_revision'],'source_bill_id'=>$old['source_bill_id'],'permission'=>'post','status'=>'posted','bill_journal_id'=>99,'receiving_payload'=>$old];
        app(ReceivingRequestService::class)->cancelAuthorized($old, $proof);
        DB::connection('tenant')->table('journal_entries')->insert(['id'=>99,'organization_id'=>14,'number'=>'QA-GEN-99','entry_date'=>'2026-10-07','status'=>'posted','posted_at'=>now()]);
        DB::connection('tenant')->table('bills')->insert(['id'=>800,'organization_id'=>14,'supplier_id'=>703,'status'=>'paid','journal_entry_id'=>99]);
        $new = array_replace($old, ['request_uuid'=>(string) Str::uuid(),'source_revision'=>str_repeat('b',64),'receiving_generation'=>1,'previous_request_uuid'=>$old['request_uuid']]);
        // Native signed Finance authorization is the remote boundary; local Stock facts remain real.
        request()->attributes->set('purchasing_authority', ['resubmission_allowed'=>true,'receiving_request_uuid'=>$new['request_uuid'],'receiving_request_generation'=>1,'previous_request_uuid'=>$old['request_uuid'],'source_bill_id'=>$old['source_bill_id'],'status'=>'posted','permission'=>'post','bill_journal_id'=>99]);
        return [$old, $new];
    }

    public function test_cancelled_undelivered_generation_creates_one_new_request_and_preserves_old_replay_denial(): void
    {
        [$old,$new]=$this->cancelledGeneration();$service=app(ReceivingRequestService::class);
        $one=$service->upsert($new);$two=$service->upsert($new);
        $this->assertSame($one['id'],$two['id']);$this->assertSame('pending',$one['status']);
        $this->assertSame(1,ReceivingRequest::query()->count());$this->assertSame(1,DB::connection('tenant')->table('purchasing_receiving_cancellations')->count());
        $this->assertSame(0,StockLedger::query()->count());$this->assertSame(0,GoodsReceipt::query()->count());
        try{$service->upsert($old);$this->fail('Cancelled predecessor was resurrected.');}catch(ValidationException $e){$this->assertArrayHasKey('request_uuid',$e->errors());}
        $this->assertSame($new['request_uuid'],ReceivingRequest::query()->sole()->request_uuid);
    }

    public function test_new_generation_requires_current_authority_and_exact_cancelled_predecessor(): void
    {
        [$old,$new]=$this->cancelledGeneration();$proof=request()->attributes->get('purchasing_authority');
        request()->attributes->set('purchasing_authority',array_replace($proof,['bill_journal_id'=>100]));
        try{app(ReceivingRequestService::class)->upsert($new);$this->fail('Wrong active journal proof accepted.');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        request()->attributes->set('purchasing_authority',array_replace($proof,['previous_request_uuid'=>(string)Str::uuid()]));
        try{app(ReceivingRequestService::class)->upsert($new);$this->fail('Wrong cancellation identity accepted.');}catch(HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame(0,ReceivingRequest::query()->count());
    }

    public function test_generation_keeps_mapping_guards_and_competing_uuid_cannot_create_second_request(): void
    {
        [$old,$new]=$this->cancelledGeneration();$supplier=IntegrationMasterDataMapping::query()->where('entity_type','supplier')->sole();$supplier->update(['status'=>'pending']);
        try{app(ReceivingRequestService::class)->upsert($new);$this->fail('Missing supplier mapping accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('supplier_external_id',$e->errors());}
        $this->assertSame(0,ReceivingRequest::query()->count());$supplier->update(['status'=>'verified']);
        app(ReceivingRequestService::class)->upsert($new);$competing=array_replace($new,['request_uuid'=>(string)Str::uuid()]);
        request()->attributes->set('purchasing_authority',array_replace(request()->attributes->get('purchasing_authority'),['receiving_request_uuid'=>$competing['request_uuid']]));
        try{app(ReceivingRequestService::class)->upsert($competing);$this->fail('Competing command duplicated the bill request.');}catch(ValidationException $e){$this->assertArrayHasKey('request_uuid',$e->errors());}
        $this->assertSame(1,ReceivingRequest::query()->count());$this->assertSame(0,StockLedger::query()->count());
    }

    public function test_cancelled_command_history_is_not_a_receipt_and_is_tenant_scoped(): void
    {
        [$old,$new]=$this->cancelledGeneration();
        DB::connection('tenant')->table('purchasing_receiving_commands')->insert(['organization_id'=>TenantTestManager::ORG_A,'operation_uuid'=>(string)Str::uuid(),'receiving_request_id'=>0,'source_bill_id'=>800,'actor_id'=>1,'payload_hash'=>str_repeat('c',64),'payload'=>'{}','status'=>'prepared','created_at'=>now(),'updated_at'=>now()]);
        try{app(ReceivingRequestService::class)->upsert($new);$this->fail('Uncertain receiving operation was ignored.');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame(0,ReceivingRequest::query()->count());
        $rows=app(\App\Services\Purchasing\ReceivingCancellationHistory::class)->rows();
        $this->assertCount(1,$rows);$this->assertNull($rows[0]['id']);$this->assertNull($rows[0]['number']);
        $this->assertSame('cancelled_before_delivery',$rows[0]['kind']);$this->assertSame($old['request_uuid'],$rows[0]['request_uuid']);
        $this->assertSame('4.0000',$rows[0]['lines'][0]['requested_qty']);$this->assertSame([],$rows[0]['receipts']);
        $this->useTenantB();$this->assertSame([],app(\App\Services\Purchasing\ReceivingCancellationHistory::class)->rows());
    }

    private function draft(ReceivingRequest $r, string $qty): GoodsReceipt
    {
        return app(GoodsReceiptService::class)->createDraft(['receiving_request_id' => $r->id, 'warehouse_id' => $this->warehouse->id, 'supplier_id' => $r->supplier_id, 'receipt_date' => '2026-10-06'], [['receiving_request_line_id' => $r->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $this->unit->id, 'received_qty' => $qty, 'accepted_qty' => $qty, 'unit_cost' => '2.5000']]);
    }

    public function test_missing_item_mapping_is_structured_and_same_request_resumes_after_mapping_repair(): void
    {
        $data=$this->data();$mapping=IntegrationMasterDataMapping::where('organization_mapping_uuid',$this->mapping->mapping_uuid)->where('entity_type','item')->sole();$mapping->update(['status'=>'pending']);
        try { app(ReceivingRequestService::class)->upsert($data);$this->fail('Missing mapping accepted'); }
        catch(ValidationException $e){$body=$e->response->getData(true);$this->assertSame('item',$body['dependency']['entity_type']);$this->assertSame(701,$body['dependency']['source_id']);$this->assertSame('lines.0.item_external_id',$body['dependency']['field']);}
        $this->assertSame(0,ReceivingRequest::count());$mapping->update(['status'=>'verified']);$result=app(ReceivingRequestService::class)->upsert($data);$this->assertSame($data['request_uuid'],$result['request_uuid']);$this->assertSame(1,ReceivingRequest::count());$this->assertSame(0,StockLedger::count());
    }

    public function test_missing_unit_mapping_has_exact_source_identity_without_creating_units(): void
    {
        $data=$this->data();$before=Unit::count();IntegrationMasterDataMapping::where('organization_mapping_uuid',$this->mapping->mapping_uuid)->where('entity_type','unit')->update(['status'=>'pending']);
        try {app(ReceivingRequestService::class)->upsert($data);$this->fail('Unmapped unit accepted');}
        catch(ValidationException $e){$body=$e->response->getData(true);$this->assertSame('unit',$body['dependency']['entity_type']);$this->assertSame(702,$body['dependency']['source_id']);}
        $this->assertSame($before,Unit::count());$this->assertSame(0,ReceivingRequest::count());
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
        app(ReceivingRequestService::class)->upsert($this->data());
        $g = $this->draft(ReceivingRequest::sole(), '1');
        app(GoodsReceiptService::class)->post($g);
        $life = IntegrationDocumentLifecycleMapping::where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $g->id)->sole();
        $authority = ['organization_mapping_uuid' => $this->mapping->mapping_uuid, 'finance_organization_id' => 14, 'receipt_ids' => [$g->id], 'receipt_mapping_uuids' => [$life->mapping_uuid]];
        request()->attributes->set('verified_workspace_action', 'purchasing.bill.receipt');
        request()->attributes->set('purchasing_authority', $authority);
        $this->assertTrue(PurchasingBillAuthority::receipt($g->id));
        $this->assertFalse(PurchasingBillAuthority::receipt($g->id + 1));
        foreach (['receipt_mapping_uuids' => [(string) Str::uuid()], 'organization_mapping_uuid' => (string) Str::uuid(), 'finance_organization_id' => 99] as $key => $value) {
            request()->attributes->set('purchasing_authority', array_replace($authority, [$key => $value]));
            $this->assertFalse(PurchasingBillAuthority::receipt($g->id));
        }
        request()->attributes->set('purchasing_authority', $authority);
        request()->attributes->set('verified_workspace_action', 'finance-sources.receipt');
        $this->assertFalse(PurchasingBillAuthority::receipt($g->id));
        request()->attributes->set('verified_workspace_action', 'purchasing.bill.receipt');
        $life->update(['lifecycle_status' => 'reversed']);
        $this->assertFalse(PurchasingBillAuthority::receipt($g->id));
    }

    public function test_finance_only_bound_receipt_dispatch_preserves_organization_scope_without_stock_warehouse_access(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $g = $this->draft(ReceivingRequest::sole(), '1');
        app(GoodsReceiptService::class)->post($g);
        $life = IntegrationDocumentLifecycleMapping::where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $g->id)->sole();
        $authority = ['organization_mapping_uuid' => $this->mapping->mapping_uuid, 'finance_organization_id' => 14, 'receipt_ids' => [$g->id], 'receipt_mapping_uuids' => [$life->mapping_uuid]];
        $outer = Request::create('/api/finance-workspace', 'POST');
        $actor = new GenericUser(['id' => 335]);
        $this->actingAs($actor);
        $outer->setUserResolver(fn () => $actor);
        $outer->attributes->set('purchasing_authority', $authority);
        $access = $this->createStub(CentralAppAccess::class);
        $access->method('decision')->willReturnCallback(fn ($id, $org, $app) => ['allowed' => $app === 'finance']);
        $this->app->instance(CentralAppAccess::class, $access);
        $commercial = $this->createStub(InventoryCommercialEntitlementService::class);
        $commercial->method('checkPermission')->willReturn(['allowed' => true, 'reason_code' => 'allowed']);
        $this->app->instance(InventoryCommercialEntitlementService::class, $commercial);
        $warehouses = $this->createStub(WarehouseAccessService::class);
        $warehouses->method('allowedIds')->willReturn([]);
        $warehouses->method('scope')->willReturnCallback(fn ($query, $column = 'warehouse_id') => $query->whereRaw('1 = 0'));
        $this->app->instance(WarehouseAccessService::class, $warehouses);
        $this->assertSame(0, GoodsReceipt::whereKey($g->id)->count());
        $input = ['action' => 'purchasing.bill.receipt', 'parameters' => ['goods_receipt' => $g->id], 'data' => ['source_bill_id' => 800, 'destination_document_type' => 'supplier_bill', 'destination_document_id' => 800]];
        $dispatcher = app(WorkspaceDispatcher::class);
        $response = $dispatcher->dispatch($outer, $input, $this->mapping, IntegrationSetting::sole());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($g->id, $response->getData(true)['data']['document_id']);
        $this->assertSame(0, GoodsReceipt::whereKey($g->id)->count());
        $outer->attributes->set('verified_workspace_action', 'purchasing.bill.reserve');
        $allocationInput = ['destination_document_type' => 'supplier_bill', 'destination_document_id' => 800,
            'destination_revision' => str_repeat('a', 64), 'destination_fingerprint' => str_repeat('a', 64),
            'allocation_kind' => 'bill', 'currency_code' => 'JOD', 'base_currency_code' => 'JOD', 'exchange_rate' => '1',
            'allocations' => [['source_document_mapping_uuid' => $life->mapping_uuid, 'source_document_type' => 'goods_receipt',
                'source_document_id' => $g->id, 'source_line_id' => $g->lines()->sole()->id, 'stock_item_id' => $this->item->id,
                'destination_line_id' => 801, 'entered_quantity' => '1', 'base_quantity' => '1', 'destination_quantity' => '1',
                'destination_unit_id' => 702, 'destination_unit_price' => '2.5', 'destination_gross' => '2.5',
                'line_discount_allocated' => '0', 'document_discount_allocated' => '0', 'destination_net' => '2.5']]];
        $this->app->instance('request', $outer);
        $reservation = app(FinancialLineAllocationService::class)->reserve($allocationInput);
        $this->assertCount(1, $reservation['allocations']);
        $this->assertSame(0, GoodsReceipt::whereKey($g->id)->count());
        foreach (['receipt_mapping_uuids' => [(string) Str::uuid()], 'receipt_ids' => [$g->id + 1], 'organization_mapping_uuid' => (string) Str::uuid()] as $key => $value) {
            $outer->attributes->set('purchasing_authority', array_replace($authority, [$key => $value]));
            try {
                $dispatcher->dispatch($outer, $input, $this->mapping, IntegrationSetting::sole());
                $this->fail('Unbound receipt scope accepted');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $outer->attributes->set('purchasing_authority', $authority);
        app(OrganizationContext::class)->set(TenantTestManager::ORG_B);
        try {
            $dispatcher->dispatch($outer, $input, $this->mapping, IntegrationSetting::withoutGlobalScopes()->where('organization_id', TenantTestManager::ORG_A)->sole());
            $this->fail('Cross-organization receipt accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        } finally {
            app(OrganizationContext::class)->set(TenantTestManager::ORG_A);
        }
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

    public function test_disconnected_foreign_receipt_preserves_existing_finance_base_cost_pool(): void
    {
        DB::connection('tenant')->table('exchange_rates')->insert(['organization_id' => 14, 'base_currency_code' => 'JOD', 'quote_currency_code' => 'USD', 'rate' => '1.25000000', 'rate_date' => '2026-10-06', 'source' => 'manual']);
        $data = $this->data();
        $data['currency_code'] = 'USD';
        $data['exchange_rate'] = '1.25000000';
        $data['exchange_rate_date'] = '2026-10-06';
        app(ReceivingRequestService::class)->upsert($data);
        $request = ReceivingRequest::sole();
        app(GoodsReceiptService::class)->post($this->draft($request, '1'));
        $before = StockBalance::sole()->getAttributes();
        $this->assertSame('2.0000', StockLedger::sole()->unit_cost);
        IntegrationSetting::sole()->update(['mode' => 'disconnected']);
        $receipt = $this->draft($request->fresh(), '1');
        try {
            app(GoodsReceiptService::class)->post($receipt);
            $this->fail('Disconnected ownership must not mix transaction costs into the existing base pool.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('valuation', $failure->errors());
        }
        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame($before, StockBalance::sole()->getAttributes());
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, PurchasingDocumentOutbox::count());
        $this->assertSame(1, IntegrationOutboxEvent::count());
        // Reconnect keeps the reviewed contract and dated rate; resume this
        // exact draft, rather than resetting ownership or creating another GRN.
        $contract = IntegrationSetting::sole()->meta['finance_currency_contract'];
        IntegrationSetting::sole()->update(['mode' => 'active']);
        app(GoodsReceiptService::class)->post($receipt);
        $this->assertSame($contract, IntegrationSetting::sole()->meta['finance_currency_contract']);
        $this->assertSame('posted', $receipt->fresh()->status);
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(['2.0000', '2.0000'], StockLedger::orderBy('id')->pluck('unit_cost')->all());
        $this->assertSame('2.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame(2, PurchasingDocumentOutbox::count());
        $this->assertSame(2, IntegrationOutboxEvent::count());
        app(GoodsReceiptService::class)->post($receipt->fresh());
        $this->assertSame(2, StockLedger::count());
        $this->assertSame(2, PurchasingDocumentOutbox::count());
        $this->assertSame(2, IntegrationOutboxEvent::count());
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

    public function test_finance_receiving_options_reflect_native_approval_warehouse_and_remaining_state(): void
    {
        $data = $this->data() + ['source_status' => 'posted', 'billing_policy' => 'billed-unreceived-v1', 'posted_bill_journal_id' => 102];
        app(ReceivingRequestService::class)->upsert($data);
        $source = ReceivingRequest::sole();
        $actor = new User;
        $actor->id = 812;
        request()->setUserResolver(fn () => $actor);
        $this->mock(InventoryPermissionService::class, fn ($mock) => $mock->shouldReceive('can')->andReturn(true));
        $hideWarehouse = false;
        $this->mock(WarehouseAccessService::class, function ($mock) use (&$hideWarehouse) {
            $mock->shouldReceive('assertAllowed')->andReturnNull();
            $mock->shouldReceive('allowedIds')->andReturn(null);
            $mock->shouldReceive('scope')->andReturnUsing(function ($query) use (&$hideWarehouse) {
                return $hideWarehouse ? $query->whereRaw('1 = 0') : $query;
            });
        });
        $service = app(FinanceReceivingService::class);
        $context = ['source_bill_id' => $source->source_bill_id, 'request_uuid' => $source->request_uuid,
            'bill_revision' => $source->source_revision];
        request()->attributes->set('purchasing_authority', ['source_revision' => $source->source_revision, 'request_revision' => $source->source_revision]);
        $unapproved = $service->options($context);
        $this->assertFalse($unapproved['can_receive']);
        $this->assertTrue($unapproved['can_approve']);
        $this->assertSame('approval_required', $unapproved['receiving_block_reason']);
        $this->assertNotEmpty($unapproved['receiving_block_message']);
        app(ReceivingRequestService::class)->approve($source, $this->warehouse->id);
        $this->assertTrue($service->options($context)['can_receive']);
        $hideWarehouse = true;
        $this->assertSame('warehouse_unavailable', $service->options($context)['receiving_block_reason']);
        $hideWarehouse = false;
        app(ReceivingRequestService::class)->cancel($source, $source->source_revision);
        $this->assertFalse($service->options($context)['can_receive']);
        $this->assertSame('cancelled', $service->options($context)['receiving_block_reason']);
        $data['source_revision'] = str_repeat('b', 64);
        $data['posted_bill_journal_id'] = 103;
        $data['reopened_from_bill_journal_id'] = 102;
        app(ReceivingRequestService::class)->upsert($data);
        $context['bill_revision'] = $data['source_revision'];
        request()->attributes->set('purchasing_authority', ['source_revision' => $data['source_revision'], 'request_revision' => $data['source_revision']]);
        $this->assertFalse($service->options($context)['can_receive']);
        $this->assertSame('approval_required', $service->options($context)['receiving_block_reason']);
        app(ReceivingRequestService::class)->approve($source->fresh(), $this->warehouse->id);
        $source->lines()->sole()->update(['received_qty' => $source->lines()->sole()->requested_qty]);
        $source->update(['status' => 'complete']);
        $this->assertFalse($service->options($context)['can_receive']);
        $this->assertSame('complete', $service->options($context)['receiving_block_reason']);
    }

    public function test_finance_native_receiving_command_is_durable_and_replays_exact_native_receipt(): void
    {
        app(ReceivingRequestService::class)->upsert($this->data());
        $source = ReceivingRequest::sole();
        app(ReceivingRequestService::class)->approve($source, $this->warehouse->id);
        $actor = new User;
        $actor->id = 812;
        request()->setUserResolver(fn () => $actor);
        request()->attributes->set('purchasing_authority', ['allowed' => true, 'source_revision' => $source->source_revision, 'request_revision' => $source->source_revision]);
        $this->mock(InventoryPermissionService::class, fn ($mock) => $mock->shouldReceive('can')->andReturn(true));
        $this->mock(WarehouseAccessService::class, function ($mock) {
            $mock->shouldReceive('assertAllowed')->andReturnNull();
            $mock->shouldReceive('allowedIds')->andReturn(null);
            $mock->shouldReceive('scope')->andReturnUsing(fn ($query) => $query);
        });
        $data = ['source_bill_id' => $source->source_bill_id, 'request_uuid' => $source->request_uuid,
            'bill_revision' => $source->source_revision, 'operation_uuid' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06',
            'lines' => [['source_bill_line_id' => 801, 'request_line_id' => $source->lines()->sole()->id,
                'quantity' => '1', 'unit_id' => $this->unit->id, 'unit_cost' => '2.5']]];
        $service = app(FinanceReceivingService::class);
        $unprepared = $data;
        $unprepared['operation_uuid'] = (string) Str::uuid();
        $this->assertSame('abandoned', $service->abandon($unprepared)['status']);
        try {
            $service->execute($unprepared);
            $this->fail('Delayed unprepared operation executed after abandonment.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame('prepared', $service->prepare($data)['status']);
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, StockLedger::count());
        $this->assertSame('abandoned', $service->abandon($data)['status']);
        $this->assertSame('abandoned', $service->abandon($data)['status']);
        try {
            $service->execute($data);
            $this->fail('Abandoned operation was executed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, GoodsReceipt::count());
        $data['operation_uuid'] = (string) Str::uuid();
        $one = $service->execute($data);
        $two = $service->execute($data);
        $this->assertSame($one['goods_receipt_id'], $two['goods_receipt_id']);
        $this->assertSame('posted', $one['status']);
        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(1, PurchasingDocumentOutbox::count());
        $this->assertSame('partial', $source->fresh()->status);
        try {
            $service->abandon($data);
            $this->fail('Posted receipt command was abandoned.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(1, StockLedger::count());
        $changed = $data;
        $changed['lines'][0]['quantity'] = '2';
        $this->expectException(HttpException::class);
        $service->execute($changed);
    }

    public function test_posted_promotion_preserves_approved_partial_request_and_original_line_identity(): void
    {
        $service = app(ReceivingRequestService::class);
        $data = $this->data();
        $service->upsert($data);
        $source = ReceivingRequest::sole();
        $service->approve($source, $this->warehouse->id);
        $lineId = $source->lines()->sole()->id;
        app(GoodsReceiptService::class)->post($this->draft($source, '1'));
        $data += ['source_status' => 'posted', 'billing_policy' => 'billed-unreceived-v1', 'posted_bill_journal_id' => 102];
        $data['source_revision'] = str_repeat('b', 64);
        $service->upsert($data);
        $this->assertSame('partial', $source->fresh()->status);
        $this->assertSame($lineId, $source->lines()->sole()->id);
        $this->assertSame('1.0000', $source->lines()->sole()->received_qty);
        $this->assertSame(str_repeat('b', 64), $source->fresh()->approved_revision);
        $this->assertSame(1, GoodsReceipt::count());
        $data['source_revision'] = str_repeat('c', 64);
        $data['lines'][0]['quantity'] = '5';
        $this->expectException(ValidationException::class);
        $service->upsert($data);
    }

    public function test_cancelled_posted_request_reopens_only_for_new_journal_and_requires_reapproval(): void
    {
        $service = app(ReceivingRequestService::class);
        $data = $this->data() + ['source_status' => 'posted', 'billing_policy' => 'billed-unreceived-v1', 'posted_bill_journal_id' => 102];
        $service->upsert($data);
        $source = ReceivingRequest::sole();
        $service->approve($source, $this->warehouse->id);
        app(GoodsReceiptService::class)->post($this->draft($source, '1'));
        $line = $source->lines()->sole();
        $service->cancel($source, $source->source_revision);
        $data['source_revision'] = str_repeat('d', 64);
        $data['posted_bill_journal_id'] = 103;
        $data['reopened_from_bill_journal_id'] = 102;
        $service->upsert($data);
        $this->assertSame('partial', $source->fresh()->status);
        $this->assertNull($source->fresh()->approved_at);
        $this->assertSame($line->id, $source->lines()->sole()->id);
        $this->assertSame('1.0000', $source->lines()->sole()->received_qty);
        $service->cancel($source->fresh(), $data['source_revision']);
        $data['source_revision'] = str_repeat('e', 64);
        $this->expectException(HttpException::class);
        $service->upsert($data);
    }

    public function test_receiver_approved_box_cost_is_compared_in_normalized_base_units(): void
    {
        $box = Unit::create(['code' => 'ROUND-BOX', 'name' => 'Box', 'kind' => 'count', 'is_active' => true]);
        $this->master('unit', $box->id, 704);
        UnitConversion::create(['item_id' => $this->item->id, 'from_unit_id' => $box->id, 'to_unit_id' => $this->unit->id, 'factor' => '6']);
        $data = $this->data();
        $data['lines'][0] = ['source_line_id' => $data['lines'][0]['source_line_id'], 'item_external_id' => $data['lines'][0]['item_external_id'], 'unit_external_id' => 704, 'quantity' => '2', 'unit_cost' => '12.1'];
        app(ReceivingRequestService::class)->upsert($data);
        $source = ReceivingRequest::sole();
        app(ReceivingRequestService::class)->approve($source, $this->warehouse->id);
        $receipt = app(GoodsReceiptService::class)->createDraft(['receiving_request_id' => $source->id, 'supplier_id' => $source->supplier_id, 'warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'], [['receiving_request_line_id' => $source->lines()->sole()->id, 'item_id' => $this->item->id, 'entered_unit_id' => $box->id, 'received_qty' => '1', 'accepted_qty' => '1', 'unit_cost' => '12.1']]);
        $this->mock(InventoryPermissionService::class)->shouldReceive('can')->andReturn(false);
        app(OperationalReceiving::class)->posting($receipt->load('lines'));
        app(GoodsReceiptService::class)->post($receipt);
        $this->assertSame('6.0000', StockBalance::sole()->on_hand_qty);
        $this->assertSame('2.0167', $receipt->lines()->sole()->unit_cost);
    }

    public function test_native_standalone_stock_has_no_finance_outbox_without_connection_identity(): void
    {
        $this->useTenantB();
        $warehouse = F::warehouse();
        $item = F::averageItem();
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $warehouse->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $item->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '5']]);
        app(GoodsReceiptService::class)->post($receipt);
        $this->assertSame(1, StockLedger::count());
        $this->assertSame(0, IntegrationOutboxEvent::count());
        $this->assertSame(0, PurchasingDocumentOutbox::count());
    }

    public function test_disconnected_existing_identity_retains_pending_finance_ownership(): void
    {
        IntegrationSetting::sole()->update(['mode' => 'disconnected']);
        $receipt = app(GoodsReceiptService::class)->createDraft(['warehouse_id' => $this->warehouse->id, 'receipt_date' => '2026-10-06'],
            [['item_id' => $this->item->id, 'received_qty' => '2', 'accepted_qty' => '2', 'unit_cost' => '5']]);
        try {
            app(GoodsReceiptService::class)->post($receipt);
            $this->fail('A disconnected owned cost pool cannot switch to standalone valuation.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('valuation', $failure->errors());
        }
        $this->assertSame('draft', $receipt->fresh()->status);
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, IntegrationOutboxEvent::count());
        $this->assertSame(0, PurchasingDocumentOutbox::count());
    }
}
