<?php

namespace App\Services\Catalog;

use App\Models\Tenant\Item;
use App\Services\Integration\FinanceConnectionClient;
use App\Services\Integration\SolaStockJournalContract;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Existing signed owner-command transport; never sends a financial stock movement. */
final class StockUnitProjection
{
    public function mapping(Item $item, int $target): ?object
    {
        $db = $item->getConnection();
        $maps = $db->table('integration_organization_mappings')->where('solastock_organization_id', $item->organization_id)->where('tenant_database_identity', $db->getDatabaseName())->where('status', 'verified')->where('activation_state', 'active')->lockForUpdate()->get();
        if ($maps->isEmpty()) {
            return null;
        }
        if ($maps->count() !== 1) {
            throw new \RuntimeException('unit_projection_mapping_ambiguous');
        }
        $map = $maps->first();
        foreach ([['item', $item->id], ['unit', $item->base_unit_id], ['unit', $target]] as [$type,$id]) {
            $rows = $db->table('integration_master_data_mappings')->where('organization_mapping_uuid', $map->mapping_uuid)->where('entity_type', $type)->where('solastock_record_id', (string) $id)->where('status', 'verified')->lockForUpdate()->get();
            if ($rows->count() !== 1) {
                throw new \RuntimeException('unit_projection_verified_identity_required');
            }
        }
        $pending = $db->table(DurableCatalogSync::TABLE)->where('organization_mapping_uuid', $map->mapping_uuid)->where('entity_type', 'item')->where('source_id', $item->id)->where('state', '<>', 'delivered')->exists();
        if ($pending) {
            throw new \RuntimeException('unit_projection_catalog_pending');
        }

        return $map;
    }

