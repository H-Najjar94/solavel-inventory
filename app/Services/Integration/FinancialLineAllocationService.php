<?php

namespace App\Services\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationFinancialLineAllocation;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
use App\Services\Access\WarehouseAccessService;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stock-owned, concurrency-safe reservation ledger for Finance document lines. */
final class FinancialLineAllocationService
{
    public const CONTRACT_VERSION = 'solastock-financial-allocation.v1';

    public function reserve(array $input): array
    {
        return DB::connection('tenant')->transaction(function () use ($input): array {
            $connection = $this->connection();
            $this->releaseExpired($connection->mapping_uuid);
            $this->releaseSupersededDestination($connection->mapping_uuid, $input);
            $current = IntegrationFinancialLineAllocation::query()
                ->where('organization_mapping_uuid', $connection->mapping_uuid)
                ->where('destination_document_type', $input['destination_document_type'])
                ->where('destination_document_id', $input['destination_document_id'])
                ->where('destination_fingerprint', $input['destination_fingerprint'])
                ->where('state', 'draft_reserved')->lockForUpdate()->get();
            $replaceIds = $current->pluck('id')->all();
            $rows = [];
            $identities = [];
            foreach ($input['allocations'] as $requested) {
                $identity = implode('|', [$requested['source_document_mapping_uuid'], $requested['source_line_id'], $requested['destination_line_id']]);
                if (isset($identities[$identity])) $this->fail('A source line may be allocated to a destination line only once per review.');
                $identities[$identity] = true;
                $rows[] = $this->reserveLine($connection, $input, $requested, $replaceIds);
            }
            $kept = collect($rows)->pluck('allocation_uuid')->all();
            foreach ($current->whereNotIn('allocation_uuid', $kept) as $replaced) {
                $replaced->state = 'released';
                $replaced->released_at = now();
                $replaced->reserved_until = null;
                $replaced->updated_by_user_id = auth()->id();
                $replaced->save();
            }
            return ['contract_version' => self::CONTRACT_VERSION, 'allocations' => $rows];
        }, 5);
    }

    public function transition(array $input, string $to): array
    {
        return DB::connection('tenant')->transaction(function () use ($input, $to): array {
            $connection = $this->connection();
            $query = IntegrationFinancialLineAllocation::query()
                ->where('organization_mapping_uuid', $connection->mapping_uuid)
                ->where('destination_document_type', $input['destination_document_type'])
                ->where('destination_document_id', $input['destination_document_id'])
                ->where('destination_fingerprint', $input['destination_fingerprint'])
                ->lockForUpdate();
            $rows = $query->get();
            if ($rows->isEmpty()) $this->fail('No reserved allocations belong to this financial document.');
            foreach ($rows as $row) {
                if (! hash_equals((string) $row->destination_fingerprint, (string) $input['destination_fingerprint'])) {
                    $this->fail('The financial document changed after allocation review.');
                }
                if ($to === 'posted') {
                    if ($row->state === 'posted') continue;
                    if ($row->state !== 'draft_reserved' || ($row->reserved_until && $row->reserved_until->isPast())) {
                        $this->fail('The source reservation expired or is no longer postable. Review remaining quantities again.');
                    }
                    $row->state = 'posted';
                    $row->posted_at = now();
                    $row->reserved_until = null;
                } elseif ($to === 'released') {
                    if ($row->state === 'released') continue;
                    if ($row->state !== 'draft_reserved') $this->fail('Only a draft reservation can be released.');
                    $row->state = 'released';
                    $row->released_at = now();
                    $row->reserved_until = null;
                } elseif ($to === 'reversed') {
                    if ($row->state === 'reversed') continue;
                    if ($row->state !== 'posted') $this->fail('Only a posted allocation can be reversed.');
                    $row->state = 'reversed';
                    $row->reversed_at = now();
                }
                $row->updated_by_user_id = auth()->id();
                $row->save();
            }
            return ['contract_version' => self::CONTRACT_VERSION, 'state' => $to,
                'allocation_uuids' => $rows->pluck('allocation_uuid')->all()];
        }, 5);
    }

