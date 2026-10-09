<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Records SolaBooks integration events into the local outbox. It NEVER sends
 * externally inside stock transactions. Connected valuation contracts are validated
 * before the physical transaction commits. Delivery
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

        $mode = $this->mode($orgId);
        $hasOwnership = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $orgId)
            ->where('tenant_database_identity', \DB::connection('tenant')->getDatabaseName())->exists();
        if ($mode === 'disconnected' && ! $hasOwnership) {
            // A native standalone receipt has no Finance job. Historical events
            // above remain recoverable; an existing connection identity retains
            // ownership even while disconnected and must never silently fall back.
            return null;
        }

        $mappingComplete = $this->coreMappingsComplete($orgId, $eventType);
        $payload = $this->payloads->build($eventType, $document, $documentType, $number, $date, $mappingComplete);

        // If integration is disconnected, still record — status reflects the mode.
        $mode = $this->mode($orgId);
        $postsJournal = IntegrationEvents::postsJournalForPayload($eventType, $payload);
        $transportEligible = $postsJournal && $mappingComplete
            && $this->transportEnabled($orgId, $eventType);
        $status = match (true) {
            ! $postsJournal => 'ignored',
            $transportEligible => 'ready',
            // Creation never guesses whether an unresolved mapping is
            // permanent. A reviewed promotion classifies it later; historical
            // pending rows are deliberately untouched by Phase 4.
            default => 'pending',
        };
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
            'contract_version' => $postsJournal ? SolaStockJournalContract::VERSION : null,
            'payload_hash' => $postsJournal
                ? hash('sha256', SolaStockJournalContract::canonicalJson($payload))
                : null,
            'workflow_key' => $eventType,
            'ordering_key' => $aggregateType.':'.(int) $document->id,
            'depends_on_event_uuid' => data_get($payload, 'original_source.event_uuid'),
            'transport_eligible_at' => $transportEligible ? now() : null,
        ]);
        $this->workflowDocuments->recordForEvent($event, $document);
        $this->workflowDocuments->recordReservationsForSalesOrder($event, $document);
        if ($postsJournal && ($payload['inventory_valuation_basis'] ?? null) === FinanceBaseValuation::BASIS) {
            // Pure local contract construction: no delivery or accounting write.
            // An unrepresentable currency amount rolls back the surrounding
            // document/physical movement transaction instead of stranding it.
            app(SolaStockJournalContractBuilder::class)->build($event);
        }

        return $event;
    }

    private function mode(int $orgId): string
    {
        return (string) (IntegrationSetting::query()->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->value('mode') ?? 'disconnected');
    }

    private function transportEnabled(int $orgId, string $eventType): bool
    {
        $safety = app(IntegrationSafetyHold::class);
        if (! $safety->deliveryEnabledFor($orgId)
            || ! $safety->workerEnabledFor($orgId)) {
            return false;
        }
        $setting = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->first();
        $enabled = (array) data_get($setting?->meta, 'transport_enabled_workflows', []);

        return $setting?->mode === 'active'
            && data_get($setting?->meta, 'transport_enabled') === true
            && in_array($eventType, $enabled, true);
    }

    public function refreshMappingStatus(int $orgId): void
    {
        IntegrationOutboxEvent::query()->where('integration', IntegrationEvents::INTEGRATION)
            ->where('organization_id', $orgId)->whereIn('status', ['pending', 'failed'])
            ->chunkById(200, function ($events) use ($orgId) {
                foreach ($events as $event) {
                    $event->update(['mapping_status' => $this->coreMappingsComplete($orgId, $event->event_type) ? 'complete' : 'incomplete']);
                }
            });
        // A mapping change can make post-activation pending journals deliverable.
        $this->promoteEligiblePending($orgId);
    }

    /**
     * Move journal events that were recorded while transport was not eligible
     * (paused, workflow not yet enabled, account roles not yet mapped, catalog
     * not yet mapped) from `pending` to `ready` once they can be delivered.
     *
     * - Only for a verified, active v2 connection whose transport is enabled
     *   for the event's workflow (same rules as record()).
     * - Never touches events from before the connection's activation baseline
     *   (first wizard activation, else the mapping's verification time): those
     *   are historical and were reconciled by the connection wizard cutoff.
     * - Dependency ordering: the local journal contract must build, which
     *   requires the item/unit catalog and account-role mappings first; the
     *   supervisor runs party and catalog sync before this step, and the claim
     *   still honours ordering_key and depends_on_event_uuid.
     * - Bounded: examines at most $limit rows per call, resuming from a cursor
     *   so rows that are still not deliverable cannot starve later rows.
     * - Never edits the payload (its hash is the idempotency contract).
     *
     * @return array{examined:int,promoted:int}
     */
    public function promoteEligiblePending(int $orgId, int $limit = 100): array
    {
        $result = ['examined' => 0, 'promoted' => 0];
        $database = (string) DB::connection('tenant')->getDatabaseName();
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $orgId)
            ->where('tenant_database_identity', $database)
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->where('status', 'verified')->where('activation_state', 'active')
            ->first();
        $baseline = $mapping ? $this->activationBaseline($mapping) : null;
        if (! $mapping || $baseline === null || $this->mode($orgId) !== 'active') {
            return $result;
        }
        $cursorKey = 'outbox-pending-promotion:'.hash('sha256', $database.'|'.$mapping->mapping_uuid);
        $cursor = (int) Cache::store('file')->get($cursorKey, 0);
        $limit = min(500, max(1, $limit));
        $candidates = IntegrationOutboxEvent::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->where('status', 'pending')
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->where('occurred_at', '>=', $baseline)
            ->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($candidates as $id) {
            $result['examined']++;
            if ($this->promoteOne($orgId, (int) $id)) {
                $result['promoted']++;
            }
        }
        Cache::store('file')->forever($cursorKey, $candidates->count() === $limit ? (int) $candidates->last() : 0);

        return $result;
    }

    /** First activation of this connection identity (same rule as party sync). */
    public function activationBaseline(IntegrationOrganizationMapping $mapping): ?string
    {
        $activated = Schema::connection('tenant')->hasTable('integration_connection_wizard_runs')
            ? DB::connection('tenant')->table('integration_connection_wizard_runs')
                ->where('organization_mapping_uuid', $mapping->mapping_uuid)
                ->whereNotNull('activated_at')->min('activated_at')
            : null;
        $baseline = $activated ?? $mapping->verified_at;

        return $baseline === null ? null : \Illuminate\Support\Carbon::parse($baseline)->toDateTimeString();
    }

    private function promoteOne(int $orgId, int $id): bool
    {
        return DB::connection('tenant')->transaction(function () use ($orgId, $id): bool {
            $event = IntegrationOutboxEvent::query()->lockForUpdate()->find($id);
            if (! $event || $event->status !== 'pending'
                || ! IntegrationEvents::postsJournalForPayload((string) $event->event_type, (array) $event->payload)
                || ! $this->transportEnabled($orgId, (string) $event->event_type)) {
                return false;
            }
            if (! $this->coreMappingsComplete($orgId, (string) $event->event_type)) {
                if ($event->mapping_status !== 'incomplete') {
                    $event->update(['mapping_status' => 'incomplete']);
                }

                return false;
            }
            try {
                // Local only: proves the account roles, catalog identities,
                // currency and document mapping exist before it becomes claimable.
                app(SolaStockJournalContractBuilder::class)->build($event);
            } catch (\Throwable) {
                return false;
            }
            $event->mapping_status = 'complete';
            $event->transport_eligible_at = now();
            $event->next_attempt_at = now();
            app(OutboxStateMachine::class)->transition($event, 'ready', 'pending_promoted_after_activation', 'system');

            return true;
        });
    }

    public function eventMappingsComplete(int $orgId, ?string $eventType = null): bool
    {
        return $this->coreMappingsComplete($orgId, $eventType);
    }

    /** The core account mappings needed for any posting to be "complete". */
    private function coreMappingsComplete(int $orgId, ?string $eventType = null): bool
    {
        $required = $eventType !== null ? AccountRolePolicy::forOperations([$eventType]) : app(OrganizationAccountRequirements::class)->roles($orgId);
        $mapped = app(OrganizationAccountRequirements::class)->validMappedRoles($orgId);

        return count(array_intersect($required, $mapped)) === count($required);
    }
}
