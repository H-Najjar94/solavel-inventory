<?php
namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use Illuminate\Support\Facades\{Cache,DB,Log,Schema};

/**
 * Party/catalog sync incidents through the existing durable document-incident outbox.
 *
 * Sibling of DocumentIncidentNotificationPublisher: it only records notification intent
 * (after commit, deduplicated by the outbox unique transition key) and never changes sync
 * state, master data, journals or stock. Delivery, HMAC signing, retry and tenant scoping are
 * the existing DocumentIncidentNotificationPublisher::process() path, which forwards
 * document_kind party|item|unit|category to Central unchanged.
 */
final class SyncIncidentNotificationPublisher
{
    private const OUTBOX = 'integration_document_notification_outbox';

    /** Record intent once the surrounding tenant transaction commits; never fails the caller. */
    public function changed(string $kind, int $stateId, string $mappingUuid): void
    {
        try {
            DB::connection('tenant')->afterCommit(function () use ($kind, $stateId, $mappingUuid): void {
                try {
                    $this->queue($kind, $stateId, $mappingUuid);
                } catch (\Throwable $error) {
                    report($error);
                }
            });
        } catch (\Throwable $error) {
            report($error);
        }
    }

    /**
     * Current facts for a sync-state row, or null when it is not (or no longer) notifiable.
     * Shared by the queue and Stock's signed context callback so both compute the same
     * fingerprint for the same transition.
     */
    public function currentFacts(string $kind, object $row, object $mapping, int $organizationId): ?array
    {
        [$episode, $resolved] = $this->lastEpisode($kind, (int) $row->id, $organizationId);
        $status = SyncIncidentFacts::status($kind, $row);
        if ($status === 'intervention') {
            $episode = ($episode === 0 || $resolved) ? $episode + 1 : $episode;
        } elseif ($status === 'resolved') {
            if ($episode === 0) {
                return null;
            }
        } else {
            $episode = max(1, $episode);
        }

        return SyncIncidentFacts::fromRow($kind, $row, (string) $mapping->mapping_uuid, $episode);
    }

