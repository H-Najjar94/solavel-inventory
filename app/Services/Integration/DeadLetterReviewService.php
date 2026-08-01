<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DeadLetterReviewService
{
    public function __construct(
        private readonly IntegrationSafetyHold $safety,
        private readonly OutboxStateMachine $states,
    ) {}

    public function review(
        IntegrationOutboxEvent $event,
        int $reviewerId,
        string $note,
        bool $retry,
    ): IntegrationOutboxEvent {
        $this->safety->assertDeliveryEnabledFor((int) $event->organization_id);
        if (! $this->safety->workerEnabledFor((int) $event->organization_id)) {
            throw new RuntimeException('Durable transport remains disabled.');
        }
        if ($event->status !== 'dead_letter') {
            throw new RuntimeException('Only dead-letter events may enter reviewed recovery.');
        }
        if ($retry && ($event->mapping_status !== 'complete' || $event->failure_category === 'business_permanent')) {
            throw new RuntimeException('The permanent blocker must be resolved before retry.');
        }

        return DB::connection('tenant')->transaction(function () use ($event, $reviewerId, $note, $retry) {
            $event = IntegrationOutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
            $before = $event->status;
            $this->states->transition(
                $event,
                'review_required',
                $retry ? 'reviewed_for_retry' : 'reviewed_without_retry',
                'user',
                $reviewerId,
                ['retry_requested' => $retry],
            );
            if ($retry) {
                $event->next_attempt_at = now();
                $event->transport_eligible_at ??= now();
                $this->states->transition($event, 'ready', 'review_blocker_resolved', 'user', $reviewerId);
            }
            DB::connection('tenant')->table('integration_dead_letter_reviews')->insert([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                'event_uuid' => $event->event_uuid,
                'action' => $retry ? 'review_and_retry' : 'mark_reviewed',
                'required_recovery_action' => $event->failure_code,
                'before_status' => $before,
                'after_status' => $event->status,
                'reviewer_user_id' => $reviewerId,
                'review_note' => mb_substr($note, 0, 500),
                'reviewed_at' => now(),
                'event_payload_hash' => $event->payload_hash
                    ?: hash('sha256', SolaStockJournalContract::canonicalJson((array) $event->payload)),
            ]);

            return $event->fresh();
        });
    }
}
