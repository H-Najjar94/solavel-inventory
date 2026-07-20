<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Client\PendingRequest;
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
                throw new RuntimeException('Ignored integration events cannot be delivered.');
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
                $response = $this->client($event)->post($this->journalEndpoint(), $payload);

                if (! $response->successful()) {
                    throw new RuntimeException($response->json('error.message') ?: 'SolaBooks rejected the journal event.');
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
            throw new RuntimeException('SolaBooks API credentials are not configured.');
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
            throw new RuntimeException('SolaBooks mappings are incomplete for this event.');
        }

        $setting = IntegrationSetting::query()->where('organization_id', $orgId)->where('integration', IntegrationEvents::INTEGRATION)->first();
        if (! $setting || $setting->mode !== 'active') {
            throw new RuntimeException('SolaBooks integration is not active.');
        }

        $payload = $event->payload ?? [];

        return [
            'date' => $payload['document_date'] ?: now()->toDateString(),
            'reference' => $event->idempotency_key,
            'description' => trim('SolaStock '.$event->event_type.' '.$event->aggregate_number),
            'source_app' => 'solastock',
            'source_type' => $event->event_type,
            'source_id' => $event->aggregate_id,
            'source_number' => $event->aggregate_number,
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
