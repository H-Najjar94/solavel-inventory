<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Str;

/**
 * Records SolaBooks integration events into the local outbox. It NEVER sends
 * externally inside stock transactions and NEVER blocks stock posting. Delivery
 * happens later through the retry/worker path, over the SolaBooks API only.
 * Idempotent: re-posting a document does not duplicate events.
 */
class IntegrationOutboxService
{
    public function __construct(
        private OrganizationContext $context,
        private EventPayloadBuilder $payloads,
        private WorkflowDocumentMappingService $workflowDocuments,
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
        $postsJournal = IntegrationEvents::postsJournalForPayload($eventType, $payload);
        $status = $mode === 'disconnected' || ! $postsJournal ? 'ignored' : 'pending';
        if (! $postsJournal) {
            $payload['accounting_policy'] = $eventType === 'transfer.posted'
                ? 'no_journal_same_entity_inventory_transfer'
                : 'operational_event_no_journal';
        }

        $event = IntegrationOutboxEvent::create([
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
        $this->workflowDocuments->recordForEvent($event, $document);
        $this->workflowDocuments->recordReservationsForSalesOrder($event, $document);

        return $event;
    }

    private function mode(int $orgId): string
    {
        return (string) (IntegrationSetting::query()->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->value('mode') ?? 'disconnected');
    }

    public function refreshMappingStatus(int $orgId): void
    {
        IntegrationOutboxEvent::query()
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('status', ['pending', 'failed'])
            ->update(['mapping_status' => $this->coreMappingsComplete($orgId) ? 'complete' : 'incomplete']);
    }

    public function eventMappingsComplete(int $orgId): bool
    {
        return $this->coreMappingsComplete($orgId);
    }

    /** The core account mappings needed for any posting to be "complete". */
    private function coreMappingsComplete(int $orgId): bool
    {
        $required = ['inventory_asset', 'cogs', 'adjustment_gain', 'adjustment_loss', 'grni', 'opening_offset', 'accounts_receivable', 'sales_revenue'];
        $mapped = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('mapping_type', $required)
            ->whereIn('status', ['mapped', 'verified'])
            ->pluck('mapping_type')->all();

        return count(array_intersect($required, $mapped)) === count($required);
    }
}