    /** Read-only Finance eligibility while the verified counterpart row remains locked until Stock commits. */
    public function preflight(Item $item, int $target, string $numerator, string $denominator): ?array
    {
        $map = $this->mapping($item, $target);
        if (! $map) {
            return null;
        }
        $db = $item->getConnection();
        if ($db->transactionLevel() < 1 || $map->tenant_database_identity !== $db->getDatabaseName()) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.finance_check_unavailable')]);
        }
        $identity = fn ($type, $id) => $db->table('integration_master_data_mappings')->where('organization_mapping_uuid', $map->mapping_uuid)->where('entity_type', $type)->where('solastock_record_id', (string) $id)->where('status', 'verified')->value('solabooks_record_id');
        $financeItem = (int) $identity('item', $item->id);
        $financeUnit = (int) $identity('unit', $target);
        try {
            $row = $db->table('inventory_items')->where('organization_id', $map->finance_organization_id)->where('id', $financeItem)->lockForUpdate()->first(['id']);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.finance_check_unavailable')]);
        }
        if (! $row) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.finance_check_unavailable')]);
        }
        $data = ['organization_mapping_uuid' => $map->mapping_uuid, 'source_item_id' => $item->id, 'from_unit_id' => (int) $item->base_unit_id, 'to_unit_id' => $target, 'ratio_numerator' => $numerator, 'ratio_denominator' => $denominator];
        try {
            $reply = app(FinanceConnectionClient::class)->command(['client_id' => $map->central_client_id, 'organization_id' => $map->central_organization_id, 'finance_organization_id' => $map->finance_organization_id, 'actor_id' => (int) auth()->id(), 'action' => 'catalog.unit_transition.preflight', 'idempotency_key' => 'unit-preflight:'.SolaStockJournalContract::payloadHash($data), 'data' => $data]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.finance_check_unavailable')]);
        }
        if (($reply['status'] ?? null) !== 'ready' || (int) ($reply['target_item_id'] ?? 0) !== $financeItem || (int) ($reply['target_unit_id'] ?? 0) !== $financeUnit || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($reply['metadata_hash'] ?? ''))) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.finance_check_unavailable')]);
        }

        return ['status' => 'ready', 'target_item_id' => $financeItem, 'target_unit_id' => $financeUnit, 'metadata_hash' => $reply['metadata_hash']];
    }

    public function assertReady(int $item): void
    {
        $db = DB::connection('tenant');
        if (! $db->getSchemaBuilder()->hasTable(ItemBaseUnitConversion::TABLE)) {
            return;
        }
        if ($db->table(ItemBaseUnitConversion::TABLE)->where('organization_id', app(OrganizationContext::class)->idOrFail())->where('item_id', $item)->where('state', '<>', 'ready')->exists()) {
            throw ValidationException::withMessages(['base_unit_conversion' => __('inventory.base_unit.projection_pending')]);
        }
    }

    public function process(object $map, int $limit = 2): int
    {
        if (($map->status ?? null) !== 'verified' || ($map->activation_state ?? null) !== 'active') {
            return 0;
        }
        $db = DB::connection('tenant');
        if (! $db->getSchemaBuilder()->hasTable(ItemBaseUnitConversion::TABLE)) {
            return 0;
        }
        if ($db->transactionLevel() !== 0) {
            throw new \LogicException('Unit projection delivery requires transaction zero');
        }
        $processed = 0;
        for ($n = 0; $n < min(10, $limit); $n++) {
            $row = $db->transaction(function () use ($db, $map) {
                $row = $db->table(ItemBaseUnitConversion::TABLE)->where('organization_mapping_uuid', $map->mapping_uuid)->where('organization_id', $map->solastock_organization_id)->whereIn('state', ['finance_pending', 'delivering'])->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->lockForUpdate()->first();
                if (! $row) {
                    return null;
                }
                $row->lease_uuid = (string) Str::uuid();
                $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->update(['state' => 'delivering', 'lease_uuid' => $row->lease_uuid, 'lease_expires_at' => now()->addSeconds(90), 'delivery_attempts' => $row->delivery_attempts + 1]);

                return $row;
            });
            if (! $row) {
                break;
            }
            try {
                $payload = json_decode($row->finance_payload, true, 512, JSON_THROW_ON_ERROR);
                $ack = app(FinanceConnectionClient::class)->command(['client_id' => $map->central_client_id, 'organization_id' => $map->central_organization_id, 'finance_organization_id' => $map->finance_organization_id, 'actor_id' => $row->created_by, 'action' => 'catalog.unit_transition', 'idempotency_key' => 'unit-transition:'.$row->request_uuid, 'data' => $payload]);
                foreach (['event_uuid', 'snapshot_hash', 'revision_after'] as $key) {
                    if (($ack[$key] ?? null) !== ($payload[$key] ?? null)) {
                        throw new \RuntimeException('unit_projection_ack_invalid');
                    }
                }
                $expectedItem = $db->table('integration_master_data_mappings')->where('organization_mapping_uuid', $map->mapping_uuid)->where('entity_type', 'item')->where('solastock_record_id', (string) $row->item_id)->where('status', 'verified')->value('solabooks_record_id');
                $expectedUnit = $db->table('integration_master_data_mappings')->where('organization_mapping_uuid', $map->mapping_uuid)->where('entity_type', 'unit')->where('solastock_record_id', (string) $row->to_unit_id)->where('status', 'verified')->value('solabooks_record_id');
                if ((int) ($ack['target_item_id'] ?? 0) !== (int) $expectedItem || (int) ($ack['target_unit_id'] ?? 0) !== (int) $expectedUnit) {
                    throw new \RuntimeException('unit_projection_ack_invalid');
                }
                if (($ack['status'] ?? null) !== 'ready' || (int) ($ack['target_item_id'] ?? 0) < 1 || (int) ($ack['target_unit_id'] ?? 0) < 1) {
                    throw new \RuntimeException('unit_projection_ack_invalid');
                }
                $db->transaction(function () use ($db, $row, $ack, $payload) {
                    $item = Item::query()->lockForUpdate()->findOrFail($row->item_id);
                    if (CatalogSourceSnapshot::revision(app(CatalogSourceSnapshot::class)->capture('item', $item->id, $item->organization_id)) !== $payload['revision_after']) {
                        throw new \RuntimeException('unit_projection_source_changed');
                    }
                    $updated = $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->where('lease_uuid', $row->lease_uuid)->update(['state' => 'ready', 'finance_ack' => json_encode($ack, JSON_THROW_ON_ERROR), 'last_error' => null, 'lease_uuid' => null, 'lease_expires_at' => null, 'next_attempt_at' => null]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('unit_projection_lease_changed');
                    }
                    $db->table(DurableCatalogSync::TABLE)->where('organization_mapping_uuid', $row->organization_mapping_uuid)->where('entity_type', 'item')->where('source_id', $item->id)->update(['state' => 'delivered', 'source_revision' => $payload['revision_after'], 'source_snapshot' => CatalogSourceSnapshot::encode($payload['after']), 'target_id' => $ack['target_item_id'], 'delivery_ack' => json_encode($ack, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                });
            } catch (\Throwable $e) {
                $db->table(ItemBaseUnitConversion::TABLE)->where('id', $row->id)->where('lease_uuid', $row->lease_uuid)->update(['state' => 'finance_pending', 'last_error' => in_array($e->getMessage(), ['unit_projection_ack_invalid', 'unit_projection_source_changed', 'unit_projection_lease_changed', 'finance_connection_transport_unknown_retry_same_key'], true) ? $e->getMessage() : 'unit_projection_retry_required', 'lease_uuid' => null, 'lease_expires_at' => null, 'next_attempt_at' => now()->addSeconds(30)]);
            }
            $processed++;
        }

        return $processed;
    }
}
