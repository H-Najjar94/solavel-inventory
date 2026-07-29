<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SolaBooksOutboxDeliveryService
{
    public function __construct(
        private OrganizationContext $context,
        private AccountingJournalBuilder $journals,
        private IntegrationOutboxService $outbox,
    ) {}

    public function deliver(IntegrationOutboxEvent $event, bool $manual = false): IntegrationOutboxEvent
    {
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
                $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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

    private function signedClient(IntegrationOutboxEvent $event, array $payload, string $body): PendingRequest
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

        $endpoint = $this->journalEndpoint();
        $path = (string) (parse_url($endpoint, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($endpoint, PHP_URL_QUERY) ?: '');
        $timestamp = (string) now()->timestamp;
        $nonce = ExternalRequestSignature::nonce();
        $contentHash = ExternalRequestSignature::bodyHash($body);
        $inventoryOrg = (string) $this->context->idOrFail();
        $financeOrg = (string) $setting->solabooks_organization_id;
        $sourceKey = (string) $payload['external_source_key'];
        $eventType = (string) $payload['event_type'];
        $canonical = ExternalRequestSignature::canonicalString(
            'POST', $path, $query, 'application/json', $timestamp, $nonce, $contentHash,
            $inventoryOrg, $financeOrg, $sourceKey, $eventType, $version,
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
        ]);
    }

    public function rotateSigningKey(): IntegrationSetting
    {
        $orgId = $this->context->idOrFail();
        $setting = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->firstOrFail();
        $response = $this->clientForProvisioning($setting)->post($this->signingEndpoint('rotate'), [
            'inventory_organization_id' => $orgId,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: __('inventory.integration.rotation_failed'));
        }
        $data = $response->json('data') ?? [];
        if (empty($data['key_id']) || empty($data['secret']) || ($data['protocol_version'] ?? null) !== ExternalRequestSignature::VERSION) {
            throw new RuntimeException(__('inventory.integration.invalid_signing_response'));
        }
        $meta = $setting->meta ?? [];
        $meta['signing_key_id'] = (string) $data['key_id'];
        $meta['signing_secret_encrypted'] = Crypt::encryptString((string) $data['secret']);
        $meta['signing_protocol_version'] = (string) $data['protocol_version'];
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
        if ($event->mapping_status !== 'complete' && $this->outbox->eventMappingsComplete($orgId)) {
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

        $payload = $event->payload ?? [];
        $originalSource = $payload['original_source'] ?? null;
        if (is_array($originalSource) && ! empty($originalSource['event_uuid'])) {
            $originalSource['external_source_key'] = IntegrationOutboxEvent::query()
                ->where('event_uuid', $originalSource['event_uuid'])
                ->value('idempotency_key');
        }

        return [
            'date' => $payload['document_date'] ?: now()->toDateString(),
            'reference' => $event->idempotency_key,
            'description' => trim('SolaStock '.$event->event_type.' '.$event->aggregate_number),
            'source_app' => 'solastock',
            'source_type' => $event->event_type,
            'source_id' => $event->aggregate_id,
            'source_number' => $event->aggregate_number,
            'inventory_organization_id' => $orgId,
            'finance_organization_id' => (int) $setting->solabooks_organization_id,
            'external_source_key' => $event->idempotency_key,
            'event_type' => $event->event_type,
            'original_source' => $originalSource,
            'lines' => $this->journals->build($event, $orgId),
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
