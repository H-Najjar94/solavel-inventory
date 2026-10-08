<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\Item;
use App\Models\Tenant\PurchasingDocumentOutbox;
use App\Models\Tenant\ReceivingRequest;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Unit;
use App\Services\Integration\ApprovedFinanceIntegrationEntitlement;
use App\Services\Integration\FinanceOnboardingReadiness;
use App\Services\Integration\IntegrationSafetyHold;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use App\Services\Integration\SolaStockJournalContract;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Independent document outbox. It creates no journal, bill, or physical movement. */
final class ReceiptHandoffService
{
    public function __construct(private OrganizationContext $context) {}

    private function mapped(IntegrationOrganizationMapping $m, string $type, ?int $id): ?int
    {
        if (! $id) {
            return null;
        }

        return IntegrationMasterDataMapping::query()->where('organization_mapping_uuid', $m->mapping_uuid)->where('central_client_id', $m->central_client_id)->where('central_organization_id', $m->central_organization_id)->where('finance_organization_id', $m->finance_organization_id)->where('solastock_organization_id', $m->solastock_organization_id)->whereNull('conflict_code')->whereNull('error_state')->where('solastock_archived', false)->where('solabooks_archived', false)->where('entity_type', $type)->where('solastock_record_id', (string) $id)->where('status', 'verified')->value('solabooks_record_id');
    }

