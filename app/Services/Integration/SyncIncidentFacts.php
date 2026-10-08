<?php
namespace App\Services\Integration;

/**
 * Safe persisted projection of a party/catalog sync incident (contract stock-sync-incident.v1).
 *
 * Carried through the shared integration_document_notification_outbox with document_kind
 * party|item|unit|category and document_outbox_id = the sync-state row id. Only fixed reason
 * codes, ids and a sanitized display name are exposed; never remote text, payloads or secrets.
 * Attempt counters, timestamps and state_version are deliberately excluded so retries do not
 * create a new alert identity. `episode` increments only after a resolved incident re-opens.
 */
final class SyncIncidentFacts
{
    public const VERSION = 'stock-sync-incident.v1';

    public const KINDS = ['party', 'item', 'unit', 'category'];

    public const REASONS = [
        'party_identity_review_required', 'party_source_unavailable', 'party_approval_required', 'party_retry_exhausted',
        'catalog_identity_conflict', 'catalog_field_conflict', 'catalog_shared_reference_change', 'catalog_source_actor_required',
        'catalog_source_changed', 'catalog_source_not_authorized', 'retry_exhausted', 'sync_review_required',
    ];

    /** Party reasons that need a person even while the state is still `held` (retrying). */
    private const PARTY_HELD_INTERVENTION = ['party_approval_required'];

    public static function table(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('sync_incident_kind_invalid');
        }

        return $kind === 'party' ? 'integration_party_sync_states' : 'integration_catalog_sync_states';
    }

    /** intervention | resolved | pending (not notifiable) for the current state row. */
    public static function status(string $kind, object $row): string
    {
        if ($kind === 'party') {
            $status = (string) ($row->status ?? '');
            if ($status === 'intervention') {
                return 'intervention';
            }
            if ($status === 'held' && in_array((string) ($row->last_error ?? ''), self::PARTY_HELD_INTERVENTION, true)) {
                return 'intervention';
            }

            return $status === 'synced' ? 'resolved' : 'pending';
        }
        $state = (string) ($row->state ?? '');

        return match ($state) {
            'intervention_required' => 'intervention',
            'delivered' => 'resolved',
            default => 'pending',
        };
    }

    public static function reason(string $kind, object $row): string
    {
        $code = (string) ($row->last_error ?? '');
        if (in_array($code, self::REASONS, true)) {
            return $code;
        }
        if ($kind === 'party') {
            return 'party_retry_exhausted';
        }

        // A catalog row only reaches intervention_required for a known terminal code or after
        // exhausting its attempts with a transient code.
        return in_array($code, ['catalog_projection_delivery_failed', 'catalog_dependency_pending', 'catalog_projection_ack_invalid', 'finance_connection_transport_unknown_retry_same_key'], true)
            ? 'retry_exhausted' : 'sync_review_required';
    }

    /**
     * @param  int  $episode  1-based incident episode for this state row
     */
    public static function fromRow(string $kind, object $row, string $mappingUuid, int $episode): array
    {
        self::table($kind);
        if ($kind === 'party') {
            $entity = (string) ($row->entity_type ?? '');
            if (! in_array($entity, ['supplier', 'customer'], true)) {
                throw new \InvalidArgumentException('sync_incident_entity_invalid');
            }
            $fields = json_decode((string) ($row->source_fields ?? '{}'), true) ?: [];
            $sourceApp = in_array($row->source_app ?? null, ['finance', 'stock'], true) ? (string) $row->source_app : 'finance';
        } else {
            $entity = (string) ($row->entity_type ?? '');
            if ($entity !== $kind) {
                throw new \InvalidArgumentException('sync_incident_entity_invalid');
            }
            $fields = json_decode((string) ($row->source_snapshot ?? '{}'), true) ?: [];
            $sourceApp = 'stock';
        }
        $status = self::status($kind, $row);

        return [
            'version' => self::VERSION,
            'organization_mapping_uuid' => $mappingUuid,
            'document_kind' => $kind,
            'outbox_id' => (int) $row->id,
            'entity_type' => $entity,
            'source_app' => $sourceApp,
            'source_id' => (int) $row->source_id,
            'display_name' => self::displayName((string) ($fields['name'] ?? '')),
            'state' => $status,
            'reason' => $status === 'intervention' ? self::reason($kind, $row) : null,
            'episode' => max(1, $episode),
        ];
    }

    public static function displayName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($name))));

        return mb_substr($clean, 0, 80);
    }

    public static function fingerprint(array $facts): string
    {
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }
}
