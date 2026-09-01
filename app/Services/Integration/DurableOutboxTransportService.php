<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationSetting;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DurableOutboxTransportService
{
    public function __construct(
        private readonly IntegrationSafetyHold $safety,
        private readonly OrganizationContext $organizations,
        private readonly OutboxStateMachine $states,
        private readonly OutboxFailureClassifier $failures,
        private readonly SolaBooksOutboxDeliveryService $delivery,
        private readonly ApprovedFinanceIntegrationEntitlement $commercialEntitlement,
    ) {}

    public function claim(int $organizationId, string $workerId): ?IntegrationOutboxEvent
    {
        // No lock or mutation may occur before both independent worker/delivery
        // controls pass.
        $this->assertExecutionEnabled($organizationId);

        return $this->organizations->runFor($organizationId, function () use ($organizationId, $workerId) {
            $enabledWorkflows = $this->assertOrganizationEnabled($organizationId);

            return DB::connection('tenant')->transaction(function () use ($organizationId, $workerId, $enabledWorkflows) {
                $now = now();
                $event = IntegrationOutboxEvent::query()
                    ->where('organization_id', $organizationId)
                    ->where('integration', IntegrationEvents::INTEGRATION)
                    ->where('contract_version', SolaStockJournalContract::VERSION)
                    ->where('mapping_status', 'complete')
                    ->whereIn('workflow_key', $enabledWorkflows)
                    ->whereNotNull('transport_eligible_at')
                    ->where(function ($query) use ($now): void {
                        $query->where('status', 'ready')
                            ->orWhere(function ($query) use ($now): void {
                                $query->where('status', 'retry_scheduled')
                                    ->where('next_attempt_at', '<=', $now);
                            });
                    })
                    ->where(function ($query): void {
                        $query->whereNull('depends_on_event_uuid')
                            ->orWhereExists(function ($query): void {
                                $query->selectRaw('1')
                                    ->from('integration_outbox_events as parent')
                                    ->whereColumn('parent.event_uuid', 'integration_outbox_events.depends_on_event_uuid')
                                    ->whereIn('parent.status', ['sent', 'reversed']);
                            });
                    })
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('integration_outbox_events as earlier')
                            ->whereColumn('earlier.organization_id', 'integration_outbox_events.organization_id')
                            ->whereColumn('earlier.ordering_key', 'integration_outbox_events.ordering_key')
                            ->whereColumn('earlier.id', '<', 'integration_outbox_events.id')
                            ->whereNotIn('earlier.status', ['sent', 'ignored', 'superseded', 'reversed']);
                    })
                    ->orderBy('id')
                    // MariaDB installations in the supported fleet do not all
                    // implement SKIP LOCKED. The claim transaction is tiny and
                    // contains no HTTP, so a regular row lock safely serializes
                    // only competing claims for the same first eligible row.
                    ->lockForUpdate()
                    ->first();
                if (! $event) {
                    return null;
                }
                $token = (string) Str::uuid();
                $event->lease_owner = mb_substr($workerId, 0, 120);
                $event->lease_token = $token;
                $event->claimed_at = $now;
                $event->lease_expires_at = $now->copy()->addSeconds((int) config('integration_transport.lease_seconds'));
                $event->attempts = (int) $event->attempts + 1;
                $this->states->transition($event, 'processing', 'worker_claimed', safeMetadata: [
                    'lease_seconds' => (int) config('integration_transport.lease_seconds'),
                ]);

                return $event->fresh();
            });
        });
    }

    public function processClaim(IntegrationOutboxEvent $claimed): array
    {
        $this->assertExecutionEnabled((int) $claimed->organization_id);
        $organizationId = (int) $claimed->organization_id;
        $token = (string) $claimed->lease_token;

        return $this->organizations->runFor($organizationId, function () use ($claimed, $token): array {
            $current = IntegrationOutboxEvent::query()->findOrFail($claimed->id);
            $this->assertCurrentLease($current, $token);
            $rawHash = hash('sha256', SolaStockJournalContract::canonicalJson((array) $current->payload));
            if ($current->payload_hash && ! hash_equals((string) $current->payload_hash, $rawHash)) {
                return $this->ackFailure($current->id, $token, [
                    'retryable' => false,
                    'category' => 'business_permanent',
                    'code' => 'altered_idempotency_payload',
                    'safe_error' => 'The event payload changed after transport eligibility.',
                ]);
            }

            try {
                // Deliberately outside every DB transaction.
                $response = $this->delivery->sendClaimed($current);
                if ($response['successful']) {
                    return $this->ackSuccess($current->id, $token, $response, $rawHash);
                }

                return $this->ackFailure(
                    $current->id,
                    $token,
                    $this->failures->classify(
                        (int) $response['status'],
                        $response['error_code'],
                        $response['safe_error']
                    ),
                    $response['retry_after'] ?? null,
                );
            } catch (Throwable $e) {
                return $this->ackFailure(
                    $current->id,
                    $token,
                    $this->failures->classify(null, null, $e)
                );
            }
        });
    }

    public function recoverExpiredLeases(int $organizationId): int
    {
        $this->assertExecutionEnabled($organizationId);

        return $this->organizations->runFor($organizationId, function () use ($organizationId): int {
            return DB::connection('tenant')->transaction(function () use ($organizationId): int {
                $events = IntegrationOutboxEvent::query()
                    ->where('organization_id', $organizationId)
                    ->where('status', 'processing')
                    ->where('lease_expires_at', '<', now()->subSeconds(
                        (int) config('integration_transport.clock_tolerance_seconds')
                    ))
                    ->lockForUpdate()->get();
                foreach ($events as $event) {
                    $expiredToken = (string) $event->lease_token;
                    $event->next_attempt_at = now();
                    $this->states->transition($event, 'ready', 'lease_expired', safeMetadata: [
                        'expired_lease_token_hash' => $expiredToken === ''
                            ? null : hash('sha256', $expiredToken),
                    ]);
                    $this->clearLease($event);
                    $event->save();
                }

                return $events->count();
            });
        });
    }

    private function ackSuccess(int $eventId, string $token, array $response, string $payloadHash): array
    {
        return DB::connection('tenant')->transaction(function () use ($eventId, $token, $response, $payloadHash): array {
            $event = IntegrationOutboxEvent::query()->lockForUpdate()->findOrFail($eventId);
            $this->assertCurrentLease($event, $token);
            $event->payload_hash = $payloadHash;
            $event->external_document_id = isset($response['data']['id']) ? (string) $response['data']['id'] : null;
            $event->external_response = [
                'id' => $event->external_document_id,
                'status' => (int) $response['status'],
            ];
            $event->sent_at = now();
            $event->next_attempt_at = null;
            $event->failure_category = null;
            $event->failure_code = null;
            $event->safe_error = null;
            $event->last_error = null;
            $this->states->transition($event, 'sent', 'finance_result_durable', expectedLeaseToken: $token);
            $this->clearLease($event);
            $event->save();

            return ['status' => 'sent', 'event_id' => $event->id, 'finance_id' => $event->external_document_id];
        });
    }

    private function ackFailure(int $eventId, string $token, array $failure, ?string $retryAfter = null): array
    {
        return DB::connection('tenant')->transaction(function () use ($eventId, $token, $failure, $retryAfter): array {
            $event = IntegrationOutboxEvent::query()->lockForUpdate()->findOrFail($eventId);
            $this->assertCurrentLease($event, $token);
            $event->failure_category = $failure['category'];
            $event->failure_code = $failure['code'];
            $event->safe_error = $failure['safe_error'];
            $event->last_error = $failure['safe_error'];
            $event->first_failed_at ??= now();
            $event->last_failed_at = now();
            $max = (int) config('integration_transport.max_attempts');
            if (! $failure['retryable']) {
                $to = 'failed';
                $event->next_attempt_at = null;
            } elseif ((int) $event->attempts >= $max) {
                $to = 'dead_letter';
                $event->dead_lettered_at = now();
                $event->next_attempt_at = null;
            } else {
                $to = 'retry_scheduled';
                $event->next_attempt_at = now()->addSeconds(
                    $this->backoffSeconds((int) $event->attempts, $retryAfter)
                );
            }
            $this->states->transition($event, $to, $failure['code'], expectedLeaseToken: $token, safeMetadata: [
                'failure_category' => $failure['category'],
                'retryable' => (bool) $failure['retryable'],
            ]);
            $this->clearLease($event);
            $event->save();

            return ['status' => $to, 'event_id' => $event->id, 'failure_code' => $failure['code']];
        });
    }

    private function assertExecutionEnabled(int $organizationId): void
    {
        $this->safety->assertDeliveryEnabledFor($organizationId);
        if (! $this->safety->workerEnabledFor($organizationId)) {
            throw new RuntimeException('Dedicated SolaStock Finance v2 worker is disabled.');
        }
        if (config('integration_transport.contract_version') !== SolaStockJournalContract::VERSION) {
            throw new RuntimeException('Transport contract configuration is inconsistent.');
        }
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $organizationId)
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->where('status', 'verified')
            ->where('activation_state', 'active')
            ->first();
        if (! $mapping) {
            throw new RuntimeException('Verified immutable organization mapping is required.');
        }
        $this->commercialEntitlement->assertApproved($mapping);
    }

    private function assertOrganizationEnabled(int $organizationId): array
    {
        $setting = IntegrationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('integration', IntegrationEvents::INTEGRATION)->first();
        $workflows = (array) data_get($setting?->meta, 'transport_enabled_workflows', []);
        if (! $setting || $setting->mode !== 'active'
            || data_get($setting->meta, 'transport_enabled') !== true
            || $workflows === []) {
            throw new RuntimeException('Organization transport is not explicitly enabled.');
        }
        return $workflows;
    }

    private function assertCurrentLease(IntegrationOutboxEvent $event, string $token): void
    {
        if ($event->status !== 'processing'
            || ! $event->lease_token
            || ! hash_equals((string) $event->lease_token, $token)
            || ! $event->lease_expires_at
            || $event->lease_expires_at->lt(now()->subSeconds(
                (int) config('integration_transport.clock_tolerance_seconds')
            ))) {
            throw new RuntimeException('Stale or expired worker acknowledgement rejected.');
        }
    }

    private function clearLease(IntegrationOutboxEvent $event): void
    {
        $event->lease_owner = null;
        $event->lease_token = null;
        $event->claimed_at = null;
        $event->lease_expires_at = null;
    }

    private function backoffSeconds(int $attempt, ?string $retryAfter): int
    {
        $base = (int) config('integration_transport.base_backoff_seconds');
        $max = (int) config('integration_transport.max_backoff_seconds');
        $delay = min($max, $base * (2 ** max(0, min(20, $attempt - 1))));
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $delay = max($delay, min($max, (int) $retryAfter));
        }
        $jitter = (int) floor($delay * ((int) config('integration_transport.jitter_percent') / 100));
        if ($jitter > 0) {
            $delay += random_int(-$jitter, $jitter);
        }

        return max(1, $delay);
    }

    public function queueReviewedRetry(IntegrationOutboxEvent $event, int $reviewerId): IntegrationOutboxEvent
    {
        $this->assertExecutionEnabled((int) $event->organization_id);
        if ($event->mapping_status !== 'complete'
            || $event->failure_category === 'business_permanent'
            || ! in_array($event->status, ['failed', 'retry_scheduled', 'review_required'], true)) {
            throw new RuntimeException('This event is not eligible for reviewed retry.');
        }

        return DB::connection('tenant')->transaction(function () use ($event, $reviewerId) {
            $event = IntegrationOutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($event->status !== 'review_required') {
                $this->states->transition($event, 'review_required', 'manual_retry_reviewed', 'user', $reviewerId);
            }
            $event->transport_eligible_at ??= now();
            $event->next_attempt_at = now();
            $this->states->transition($event, 'ready', 'manual_retry_queued', 'user', $reviewerId);

            return $event->fresh();
        });
    }
}
