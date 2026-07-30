<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OutboxStateMachine
{
    public const TRANSITIONS = [
        'pending' => ['review_required', 'blocked_mapping', 'blocked_contract', 'ready', 'ignored', 'superseded'],
        'review_required' => ['blocked_mapping', 'blocked_contract', 'ready', 'ignored', 'superseded'],
        'blocked_mapping' => ['review_required', 'ready', 'ignored', 'superseded'],
        'blocked_contract' => ['review_required', 'ready', 'ignored', 'superseded'],
        'ready' => ['processing', 'review_required', 'blocked_mapping', 'blocked_contract', 'superseded'],
        'processing' => ['sent', 'retry_scheduled', 'failed', 'dead_letter', 'ready'],
        'retry_scheduled' => ['processing', 'review_required', 'blocked_mapping', 'blocked_contract', 'dead_letter'],
        'failed' => ['review_required', 'dead_letter'],
        'dead_letter' => ['review_required'],
        'sent' => ['reversed'],
        'ignored' => [],
        'superseded' => [],
        'reversed' => [],
    ];

    public function transition(
        IntegrationOutboxEvent $event,
        string $to,
        string $reason,
        string $actorType = 'worker',
        ?int $actorUserId = null,
        array $safeMetadata = [],
        ?string $expectedLeaseToken = null,
    ): IntegrationOutboxEvent {
        $from = (string) $event->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuntimeException("Invalid outbox transition {$from} -> {$to}.");
        }
        if ($expectedLeaseToken !== null
            && ! hash_equals((string) $event->lease_token, $expectedLeaseToken)) {
            throw new RuntimeException('Stale lease acknowledgement rejected.');
        }
        $event->status = $to;
        $event->state_version = (int) $event->state_version + 1;
        $event->save();
        DB::connection('tenant')->table('integration_outbox_transition_audits')->insert([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'event_uuid' => $event->event_uuid,
            'from_status' => $from,
            'to_status' => $to,
            'state_version' => $event->state_version,
            // The capability-bearing token is never retained in audit history.
            'lease_token_hash' => $event->lease_token
                ? hash('sha256', (string) $event->lease_token)
                : null,
            'reason_code' => $reason,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'safe_metadata' => $safeMetadata === [] ? null : json_encode($safeMetadata),
            'created_at' => now(),
        ]);

        return $event;
    }
}