    private function reserveLine(IntegrationOrganizationMapping $connection, array $input, array $requested, array $replaceIds): array
    {
        $source = IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('mapping_uuid', $requested['source_document_mapping_uuid'])
            ->where('source_application', 'solastock')
            ->where('source_document_type', $requested['source_document_type'])
            ->where('source_document_id', (string) $requested['source_document_id'])
            ->whereNull('conflict_code')->whereNull('error_state')->lockForUpdate()->first();
        if (! $source) $this->fail('The selected source identity is unavailable or requires review.');

        $receipt = $requested['source_document_type'] === 'goods_receipt';
        $return = $requested['source_document_type'] === 'sales_return';
        if (! $receipt && ! $return && $requested['source_document_type'] !== 'shipment') $this->fail('Unsupported financial source type.');
        $expected = $receipt ? ['supplier_bill', 'bill'] : ($return ? ['customer_credit_note', 'customer_credit'] : ['customer_invoice', 'invoice']);
        if ($input['destination_document_type'] !== $expected[0] || $input['allocation_kind'] !== $expected[1]) {
            $this->fail('The source and destination document types are incompatible.');
        }
        if ((string) $source->transaction_currency_code !== strtoupper((string) $input['currency_code'])
            || (string) $source->base_currency_code !== strtoupper((string) $input['base_currency_code'])
            || Decimal::cmp((string) $source->exchange_rate, (string) $input['exchange_rate'], 12) !== 0) {
            $this->fail('Source and destination currency snapshots do not match.');
        }
        $document = ($receipt ? GoodsReceipt::query() : ($return ? SalesReturn::query() : Shipment::query()))
            ->where('organization_id', $connection->solastock_organization_id)
            ->with('lines')->lockForUpdate()->find($requested['source_document_id']);
        if (! $document || ! $document->posted_at
            || ($return ? ($document->status !== 'posted' || $document->reversed_at) : $document->reversed_at)) {
            $this->fail('The selected source is not posted or was reversed.');
        }
        app(WarehouseAccessService::class)->assertAllowed((int) $document->warehouse_id);
        $line = $document->lines->firstWhere('id', (int) $requested['source_line_id']);
        if (! $line) $this->fail('The selected source line does not belong to this document.');
        if ((int) $line->item_id !== (int) $requested['stock_item_id']) $this->fail('The selected item identity does not match its source line.');

        $sourceQty = Decimal::round((string) ($receipt ? $line->accepted_qty : ($return ? $line->returned_qty : $line->quantity)), 8);
        $baseQty = Decimal::round((string) $requested['base_quantity'], 8);
        if (! Decimal::gt($baseQty, '0')) $this->fail('Allocated quantity must be positive.');
        $idempotency = hash('sha256', implode('|', [
            $input['destination_document_type'], $input['destination_document_id'], $requested['destination_line_id'],
            $source->mapping_uuid, $line->id, $input['destination_fingerprint'],
            Decimal::round((string) $requested['entered_quantity'], 8), Decimal::round((string) $requested['base_quantity'], 8),
            (string) $requested['destination_unit_id'], Decimal::round((string) $requested['destination_quantity'], 8),
            Decimal::round((string) $requested['destination_unit_price'], 8), Decimal::round((string) $requested['destination_gross'], 8),
            Decimal::round((string) ($requested['line_discount_allocated'] ?? 0), 8),
            Decimal::round((string) ($requested['document_discount_allocated'] ?? 0), 8),
            Decimal::round((string) $requested['destination_net'], 8),
        ]));
        $existing = IntegrationFinancialLineAllocation::query()
            ->where('organization_mapping_uuid', $connection->mapping_uuid)->where('idempotency_key', $idempotency)->lockForUpdate()->first();
        $usedQuery = IntegrationFinancialLineAllocation::query()
            ->where('organization_mapping_uuid', $connection->mapping_uuid)
            ->where('source_document_mapping_uuid', $source->mapping_uuid)
            ->where('source_line_id', $line->id)->whereIn('state', ['draft_reserved', 'posted'])
            ->when($replaceIds !== [], fn ($query) => $query->whereNotIn('id', $replaceIds))
            ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id));
        $used = (string) $usedQuery->sum('base_quantity');
        if (Decimal::gt(Decimal::add($used, $baseQty), $sourceQty)) $this->fail('The allocation exceeds the source line remaining quantity.');
        if (! empty($line->serial_id) && Decimal::cmp($baseQty, '1', 8) !== 0) $this->fail('Serial-tracked source lines are indivisible.');

        $factor = Decimal::round((string) ($line->unit_conversion_factor ?? 1), 12);
        $enteredQty = Decimal::round((string) $requested['entered_quantity'], 8);
        if (Decimal::cmp(Decimal::mul($enteredQty, $factor), $baseQty, 8) !== 0) {
            $this->fail('Entered and base quantities do not match the frozen unit conversion.');
        }
        $destinationQty = Decimal::round((string) $requested['destination_quantity'], 8);
        if (! Decimal::gt($destinationQty, '0') || empty($requested['destination_unit_id'])) {
            $this->fail('The financial line must preserve its reviewed unit and quantity.');
        }
        $salesOrderLineId = $return && $line->source_shipment_line_id
            ? \App\Models\Tenant\ShipmentLine::query()->find($line->source_shipment_line_id)?->sales_order_line_id
            : ($line->sales_order_line_id ?? null);
        $sourcePrice = Decimal::round((string) ($receipt ? $line->unit_cost : SalesOrderLine::query()
            ->where('organization_id', $connection->solastock_organization_id)->find($salesOrderLineId)?->unit_price), 8);
        if (! Decimal::gt($sourcePrice, '0')) $this->fail('The selected source line has no valid base-unit price.');
        $sourceGross = Decimal::round(Decimal::mul($baseQty, $sourcePrice), 8);
        $destinationGross = Decimal::round((string) $requested['destination_gross'], 8);
        $destinationUnitPrice = Decimal::round((string) $requested['destination_unit_price'], 8);
        if (! Decimal::gt($destinationUnitPrice, '0')
            || Decimal::cmp(Decimal::mul($destinationQty, $destinationUnitPrice), $destinationGross, 8) !== 0) {
            $this->fail('The financial quantity and unit price do not reconcile to the destination gross amount.');
        }
        $lineDiscount = Decimal::round((string) ($requested['line_discount_allocated'] ?? 0), 8);
        $documentDiscount = Decimal::round((string) ($requested['document_discount_allocated'] ?? 0), 8);
        $destinationNet = Decimal::round((string) $requested['destination_net'], 8);
        if (Decimal::cmp(Decimal::sub($destinationGross, Decimal::add($lineDiscount, $documentDiscount)), $destinationNet, 8) !== 0
            || Decimal::lt($lineDiscount, '0') || Decimal::lt($documentDiscount, '0')) {
            $this->fail('The financial price and allocated discounts do not reconcile to the destination net amount.');
        }
        $sourceFingerprint = hash('sha256', json_encode([
            'mapping_uuid' => $source->mapping_uuid, 'source_line_id' => (int) $line->id,
            'item_id' => (int) $line->item_id, 'base_quantity' => $sourceQty, 'unit_price' => $sourcePrice,
            'entered_unit_id' => $line->entered_unit_id, 'base_unit_id' => $line->base_unit_id,
            'factor' => $factor, 'conversion_hash' => $line->unit_conversion_hash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($existing) {
            if ($existing->state === 'released') {
                $existing->state = 'draft_reserved'; $existing->released_at = null;
                $existing->reserved_until = now()->addHours(24); $existing->updated_by_user_id = auth()->id(); $existing->save();
            }
            return $this->serialize($existing);
        }

        $row = IntegrationFinancialLineAllocation::query()->create([
            'allocation_uuid' => (string) Str::uuid(), 'organization_mapping_uuid' => $connection->mapping_uuid,
            'central_client_id' => $connection->central_client_id, 'central_organization_id' => $connection->central_organization_id,
            'tenant_database_identity' => $connection->tenant_database_identity,
            'finance_organization_id' => $connection->finance_organization_id, 'solastock_organization_id' => $connection->solastock_organization_id,
            'source_document_mapping_uuid' => $source->mapping_uuid, 'source_document_type' => $source->source_document_type,
            'source_document_id' => $source->source_document_id, 'source_line_id' => $line->id,
            'destination_document_type' => $input['destination_document_type'], 'destination_document_id' => $input['destination_document_id'],
            'destination_line_id' => $requested['destination_line_id'], 'allocation_kind' => $input['allocation_kind'],
            'entered_quantity' => $enteredQty, 'entered_unit_id' => $line->entered_unit_id, 'base_quantity' => $baseQty,
            'base_unit_id' => $line->base_unit_id, 'unit_conversion_id' => $line->unit_conversion_id,
            'destination_quantity' => $destinationQty, 'destination_unit_id' => $requested['destination_unit_id'],
            'unit_conversion_factor' => $factor, 'unit_conversion_version' => $line->unit_conversion_version,
            'unit_conversion_hash' => $line->unit_conversion_hash, 'unit_conversion_precision' => $line->unit_conversion_precision ?? 4,
            'unit_conversion_rounding_mode' => $line->unit_conversion_rounding_mode ?? 'HALF_UP',
            'source_unit_price' => $sourcePrice, 'destination_unit_price' => $destinationUnitPrice,
            'source_gross' => $sourceGross, 'destination_gross' => $destinationGross,
            'line_discount_allocated' => $lineDiscount, 'document_discount_allocated' => $documentDiscount,
            'source_net' => $sourceGross, 'destination_net' => $destinationNet,
            'price_difference' => Decimal::round(Decimal::sub($destinationNet, $sourceGross), 8),
            'currency_code' => $input['currency_code'], 'base_currency_code' => $input['base_currency_code'],
            'exchange_rate' => $input['exchange_rate'], 'state' => 'draft_reserved',
            'destination_revision' => $input['destination_revision'], 'source_fingerprint' => $sourceFingerprint,
            'destination_fingerprint' => $input['destination_fingerprint'], 'idempotency_key' => $idempotency,
            'reserved_until' => now()->addHours(24), 'created_by_user_id' => auth()->id(), 'updated_by_user_id' => auth()->id(),
            'safe_metadata' => ['contract_version' => self::CONTRACT_VERSION,
                'lot_id' => $line->lot_id ?? null, 'serial_id' => $line->serial_id ?? null,
                'condition' => $line->condition ?? null, 'disposition' => $line->disposition ?? null],
        ]);
        return $this->serialize($row);
    }

    private function connection(): IntegrationOrganizationMapping
    {
        return IntegrationOrganizationMapping::query()->where('status', 'verified')->where('activation_state', 'active')
            ->where('solastock_organization_id', app(\App\Tenancy\OrganizationContext::class)->idOrFail())
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->lockForUpdate()->firstOrFail();
    }

    private function releaseExpired(string $mappingUuid): void
    {
        IntegrationFinancialLineAllocation::query()->where('organization_mapping_uuid', $mappingUuid)
            ->where('state', 'draft_reserved')->whereNotNull('reserved_until')->where('reserved_until', '<=', now())
            ->update(['state' => 'released', 'released_at' => now(), 'reserved_until' => null, 'updated_at' => now()]);
    }

    private function releaseSupersededDestination(string $mappingUuid, array $input): void
    {
        $active = IntegrationFinancialLineAllocation::query()
            ->where('organization_mapping_uuid', $mappingUuid)
            ->where('destination_document_type', $input['destination_document_type'])
            ->where('destination_document_id', $input['destination_document_id'])
            ->where('state', 'draft_reserved')
            ->where('destination_fingerprint', '!=', $input['destination_fingerprint'])
            ->lockForUpdate()->get();
        foreach ($active as $row) {
            $row->state = 'released';
            $row->released_at = now();
            $row->reserved_until = null;
            $row->updated_by_user_id = auth()->id();
            $row->save();
        }
    }

    private function serialize(IntegrationFinancialLineAllocation $row): array
    {
        return ['allocation_uuid' => $row->allocation_uuid, 'source_document_mapping_uuid' => $row->source_document_mapping_uuid,
            'source_line_id' => (int) $row->source_line_id, 'destination_line_id' => (int) $row->destination_line_id,
            'entered_quantity' => (string) $row->entered_quantity, 'base_quantity' => (string) $row->base_quantity,
            'destination_quantity' => (string) $row->destination_quantity, 'destination_unit_id' => (int) $row->destination_unit_id,
            'price_difference' => (string) $row->price_difference, 'state' => $row->state,
            'reserved_until' => $row->reserved_until?->toIso8601String()];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['allocations' => $message]);
    }
}
