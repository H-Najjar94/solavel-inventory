<?php

namespace App\Services\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationOutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recovery for ONE posted goods receipt whose grn.posted accounting event was
 * frozen under a superseded contract, failed permanently and was ignored by an
 * operator. Rebuilds that same event (same idempotency key, so Finance's
 * receipt draft, lifecycle mapping and settlement keep pointing at it) under
 * the current contract and makes it ready again. No stock movement is created.
 *
 * Safe to retry: refused unless the event is still `ignored` and Finance holds
 * no journal for its key; Finance itself de-duplicates on the same key.
 * The normal state machine keeps `ignored` terminal; this is the only exit and
 * it always writes a transition audit with the previous payload hash.
 */
final class IgnoredReceiptEventReissue
{
    public const REASON = 'reissued_after_superseded_contract';

    public function __construct(private EventPayloadBuilder $payloads) {}

    /** @return array<string,mixed> */
    public function reissue(int $organizationId, int $eventId, ?int $actorId, bool $apply): array
    {
        $db = DB::connection('tenant');
        $db->beginTransaction();
        try {
            $event = IntegrationOutboxEvent::query()->where('organization_id', $organizationId)->lockForUpdate()->find($eventId)
                ?? throw new RuntimeException('event_not_found');
            if ($event->event_type !== 'grn.posted') throw new RuntimeException('only_grn_posted');
            if ($event->status !== 'ignored') throw new RuntimeException('event_not_ignored');
            if ($db->table('journal_entries')->where('source_key', 'external-api:'.hash('sha256', (string) $event->idempotency_key))->exists()) {
                throw new RuntimeException('finance_journal_exists');
            }
            $receipt = GoodsReceipt::withoutGlobalScopes()->where('organization_id', $organizationId)->find($event->aggregate_id)
                ?? throw new RuntimeException('receipt_not_found');
            if ($receipt->status !== 'posted' || $receipt->reversal_id) throw new RuntimeException('receipt_not_posted');

            $previousHash = $event->payload_hash;
            $payload = $this->payloads->build('grn.posted', $receipt, 'goods_receipt', $receipt->grn_number, (string) $receipt->receipt_date, true);
            if (! IntegrationEvents::postsJournalForPayload('grn.posted', $payload)) throw new RuntimeException('payload_posts_no_journal');

            $event->forceFill([
                'payload' => $payload,
                'payload_hash' => hash('sha256', SolaStockJournalContract::canonicalJson($payload)),
                'contract_version' => SolaStockJournalContract::VERSION,
                'mapping_status' => 'complete', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null,
                'failure_category' => null, 'failure_code' => null, 'safe_error' => null, 'depends_on_event_uuid' => null,
                'status' => 'ready', 'transport_eligible_at' => now(), 'state_version' => (int) $event->state_version + 1,
            ])->save();
            $db->table('integration_outbox_transition_audits')->insert([
                'organization_id' => $organizationId, 'event_id' => $event->id, 'event_uuid' => $event->event_uuid,
                'from_status' => 'ignored', 'to_status' => 'ready', 'state_version' => $event->state_version,
                'lease_token_hash' => null, 'reason_code' => self::REASON, 'actor_type' => 'user', 'actor_user_id' => $actorId,
                'safe_metadata' => json_encode(['previous_payload_hash' => $previousHash, 'new_payload_hash' => $event->payload_hash]),
                'created_at' => now(),
            ]);
            app(WorkflowDocumentMappingService::class)->recordForEvent($event, $receipt);
            app(SolaStockJournalContractBuilder::class)->build($event->fresh());

            $result = ['event_id' => $event->id, 'idempotency_key' => $event->idempotency_key, 'receipt' => $receipt->grn_number,
                'value' => $payload['total_inventory_value_change'] ?? null, 'applied' => $apply];
            $apply ? $db->commit() : $db->rollBack();

            return $result;
        } catch (\Throwable $e) {
            if ($db->transactionLevel() > 0) $db->rollBack();
            throw $e;
        }
    }
}