    public function record(GoodsReceipt $g, bool $reverse = false): ?PurchasingDocumentOutbox
    {
        $m = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $g->organization_id)->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->whereIn('status', ['verified', 'verified_hold'])->whereIn('activation_state', ['active', 'maintenance_hold'])->first();
        $setting = IntegrationSetting::query()->where('organization_id', $g->organization_id)->where('integration', 'solabooks')->first();
        if (! $m || ! in_array($setting?->mode, ['active', 'paused', 'connected_readonly', 'connected_pending_mapping'], true)) {
            return null;
        }
        $life = IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $m->mapping_uuid)->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $g->id)->first();
        if (! $life) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $eventType = $reverse ? 'purchasing.receipt.reversed' : 'purchasing.receipt.confirmed';
        $key = 'purchasing:receipt:'.$life->mapping_uuid.':'.($reverse ? 'reversed' : 'confirmed');
        $existing = PurchasingDocumentOutbox::query()->where('source_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $journal = IntegrationOutboxEvent::query()->where('organization_id', $g->organization_id)->where('event_type', 'grn.posted')->where('aggregate_id', $g->id)->firstOrFail();
        $request = $g->receiving_request_id ? ReceivingRequest::query()->findOrFail($g->receiving_request_id) : null;
        $missing = [];
        $supplier = $this->mapped($m, 'supplier', $g->supplier_id);
        if (! $supplier) {
            $missing[] = 'supplier_mapping';
        }
        $g->loadMissing('lines');
        $lines = [];
        foreach ($g->lines as $l) {
            if (! Decimal::gt((string) $l->accepted_qty, '0')) {
                continue;
            }
            $item = $this->mapped($m, 'item', $l->item_id);
            $unit = $this->mapped($m, 'unit', $l->entered_unit_id);
            if (! $item) {
                $missing[] = 'item_mapping:'.$l->id;
            }if (! $unit) {
                $missing[] = 'unit_mapping:'.$l->id;
            }
            $f = (string) ($l->unit_conversion_factor ?: '1');
            $lines[] = ['source_line_id' => (string) $l->id, 'source_bill_line_id' => $request?->lines()->whereKey($l->receiving_request_line_id)->value('source_line_id'), 'item_id' => $l->item_id, 'item_name' => Item::query()->find($l->item_id)?->name, 'item_sku' => Item::query()->find($l->item_id)?->sku, 'unit_id' => $l->entered_unit_id, 'unit_label' => Unit::query()->find($l->entered_unit_id)?->code, 'item_external_id' => $item, 'unit_external_id' => $unit, 'quantity' => Decimal::qty(Decimal::div((string) $l->accepted_qty, $f)), 'unit_cost' => Decimal::cost(Decimal::mul((string) $l->unit_cost, $f))];
        }
        $currency = (array) data_get($journal->payload, 'currency', []);
        $uuid = (string) Str::uuid();
        $payload = ['source_app' => 'solastock', 'schema_version' => 'purchasing.v1', 'contract_version' => SolaStockJournalContract::VERSION, 'event_uuid' => $uuid, 'event_type' => $eventType, 'external_source_key' => $key, 'inventory_organization_id' => $g->organization_id, 'finance_organization_id' => $m->finance_organization_id, 'identity' => ['central_client_id' => $m->central_client_id, 'central_organization_id' => $m->central_organization_id, 'finance_organization_id' => $m->finance_organization_id, 'inventory_organization_id' => $m->solastock_organization_id, 'integration_mapping_id' => $m->id, 'organization_mapping_uuid' => $m->mapping_uuid, 'signing_key_id' => (string) data_get($setting->meta, 'signing_key_id')],
            'receipt' => ['mapping_uuid' => $life->mapping_uuid, 'id' => $g->id, 'number' => $g->grn_number, 'date' => $g->receipt_date?->format('Y-m-d'), 'request_uuid' => $request?->request_uuid, 'receiving_request' => $request ? app(ReceivingRequestService::class)->status($request) : null, 'source_bill_id' => $request?->source_bill_id, 'purchase_order_id' => $g->purchase_order_id, 'supplier_id' => $g->supplier_id, 'supplier_name' => Supplier::query()->find($g->supplier_id)?->name, 'supplier_external_id' => $supplier, 'currency_code' => $currency['code'] ?? $m->base_currency_code, 'exchange_rate' => $currency['exchange_rate'] ?? '1', 'exchange_rate_date' => $currency['rate_date'] ?? $g->receipt_date?->format('Y-m-d'), 'base_currency_code' => $m->base_currency_code, 'accounting_source_key' => $journal->idempotency_key, 'journal_idempotency_key' => $journal->idempotency_key, 'lines' => $lines, 'missing_information' => array_values(array_unique($missing))]];

        return PurchasingDocumentOutbox::create(['organization_id' => $g->organization_id, 'event_uuid' => $uuid, 'event_type' => $eventType, 'source_key' => $key, 'goods_receipt_id' => $g->id, 'payload' => $payload, 'payload_hash' => hash('sha256', SolaStockJournalContract::canonicalJson($payload))]);
    }

    private function enabled(): void
    {
        $org = $this->context->idOrFail();
        $safety = app(IntegrationSafetyHold::class);
        $safety->assertDeliveryEnabledFor($org);
        if (! $safety->workerEnabledFor($org)) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }app(FinanceOnboardingReadiness::class)->assertComplete($org);
        $m = app(ReceivingRequestService::class)->mapping();
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($m);
        $s = IntegrationSetting::query()->where('organization_id', $org)->where('integration', 'solabooks')->first();
        if ($s?->mode !== 'active' || data_get($s->meta, 'transport_enabled') !== true) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
    }

    public function deliverDue(int $limit = 25): int
    {
        if (! Schema::connection('tenant')->hasTable('purchasing_document_outbox')) {
            return 0;
        }$this->enabled();
        $count = 0;
        for ($i = 0; $i < $limit; $i++) {
            $event = DB::connection('tenant')->transaction(function () {
                $e = PurchasingDocumentOutbox::query()->where(function ($q) {
                    $q->where('status', 'ready')->orWhere(fn ($q) => $q->where('status', 'retry')->where('next_attempt_at', '<=', now()))->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_expires_at', '<=', now()));
                })
                    ->whereNotExists(function ($q) {
                        $q->selectRaw('1')->from('purchasing_document_outbox as prior')->whereColumn('prior.organization_id', 'purchasing_document_outbox.organization_id')->whereColumn('prior.goods_receipt_id', 'purchasing_document_outbox.goods_receipt_id')->whereColumn('prior.id', '<', 'purchasing_document_outbox.id')->where('prior.status', '!=', 'sent');
                    })->orderBy('id')->lockForUpdate()->first();
                if (! $e) {
                    return null;
                }$e->update(['status' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(60), 'attempts' => $e->attempts + 1]);

                return $e;
            });
            if (! $event) {
                break;
            }
            $ok = false;
            $response = [];
            $error = null;
            $intervention = false;
            try {
                $this->enabled();
                if (! hash_equals($event->payload_hash, hash('sha256', SolaStockJournalContract::canonicalJson($event->payload)))) {
                    throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
                }$response = app(SolaBooksOutboxDeliveryService::class)->sendPurchasingDocument($event);
                $outcome = \App\Services\Integration\DocumentHandoffOutcome::classify($response,(string)$event->event_type);
                $ok = $outcome['successful'];
                $intervention = $outcome['intervention'];
                $response['data']['delivery_reason']=$outcome['reason'];
                $error = $outcome['reason'];
            } catch (Throwable $e) {
                $error = 'delivery_pending';
                if ($e instanceof \App\Services\Integration\PartyDependencyPending) {
                    $response=['data'=>$e->details];
                    $error=$e->details['reason'];
                    $intervention=$e->details['state']==='intervention';
                }
                report($e);
            }
            DB::connection('tenant')->transaction(function () use ($event, $ok, $response, $error, $intervention) {
                $e = PurchasingDocumentOutbox::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($e->status !== 'processing' || $e->lease_token !== $event->lease_token) {
                    return;
                }$e->update(['status' => $ok ? 'sent' : ($intervention || $e->attempts>=40 ? 'intervention' : 'retry'), 'lease_token' => null, 'lease_expires_at' => null, 'next_attempt_at' => $ok ? null : now()->addSeconds(min(3600, 30 * (2 ** min(7, $e->attempts)))), 'last_error' => $ok ? null : \App\Services\Integration\DocumentHandoffOutcome::message($error), 'receiver_response' => $response['data'] ?? null]);
            });
            if ($intervention || (!$ok && $event->attempts===1) || ($ok && $event->attempts>1)) {
                \Illuminate\Support\Facades\Log::log($ok?'info':'warning','purchasing.document_handoff.'.($ok?'resolved':($intervention?'intervention':'retrying')),[
                    'organization_id'=>(int)$event->organization_id,'document_outbox_id'=>(int)$event->id,
                    'goods_receipt_id'=>(int)$event->goods_receipt_id,'source_key'=>$event->source_key,
                    'correlation_id'=>$event->event_uuid,'attempt_count'=>(int)$event->attempts,
                    'reason'=>$error,'destination_bill_id'=>$response['data']['bill_id']??null,
                    'dependency'=>$response['data']['entity_type']??null,'dependency_source_id'=>$response['data']['source_id']??null]);
            }
            app(\App\Services\Integration\DocumentIncidentNotificationPublisher::class)->changed('receipt',(int)$event->id,(int)$event->organization_id);
            $count++;
        }

        return $count;
    }
}
