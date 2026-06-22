<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Str;

/**
 * Records SolaBooks integration events into the local outbox. NEVER sends
 * externally (a future worker drains the outbox) and NEVER blocks stock posting.
 * Called from inside document-posting transactions so the event is durable iff
 * the post commits. Idempotent: re-posting a document does not duplicate events.
 */
class IntegrationOutboxService
{
    public function __construct(
        private OrganizationContext $context,
        private EventPayloadBuilder $payloads,
    ) {}

    /**
     * Record an event for a posted/reversed document. Safe to call within the
     * post transaction. Returns the event (or the existing one on idempotent retry).
     */
    public function record(string $eventType, object $document, string $documentType, ?string $number = null, ?string $date = null): ?IntegrationOutboxEvent
    {
        if (! IntegrationEvents::exists($eventType)) {
            return null;
        }

        $orgId = $this->context->idOrFail();
        $aggregateType = IntegrationEvents::aggregateType($eventType);
        $idem = IntegrationEvents::idempotencyKey($eventType, $aggregateType, (int) $document->id);

        // Idempotent: if already recorded, return it.
        $existing = IntegrationOutboxEvent::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->where('idempotency_key', $idem)->first();
        if ($existing) {
            return $existing;
        }

        $mappingComplete = $this->coreMappingsComplete($orgId);
        $payload = $this->payloads->build($eventType, $document, $documentType, $number, $date, $mappingComplete);

        // If integration is disconnected, still record — status reflects the mode.
        $mode = $this->mode($orgId);
        $status = $mode === 'disconnected' ? 'ignored' : 'pending';

        return IntegrationOutboxEvent::create([
            'organization_id' => $orgId,
            'event_uuid' => (string) Str::uuid(),
            'integration' => IntegrationEvents::INTEGRATION,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (int) $document->id,
            'aggregate_number' => $number,
            'occurred_at' => now(),
            'payload' => $payload,
            'status' => $status,
            'mapping_status' => $mappingComplete ? 'complete' : 'incomplete',
            'attempts' => 0,
            'idempotency_key' => $idem,
        ]);
    }

    private function mode(int $orgId): string
    {
        return (string) (IntegrationSetting::query()->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->value('mode') ?? 'disconnected');
    }

    /** The core account mappings needed for any posting to be "complete". */
    private function coreMappingsComplete(int $orgId): bool
    {
        $required = ['inventory_asset', 'cogs', 'adjustment_gain', 'adjustment_loss', 'grni', 'opening_offset'];
        $mapped = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('mapping_type', $required)
            ->whereIn('status', ['mapped', 'verified'])
            ->pluck('mapping_type')->all();

        return count(array_intersect($required, $mapped)) === count($required);
    }
}