    public function queue(string $kind, int $stateId, string $mappingUuid): void
    {
        if (! in_array($kind, SyncIncidentFacts::KINDS, true)) {
            return;
        }
        $schema = Schema::connection('tenant');
        $table = SyncIncidentFacts::table($kind);
        if (! $schema->hasTable(self::OUTBOX) || ! $schema->hasTable($table)) {
            return;
        }
        $db = DB::connection('tenant');
        $mapping = $this->mapping($mappingUuid);
        if (! $mapping) {
            return;
        }
        $row = $db->table($table)->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('id', $stateId)->first();
        if (! $row || ($kind !== 'party' && $row->entity_type !== $kind)) {
            return;
        }
        $org = (int) $mapping->solastock_organization_id;
        $status = SyncIncidentFacts::status($kind, $row);
        if ($status === 'pending') {
            return;
        }
        [$episode, $resolved] = $this->lastEpisode($kind, (int) $row->id, $org);
        // A resolution is only worth telling when an intervention was told in this episode.
        if ($status === 'resolved' && ($episode === 0 || $resolved)) {
            return;
        }
        $facts = $this->currentFacts($kind, $row, $mapping, $org);
        if (! $facts) {
            return;
        }
        $inserted = $db->table(self::OUTBOX)->insertOrIgnore([
            'organization_id' => $org, 'organization_mapping_uuid' => $mapping->mapping_uuid,
            'document_kind' => $kind, 'document_outbox_id' => (int) $row->id,
            'transition_fingerprint' => SyncIncidentFacts::fingerprint($facts),
            'facts' => json_encode($facts, JSON_THROW_ON_ERROR), 'state' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted > 0) {
            Log::info('integration.sync_incident.queued', ['organization_id' => $org, 'organization_mapping_uuid' => $mapping->mapping_uuid,
                'document_kind' => $kind, 'sync_state_id' => (int) $row->id, 'state' => $facts['state'], 'reason' => $facts['reason'], 'episode' => $facts['episode']]);
        }
    }

    /** Supervisor entry point: reconcile at most once a minute per connection mapping. */
    public function sweep(object $mapping): int
    {
        $key = 'sync-incident-sweep:'.DB::connection('tenant')->getDatabaseName().':'.$mapping->mapping_uuid;
        if (! Cache::store('file')->add($key, 1, 60)) {
            return 0;
        }

        return $this->reconcile($mapping);
    }

    /**
     * Bounded rotating reconciliation: recovers lost after-commit inserts and resolutions written
     * by paths without a hook (explicit identity review, retries). No sync state is changed.
     */
    public function reconcile(object $mapping, int $batch = 10): int
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable(self::OUTBOX)) {
            return 0;
        }
        $db = DB::connection('tenant');
        $org = (int) $mapping->solastock_organization_id;
        $cache = Cache::store('file');
        $visited = 0;
        $sources = [
            'party' => fn ($q) => $q->where(fn ($q) => $q->where('status', 'intervention')
                ->orWhere(fn ($q) => $q->where('status', 'held')->where('last_error', 'party_approval_required'))),
            'catalog' => fn ($q) => $q->where('state', 'intervention_required'),
        ];
        foreach ($sources as $source => $filter) {
            $table = $source === 'party' ? 'integration_party_sync_states' : 'integration_catalog_sync_states';
            if (! $schema->hasTable($table)) {
                continue;
            }
            $key = 'sync-incident-recovery:'.$db->getDatabaseName().':'.$mapping->mapping_uuid.':'.$source;
            $cursor = (int) $cache->get($key, 0);
            $rows = $filter($db->table($table)->where('organization_mapping_uuid', $mapping->mapping_uuid)->where('id', '>', $cursor))
                ->orderBy('id')->limit($batch)->get(['id', 'entity_type']);
            foreach ($rows as $row) {
                $this->queue($source === 'party' ? 'party' : (string) $row->entity_type, (int) $row->id, (string) $mapping->mapping_uuid);
                $visited++;
            }
            $cache->put($key, $rows->count() < $batch ? 0 : (int) $rows->last()->id, 86400);
        }
        // Open episodes: re-check states that were told as interventions and may now be resolved.
        $key = 'sync-incident-open:'.$db->getDatabaseName().':'.$mapping->mapping_uuid;
        $cursor = (int) $cache->get($key, 0);
        $open = $db->table(self::OUTBOX)->where('organization_id', $org)->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->whereIn('document_kind', SyncIncidentFacts::KINDS)->where('id', '>', $cursor)->orderBy('id')->limit($batch)
            ->get(['id', 'document_kind', 'document_outbox_id']);
        foreach ($open as $row) {
            $this->queue((string) $row->document_kind, (int) $row->document_outbox_id, (string) $mapping->mapping_uuid);
            $visited++;
        }
        $cache->put($key, $open->count() < $batch ? 0 : (int) $open->last()->id, 86400);

        return $visited;
    }

    /** @return array{0:int,1:bool} latest told episode for the state row and whether it was resolved */
    private function lastEpisode(string $kind, int $stateId, int $organizationId): array
    {
        $episode = 0;
        $resolved = [];
        $rows = DB::connection('tenant')->table(self::OUTBOX)->where('organization_id', $organizationId)
            ->where('document_kind', $kind)->where('document_outbox_id', $stateId)->orderBy('id')->pluck('facts');
        foreach ($rows as $raw) {
            $facts = json_decode((string) $raw, true);
            if (! is_array($facts) || ($facts['version'] ?? null) !== SyncIncidentFacts::VERSION) {
                continue;
            }
            $number = (int) ($facts['episode'] ?? 0);
            $episode = max($episode, $number);
            if (($facts['state'] ?? null) === 'resolved') {
                $resolved[$number] = true;
            }
        }

        return [$episode, $episode > 0 && isset($resolved[$episode])];
    }

    private function mapping(string $mappingUuid): ?IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->where('mapping_uuid', $mappingUuid)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())
            ->where('status', 'verified')->where('activation_state', 'active')->first();
    }
}
