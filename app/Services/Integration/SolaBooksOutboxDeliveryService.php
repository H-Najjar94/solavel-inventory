<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Models\Tenant\PurchasingDocumentOutbox;
use App\Services\Purchasing\ReceivingRequestService;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SolaBooksOutboxDeliveryService
{
    public function __construct(
        private OrganizationContext $context,
        private AccountingJournalBuilder $journals,
        private SolaStockJournalContractBuilder $contracts,
        private IntegrationOutboxService $outbox,
        private IntegrationSafetyHold $safety,
    ) {}

    public function deliver(IntegrationOutboxEvent $event, bool $manual = false): IntegrationOutboxEvent
    {
        // This must remain the first operation: a blocked request must not lock
        // or mutate an event, increment attempts, mint a nonce, or call Finance.
        $this->safety->assertDeliveryEnabledFor($this->context->idOrFail());
        if (! app()->environment('testing')) {
            throw new RuntimeException('Direct delivery is disabled; use the dedicated leased v2 worker.');
        }

        $orgId = $this->context->idOrFail();
        $failure = null;

        $result = DB::connection(config('tenancy.tenant_connection', 'tenant'))->transaction(function () use ($event, $orgId, $manual, &$failure) {
            $event = IntegrationOutboxEvent::query()->where('organization_id', $orgId)->lockForUpdate()->findOrFail($event->id);

            if ($event->status === 'sent') {
                return $event;
            }
            if ($event->status === 'ignored') {
                throw new RuntimeException(__('inventory.integration.ignored_delivery'));
            }
            if (! $manual && $event->next_attempt_at && $event->next_attempt_at->isFuture()) {
                return $event;
            }

            $event->status = 'processing';
            $event->attempts = (int) $event->attempts + 1;
            $event->correlation_id = $event->correlation_id ?: $event->event_uuid;
            $event->save();

            try {
                $payload = $this->journalPayload($event, $orgId);
                $body = SolaStockJournalContract::canonicalJson($payload);
                $response = $this->signedClient($event, $payload, $body)
                    ->withBody($body, 'application/json')
                    ->post($this->journalEndpoint());

                if (! $response->successful()) {
                    throw new RuntimeException($response->json('error.message') ?: __('inventory.integration.journal_rejected'));
                }

                $data = $response->json('data') ?? [];
                $event->status = 'sent';
                $event->sent_at = now();
                $event->next_attempt_at = null;
                $event->last_error = null;
                $event->external_document_id = isset($data['id']) ? (string) $data['id'] : null;
                $event->external_response = $response->json();
                $event->dead_lettered_at = null;
                $event->save();

                IntegrationSetting::query()->updateOrCreate(
                    ['organization_id' => $orgId, 'integration' => IntegrationEvents::INTEGRATION],
                    ['last_sync_at' => now(), 'last_error' => null]
                );
                $setting = IntegrationSetting::query()
                    ->where('organization_id', $orgId)
                    ->where('integration', IntegrationEvents::INTEGRATION)
                    ->first();
                if ($setting) {
                    $meta = $setting->meta ?? [];
                    $meta['last_signed_delivery_at'] = now()->toIso8601String();
                    $setting->meta = $meta;
                    $setting->save();
                }

                return $event;
            } catch (\Throwable $e) {
                $this->markFailed($event, $e->getMessage());
                $failure = $e;

                return $event->fresh();
            }
        });

        if ($failure) {
            throw $failure;
        }

        return $result;
    }

    public function deliverDue(int $limit = 25): array
    {
        // Covers future commands/workers and bulk paths even though Phase 0
        // deliberately does not schedule an outbox worker.
        $this->safety->assertDeliveryEnabled();
        if (! app()->environment('testing')) {
            throw new RuntimeException('Legacy bulk delivery is disabled; use the dedicated leased v2 worker.');
        }

        $events = IntegrationOutboxEvent::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($events as $event) {
            try {
                $after = $this->deliver($event)->fresh();
                if ($after->status === 'sent') {
                    $result['sent']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable) {
                $result['failed']++;
            }
        }

        return $result;
    }

    private function client(IntegrationOutboxEvent $event): PendingRequest
    {
        $setting = IntegrationSetting::query()
            ->where('organization_id', $this->context->idOrFail())
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->first();
        $apiKey = (string) ($setting?->apiKey() ?: config('services.solabooks.api_key'));
        $clientId = (string) ($setting?->meta['client_id'] ?? config('services.solabooks.client_id'));
        $orgId = (string) ($setting?->solabooks_organization_id ?: config('services.solabooks.organization_id'));

        if ($apiKey === '' || $clientId === '' || $orgId === '') {
            throw new RuntimeException(__('inventory.integration.credentials_missing'));
        }

        return Http::acceptJson()
            ->asJson()
            ->timeout((int) config('services.solabooks.timeout', 10))
            ->retry(0)
            ->withHeaders([
                'X-API-Key' => $apiKey,
                'X-Client-Id' => $clientId,
                'X-Organization-Id' => $orgId,
                'Idempotency-Key' => $event->idempotency_key,
                'X-SolaStock-Event-UUID' => $event->event_uuid,
            ]);
    }

    private function signedClient(IntegrationOutboxEvent $event, array $payload, string $body, ?string $targetEndpoint = null): PendingRequest
    {
        $setting = IntegrationSetting::query()
            ->where('organization_id', $this->context->idOrFail())
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->first();
        $secret = $setting?->signingSecret();
        $keyId = (string) ($setting?->meta['signing_key_id'] ?? '');
        $version = (string) ($setting?->meta['signing_protocol_version'] ?? ExternalRequestSignature::VERSION);
        if (! $setting || ! $secret || $keyId === '' || $version !== ExternalRequestSignature::VERSION) {
            throw new RuntimeException(__('inventory.integration.signing_missing'));
        }

        $endpoint = $targetEndpoint ?? $this->journalEndpoint();
        $path = (string) (parse_url($endpoint, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($endpoint, PHP_URL_QUERY) ?: '');
        $timestamp = (string) now()->timestamp;
        $nonce = ExternalRequestSignature::nonce();
        $contentHash = ExternalRequestSignature::bodyHash($body);
        $inventoryOrg = (string) $this->context->idOrFail();
        $financeOrg = (string) $setting->solabooks_organization_id;
        $sourceKey = (string) $payload['external_source_key'];
        $eventType = (string) $payload['event_type'];
        $identity = (array) $payload['identity'];
        $contractVersion = (string) $payload['contract_version'];
        $canonical = ExternalRequestSignature::canonicalString(
            'POST', $path, $query, 'application/json', $timestamp, $nonce, $contentHash,
            $inventoryOrg, $financeOrg, $sourceKey, $eventType, $version,
            $contractVersion,
            (string) $identity['central_client_id'],
            (string) $identity['central_organization_id'],
            (string) $identity['integration_mapping_id'],
        );

        return $this->client($event)->withHeaders([
            'X-Solavel-Signature-Version' => $version,
            'X-Solavel-Key-Id' => $keyId,
            'X-Solavel-Timestamp' => $timestamp,
            'X-Solavel-Nonce' => $nonce,
            'X-Solavel-Content-SHA256' => $contentHash,
            'X-Solavel-Signature' => ExternalRequestSignature::sign($canonical, $secret),
            'X-Solavel-Inventory-Organization-Id' => $inventoryOrg,
            'X-Solavel-External-Source-Key' => $sourceKey,
            'X-Solavel-Event-Type' => $eventType,
            'X-Solavel-Contract-Version' => $contractVersion,
            'X-Solavel-Central-Client-Id' => (string) $identity['central_client_id'],
            'X-Solavel-Central-Organization-Id' => (string) $identity['central_organization_id'],
            'X-Solavel-Integration-Mapping-Id' => (string) $identity['integration_mapping_id'],
        ]);
    }

    public function sendPartyChange(object $mapping, object $state): array
    {
        $setting=IntegrationSetting::query()->where('organization_id',$mapping->solastock_organization_id)->where('integration','solabooks')->firstOrFail();
        abort_unless($setting->mode==='active',409);
        $key='party:'.hash('sha256',$mapping->mapping_uuid.'|'.$state->entity_type.'|stock|'.$state->source_id.'|'.$state->source_revision);
        $payload=['source_app'=>'solastock','schema_version'=>'purchasing.parties.v1','contract_version'=>SolaStockJournalContract::VERSION,'event_type'=>'purchasing.party.changed','event_uuid'=>(string)Str::uuid(),'external_source_key'=>$key,'inventory_organization_id'=>$mapping->solastock_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,'identity'=>['central_client_id'=>$mapping->central_client_id,'central_organization_id'=>$mapping->central_organization_id,'inventory_organization_id'=>$mapping->solastock_organization_id,'finance_organization_id'=>$mapping->finance_organization_id,'integration_mapping_id'=>$mapping->id,'signing_key_id'=>(string)data_get($setting->meta,'signing_key_id'),'organization_mapping_uuid'=>$mapping->mapping_uuid],'party'=>['source_app'=>'stock','entity_type'=>$state->entity_type,'source_id'=>(int)$state->source_id,'source_revision'=>$state->source_revision]];
        $body=SolaStockJournalContract::canonicalJson($payload);
        $endpoint=preg_replace('~/journal-entries(?:\\?.*)?$~','/purchasing/parties',$this->journalEndpoint());
        if(!$endpoint||$endpoint===$this->journalEndpoint())throw new RuntimeException('party_connection_pending');
        $event=new IntegrationOutboxEvent(['organization_id'=>$mapping->solastock_organization_id,'idempotency_key'=>$key,'event_uuid'=>$payload['event_uuid']]);
        $response=$this->signedClient($event,$payload,$body,$endpoint)->withBody($body,'application/json')->post($endpoint);
        if(!$response->successful())throw new RuntimeException('party_connection_pending');
        return (array)$response->json('data');
    }

    public function authorizePurchasing(int $actorId, int $billId, string $permission, array $closureFacts = []): array
    {
        $mapping = app(ReceivingRequestService::class)->mapping();
        $setting = IntegrationSetting::query()->where('organization_id', $this->context->idOrFail())->where('integration', 'solabooks')->firstOrFail();
        $key = 'purchasing:authorize:'.Str::uuid();
        $payload = ['source_app' => 'solastock', 'schema_version' => 'purchasing.v1', 'contract_version' => SolaStockJournalContract::VERSION, 'event_type' => 'purchasing.authorize', 'event_uuid' => (string) Str::uuid(), 'external_source_key' => $key, 'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id, 'identity' => ['central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id, 'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id, 'integration_mapping_id' => $mapping->id, 'signing_key_id' => (string) data_get($setting->meta, 'signing_key_id'), 'organization_mapping_uuid' => $mapping->mapping_uuid], 'actor_id' => $actorId, 'source_bill_id' => $billId, 'permission' => $permission];
        if(isset($closureFacts['party_command']))$payload+=array_intersect_key($closureFacts,array_flip(['party_command','party_supplier_id']));
        if (in_array($permission, ['unpost', 'void'], true) || ($closureFacts['command']??null)==='cancel') {
            $payload += array_intersect_key($closureFacts, array_flip(['closing_bill_journal_id', 'request_uuid', 'source_revision','expected_revision','command']));
        }
        $body = SolaStockJournalContract::canonicalJson($payload);
        $endpoint = preg_replace('~/journal-entries(?:\\?.*)?$~', '/purchasing/authorize', $this->journalEndpoint());
        if (! $endpoint || $endpoint === $this->journalEndpoint()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $event = new IntegrationOutboxEvent(['organization_id' => $mapping->solastock_organization_id, 'idempotency_key' => $key, 'event_uuid' => $payload['event_uuid']]);
        $response = $this->signedClient($event, $payload, $body, $endpoint)->withBody($body, 'application/json')->post($endpoint);
        abort_unless($response->successful(), $response->status() === 403 ? 403 : 503, __('inventory.purchasing.authority_unavailable'));
        $data = (array) $response->json('data');
        abort_unless(($data['allowed'] ?? false) === true && (int) ($data['actor_id'] ?? 0) === $actorId && (int) ($data['source_bill_id'] ?? 0) === $billId && (int) ($data['finance_organization_id'] ?? 0) === (int) $mapping->finance_organization_id && (int) ($data['central_organization_id'] ?? 0) === (int) $mapping->central_organization_id && ($data['permission'] ?? null) === $permission && ($data['organization_mapping_uuid'] ?? null) === $mapping->mapping_uuid, 403);

        return $data;
    }

    /** Signed, invoice-specific Finance authority; never grants a Stock application permission. */
    public function authorizeSales(int $actorId, int $invoiceId, string $permission, array $reviewFacts = []): array
    {
        abort_unless($actorId > 0 && $invoiceId > 0 && in_array($permission, ['view', 'edit_draft', 'post', 'unpost', 'void'], true), 403);
        $mapping = app(ReceivingRequestService::class)->mapping();
        $setting = IntegrationSetting::query()->where('organization_id', $this->context->idOrFail())->where('integration', 'solabooks')->firstOrFail();
        $key = 'sales:authorize:'.Str::uuid();
        $payload = [
            'source_app' => 'solastock', 'schema_version' => 'sales.v1', 'contract_version' => SolaStockJournalContract::VERSION,
            'event_type' => 'sales.authorize', 'event_uuid' => (string) Str::uuid(), 'external_source_key' => $key,
            'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id,
            'identity' => ['central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id,
                'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id,
                'integration_mapping_id' => $mapping->id, 'signing_key_id' => (string) data_get($setting->meta, 'signing_key_id'), 'organization_mapping_uuid' => $mapping->mapping_uuid],
            'actor_id' => $actorId, 'source_invoice_id' => $invoiceId, 'permission' => $permission,
        ];
        $payload += array_intersect_key($reviewFacts, array_flip(['party_command', 'party_customer_id', 'request_uuid', 'source_revision', 'expected_revision', 'command', 'closing_invoice_journal_id']));
        $body = SolaStockJournalContract::canonicalJson($payload);
        $endpoint = preg_replace('~/journal-entries(?:\\?.*)?$~', '/sales/authorize', $this->journalEndpoint());
        if (! $endpoint || $endpoint === $this->journalEndpoint()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $event = new IntegrationOutboxEvent(['organization_id' => $mapping->solastock_organization_id, 'idempotency_key' => $key, 'event_uuid' => $payload['event_uuid']]);
        $response = $this->signedClient($event, $payload, $body, $endpoint)->withBody($body, 'application/json')->post($endpoint);
        abort_unless($response->successful(), in_array($response->status(), [403, 404], true) ? 403 : 503, __('inventory.purchasing.authority_unavailable'));
        $data = (array) $response->json('data');
        abort_unless(($data['allowed'] ?? false) === true && (int) ($data['actor_id'] ?? 0) === $actorId
            && (int) ($data['source_invoice_id'] ?? 0) === $invoiceId && ($data['permission'] ?? null) === $permission
            && (int) ($data['finance_organization_id'] ?? 0) === (int) $mapping->finance_organization_id
            && (int) ($data['central_organization_id'] ?? 0) === (int) $mapping->central_organization_id
            && ($data['organization_mapping_uuid'] ?? null) === $mapping->mapping_uuid, 403);

        return $data;
    }

    public function authorizePurchaseSettlement(array $facts, string $operation): array
    {
        $mapping = app(ReceivingRequestService::class)->mapping();
        $setting = IntegrationSetting::query()->where('organization_id', $this->context->idOrFail())->where('integration', 'solabooks')->firstOrFail();
        $key = 'purchasing:settlement-authorize:'.Str::uuid();
        $payload = ['source_app' => 'solastock', 'schema_version' => 'purchasing.v2', 'contract_version' => SolaStockJournalContract::VERSION, 'event_type' => 'purchasing.settlement.authorize', 'event_uuid' => (string) Str::uuid(), 'external_source_key' => $key, 'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id, 'identity' => ['central_client_id' => $mapping->central_client_id, 'central_organization_id' => $mapping->central_organization_id, 'inventory_organization_id' => $mapping->solastock_organization_id, 'finance_organization_id' => $mapping->finance_organization_id, 'integration_mapping_id' => $mapping->id, 'signing_key_id' => (string) data_get($setting->meta, 'signing_key_id'), 'organization_mapping_uuid' => $mapping->mapping_uuid], 'operation' => $operation, 'settlement' => $facts];
        $body = SolaStockJournalContract::canonicalJson($payload);
        $endpoint = preg_replace('~/journal-entries(?:\\?.*)?$~', '/purchasing/settlements/authorize', $this->journalEndpoint());
        if (! $endpoint || $endpoint === $this->journalEndpoint()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $event = new IntegrationOutboxEvent(['organization_id' => $mapping->solastock_organization_id, 'idempotency_key' => $key, 'event_uuid' => $payload['event_uuid']]);
        $response = $this->signedClient($event, $payload, $body, $endpoint)->withBody($body, 'application/json')->post($endpoint);
        abort_unless($response->successful(), $response->status() === 403 ? 403 : 503, __('inventory.purchasing.authority_unavailable'));
        $data = (array) $response->json('data');
        abort_unless(($data['allowed'] ?? false) === true
            && ($data['operation'] ?? null) === $operation
            && ($data['settlement_uuid'] ?? null) === ($facts['settlement_uuid'] ?? null)
            && (int) ($data['bill_id'] ?? 0) === (int) ($facts['source_bill_id'] ?? 0)
            && (int) ($data['bill_journal_id'] ?? 0) === (int) ($facts['bill_journal_id'] ?? 0)
            && (int) ($data['finance_organization_id'] ?? 0) === (int) $mapping->finance_organization_id
            && (int) ($data['central_organization_id'] ?? 0) === (int) $mapping->central_organization_id
            && ($data['organization_mapping_uuid'] ?? null) === $mapping->mapping_uuid, 403);

        return $data;
    }

    public function sendPurchasingDocument(PurchasingDocumentOutbox $document): array
    {
        $this->safety->assertDeliveryEnabledFor((int) $document->organization_id);
        if ($document->status !== 'processing' || ! $document->lease_token) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $event = new IntegrationOutboxEvent(['organization_id' => $document->organization_id, 'idempotency_key' => $document->source_key, 'event_uuid' => $document->event_uuid]);
        $payload = $document->payload;
        if (($payload['schema_version'] ?? null) !== 'purchasing.v1' || ! in_array($payload['event_type'] ?? null, ['purchasing.receipt.confirmed', 'purchasing.receipt.reversed', 'purchasing.return.confirmed', 'purchasing.return.reversed'], true)) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        if (! $document->lease_expires_at || $document->lease_expires_at->isPast()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        if (! hash_equals((string) $document->payload_hash, hash('sha256', SolaStockJournalContract::canonicalJson($payload)))) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $body = SolaStockJournalContract::canonicalJson($payload);
        $endpoint = preg_replace('~/journal-entries(?:\\?.*)?$~', '/purchasing/receipts', $this->journalEndpoint());
        if (! $endpoint || $endpoint === $this->journalEndpoint()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $response = $this->signedClient($event, $payload, $body, $endpoint)->withBody($body, 'application/json')->post($endpoint);

        return ['successful' => $response->successful(), 'status' => $response->status(), 'data' => (array) ($response->json('data') ?? [])];
    }

    /** Dedicated immutable sales documents; never treats their delivery as an accounting journal. */
    public function sendSalesDocument(\App\Models\Tenant\SalesDocumentOutbox $document): array
    {
        $this->safety->assertDeliveryEnabledFor((int) $document->organization_id);
        if ($document->status !== 'processing' || ! $document->lease_token
            || ! $document->lease_expires_at || $document->lease_expires_at->isPast()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $payload = $document->payload;
        if (($payload['schema_version'] ?? null) !== 'sales.v1'
            || ! in_array($payload['event_type'] ?? null, ['sales.shipment.confirmed', 'sales.shipment.reversed', 'sales.return.confirmed', 'sales.return.reversed'], true)
            || ($payload['external_source_key'] ?? null) !== $document->source_key
            || ($payload['event_uuid'] ?? null) !== $document->event_uuid
            || (int) ($payload['inventory_organization_id'] ?? 0) !== (int) $document->organization_id
            || ($payload['identity']['organization_mapping_uuid'] ?? null) !== $document->organization_mapping_uuid
            || ! hash_equals((string) $document->payload_hash, hash('sha256', SolaStockJournalContract::canonicalJson($payload)))) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $body = SolaStockJournalContract::canonicalJson($payload);
        $endpoint = preg_replace('~/journal-entries(?:\\?.*)?$~', '/sales/documents', $this->journalEndpoint());
        if (! $endpoint || $endpoint === $this->journalEndpoint()) {
            throw new RuntimeException(__('inventory.purchasing.connection_review_required'));
        }
        $event = new IntegrationOutboxEvent(['organization_id' => $document->organization_id, 'idempotency_key' => $document->source_key, 'event_uuid' => $document->event_uuid]);
        $response = $this->signedClient($event, $payload, $body, $endpoint)->withBody($body, 'application/json')->post($endpoint);

        return ['successful' => $response->successful(), 'status' => $response->status(), 'data' => (array) ($response->json('data') ?? [])];
    }

    public function rotateSigningKey(): IntegrationSetting
    {
        $orgId = $this->context->idOrFail();
        $setting = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->firstOrFail();
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $orgId)
            ->where('finance_organization_id', (int) $setting->solabooks_organization_id)
            ->where('central_client_id', (int) ($setting->meta['client_id'] ?? 0))
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->whereIn('status', ['verified_hold', 'verified'])
            ->whereIn('activation_state', ['maintenance_hold', 'active'])
            ->firstOrFail();
        $configuredCentralOrganizationId = (int) ($setting->meta['central_organization_id'] ?? 0);
        if ($configuredCentralOrganizationId > 0
            && $configuredCentralOrganizationId !== (int) $mapping->central_organization_id) {
            throw new RuntimeException(__('inventory.integration.contract_organization_invalid'));
        }
        if ($configuredCentralOrganizationId === 0) {
            $meta = (array) $setting->meta;
            $meta['central_organization_id'] = (int) $mapping->central_organization_id;
            $meta['integration_mapping_uuid'] = (string) $mapping->mapping_uuid;
            $setting->meta = $meta;
            $setting->save();
        }
        $response = $this->clientForProvisioning($setting)->post($this->signingEndpoint('rotate'), [
            'inventory_organization_id' => $orgId,
            'central_organization_id' => (int) ($setting->meta['central_organization_id'] ?? 0),
            'integration_mapping_id' => (int) $mapping->id,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: __('inventory.integration.rotation_failed'));
        }
        $data = $response->json('data') ?? [];
        if (empty($data['key_id']) || empty($data['secret']) || ($data['protocol_version'] ?? null) !== ExternalRequestSignature::VERSION
            || ($data['contract_version'] ?? null) !== SolaStockJournalContract::VERSION) {
            throw new RuntimeException(__('inventory.integration.invalid_signing_response'));
        }
        $meta = $setting->meta ?? [];
        $meta['signing_key_id'] = (string) $data['key_id'];
        $meta['signing_secret_encrypted'] = Crypt::encryptString((string) $data['secret']);
        $meta['signing_protocol_version'] = (string) $data['protocol_version'];
        $meta['contract_version'] = (string) $data['contract_version'];
        $meta['signing_key_rotated_at'] = now()->toIso8601String();
        $setting->meta = $meta;
        $setting->save();

        return $setting->fresh();
    }

    public function revokeSigningKey(string $keyId): void
    {
        $setting = IntegrationSetting::query()
            ->where('organization_id', $this->context->idOrFail())
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->firstOrFail();
        $response = $this->clientForProvisioning($setting)->post($this->signingEndpoint(rawurlencode($keyId).'/revoke'), [
            'inventory_organization_id' => $this->context->idOrFail(),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: __('inventory.integration.revocation_failed'));
        }
        $meta = $setting->meta ?? [];
        if (($meta['signing_key_id'] ?? null) === $keyId) {
            unset($meta['signing_key_id'], $meta['signing_secret_encrypted'], $meta['signing_protocol_version']);
            $meta['signing_key_revoked_at'] = now()->toIso8601String();
            $setting->meta = $meta;
            $setting->save();
        }
    }

    private function clientForProvisioning(IntegrationSetting $setting): PendingRequest
    {
        $apiKey = (string) ($setting->apiKey() ?: config('services.solabooks.api_key'));
        $clientId = (string) ($setting->meta['client_id'] ?? config('services.solabooks.client_id'));
        $financeOrg = (string) ($setting->solabooks_organization_id ?: config('services.solabooks.organization_id'));
        if ($apiKey === '' || $clientId === '' || $financeOrg === '') {
            throw new RuntimeException(__('inventory.integration.credentials_missing'));
        }

        return Http::acceptJson()->asJson()->timeout((int) config('services.solabooks.timeout', 10))->retry(0)->withHeaders([
            'X-API-Key' => $apiKey,
            'X-Client-Id' => $clientId,
            'X-Organization-Id' => $financeOrg,
        ]);
    }

    private function signingEndpoint(string $suffix): string
    {
        return rtrim((string) config('services.solabooks.api_base_url'), '/').'/external-signing-keys/'.$suffix;
    }

    private function journalEndpoint(): string
    {
        $endpoint = (string) config('services.solabooks.journal_entries_url');
        if ($endpoint !== '') {
            return $endpoint;
        }

        return rtrim((string) config('services.solabooks.api_base_url'), '/').'/journal-entries';
    }

    private function journalPayload(IntegrationOutboxEvent $event, int $orgId): array
    {
        if ($event->mapping_status !== 'complete' && $this->outbox->eventMappingsComplete($orgId, $event->event_type)) {
            $event->mapping_status = 'complete';
            $event->save();
        }
        if ($event->mapping_status !== 'complete') {
            throw new RuntimeException(__('inventory.integration.mappings_incomplete'));
        }

        $setting = IntegrationSetting::query()->where('organization_id', $orgId)->where('integration', IntegrationEvents::INTEGRATION)->first();
        if (! $setting || $setting->mode !== 'active') {
            throw new RuntimeException(__('inventory.integration.inactive'));
        }

        return $this->contracts->build($event);
    }

    public function preview(IntegrationOutboxEvent $event): array
    {
        return $this->contracts->build($event);
    }

    /**
     * Execute only the immutable remote exchange. The durable transport claims
     * and commits its lease before invoking this method and acknowledges in a
     * separate transaction afterwards, so HTTP is never inside a DB transaction.
     */
    public function sendClaimed(IntegrationOutboxEvent $event): array
    {
        $this->safety->assertDeliveryEnabledFor((int) $event->organization_id);
        if ($event->status !== 'processing' || ! $event->lease_token) {
            throw new RuntimeException('A current processing lease is required.');
        }
        if ($event->contract_version !== SolaStockJournalContract::VERSION) {
            throw new RuntimeException('Only solastock-journal.v2 may use the durable transport.');
        }
        $payload = $this->journalPayload($event, (int) $event->organization_id);
        $body = SolaStockJournalContract::canonicalJson($payload);
        $response = $this->signedClient($event, $payload, $body)
            ->withBody($body, 'application/json')
            ->post($this->journalEndpoint());

        return [
            'successful' => $response->successful(),
            'status' => $response->status(),
            'data' => (array) ($response->json('data') ?? []),
            'error_code' => $response->json('error.code'),
            'safe_error' => mb_substr((string) (
                $response->json('error.message') ?: $response->json('message') ?: 'Remote request rejected.'
            ), 0, 500),
            'retry_after' => $response->header('Retry-After'),
        ];
    }

    private function markFailed(IntegrationOutboxEvent $event, string $message): void
    {
        $delaySeconds = min(3600, 60 * (2 ** max(0, min(5, (int) $event->attempts - 1))));
        $event->status = 'failed';
        $event->last_error = $message;
        $event->next_attempt_at = now()->addSeconds($delaySeconds);
        if ((int) $event->attempts >= 5) {
            $event->dead_lettered_at = now();
        }
        $event->save();

        IntegrationSetting::query()->updateOrCreate(
            ['organization_id' => $event->organization_id, 'integration' => IntegrationEvents::INTEGRATION],
            ['last_error' => $message]
        );
    }
}
