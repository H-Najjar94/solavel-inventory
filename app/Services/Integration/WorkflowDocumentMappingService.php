<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\Reservation;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkflowDocumentMappingService
{
    public function recordForEvent(IntegrationOutboxEvent $event, object $document): ?IntegrationDocumentLifecycleMapping
    {
        $organization = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', (int) $event->organization_id)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->whereIn('status', ['verified_hold', 'verified'])
            ->whereIn('activation_state', ['maintenance_hold', 'active'])
            ->first();
        if (! $organization) {
            return null;
        }

        $type = $this->canonicalType((string) $event->aggregate_type, $document);
        $identity = [
            'organization_mapping_uuid' => $organization->mapping_uuid,
            'source_application' => 'solastock',
            'source_document_type' => $type,
            'source_document_id' => (string) $event->aggregate_id,
        ];
        $existing = IntegrationDocumentLifecycleMapping::query()->where($identity)->first();
        if ($existing) {
            $lines = $this->documentLines($type, $document);
            $before = $existing->getAttributes();
            $existing->fill([
                'lifecycle_status' => (string) ($document->status ?? $existing->lifecycle_status),
                ...$this->quantities($type, $lines),
                ...$this->amounts($event, $document),
                'updated_by_user_id' => auth()->id(),
                'last_verified_at' => now(),
            ])->save();
            $this->audit($existing, 'document_mapping_lifecycle_updated', $before);
            return $existing;
        }

        $quantities = $this->quantities($type, $this->documentLines($type, $document));
        $currency = (array) data_get($event->payload, 'currency', []);
        $parent = $this->parentMapping($organization->mapping_uuid, $type, $document);

        return DB::connection('tenant')->transaction(function () use (
            $identity, $organization, $event, $document, $quantities, $currency, $parent
        ): IntegrationDocumentLifecycleMapping {
            $mapping = IntegrationDocumentLifecycleMapping::query()->create($identity + [
                'mapping_uuid' => (string) Str::uuid(),
                'central_client_id' => $organization->central_client_id,
                'central_organization_id' => $organization->central_organization_id,
                'tenant_database_identity' => $organization->tenant_database_identity,
                'finance_organization_id' => $organization->finance_organization_id,
                'solastock_organization_id' => $organization->solastock_organization_id,
                'document_version' => 'phase3.v1',
                'parent_mapping_uuid' => $parent?->mapping_uuid,
                'lifecycle_status' => (string) ($document->status ?? 'recorded'),
                ...$quantities,
                'transaction_currency_code' => $currency['code'] ?? null,
                'base_currency_code' => $organization->base_currency_code,
                'exchange_rate' => $currency['exchange_rate'] ?? (($currency['code'] ?? null) === $organization->base_currency_code ? 1 : null),
                'exchange_rate_date' => $currency['rate_date'] ?? data_get($event->payload, 'document_date'),
                ...$this->amounts($event, $document),
                'accounting_source_key' => $event->idempotency_key,
                'matching_state' => $parent ? 'partially_matched' : 'unmatched',
                'safe_metadata' => [
                    'event_type' => $event->event_type,
                    'document_number_hash' => $event->aggregate_number
                        ? hash('sha256', (string) $event->aggregate_number) : null,
                ],
                'last_verified_at' => now(),
                'created_by_user_id' => auth()->id(),
                'updated_by_user_id' => auth()->id(),
            ]);
            $this->audit($mapping, 'document_mapping_created');

            return $mapping;
        });
    }

    public function recordReservationsForSalesOrder(IntegrationOutboxEvent $event, object $salesOrder): void
    {
        if (! in_array($event->event_type, ['stock_reserved', 'stock_reservation_released'], true)) {
            return;
        }
        $organization = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', (int) $event->organization_id)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->whereIn('status', ['verified_hold', 'verified'])
            ->whereIn('activation_state', ['maintenance_hold', 'active'])
            ->first();
        if (! $organization) {
            return;
        }
        $parent = IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $organization->mapping_uuid)
            ->where('source_application', 'solastock')
            ->where('source_document_type', 'sales_order')
            ->where('source_document_id', (string) $salesOrder->id)
            ->first();
        $currency = (array) data_get($event->payload, 'currency', []);

        Reservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $salesOrder->id)
            ->orderBy('id')
            ->each(function (Reservation $reservation) use ($organization, $event, $parent, $currency): void {
                $identity = [
                    'organization_mapping_uuid' => $organization->mapping_uuid,
                    'source_application' => 'solastock',
                    'source_document_type' => 'reservation',
                    'source_document_id' => (string) $reservation->id,
                ];
                $mapping = IntegrationDocumentLifecycleMapping::query()->where($identity)->first();
                if ($mapping) {
                    $before = $mapping->getAttributes();
                    $mapping->fill([
                        'lifecycle_status' => $reservation->status,
                        'reserved_qty' => $reservation->status === 'active' ? $reservation->qty : 0,
                        'last_verified_at' => now(),
                        'updated_by_user_id' => auth()->id(),
                    ])->save();
                    $this->audit($mapping, 'reservation_mapping_updated', $before);
                    return;
                }
                $mapping = IntegrationDocumentLifecycleMapping::query()->create($identity + [
                    'mapping_uuid' => (string) Str::uuid(),
                    'central_client_id' => $organization->central_client_id,
                    'central_organization_id' => $organization->central_organization_id,
                    'tenant_database_identity' => $organization->tenant_database_identity,
                    'finance_organization_id' => $organization->finance_organization_id,
                    'solastock_organization_id' => $organization->solastock_organization_id,
                    'document_version' => 'phase3.v1',
                    'parent_mapping_uuid' => $parent?->mapping_uuid,
                    'lifecycle_status' => $reservation->status,
                    'reserved_qty' => $reservation->status === 'active' ? $reservation->qty : 0,
                    'transaction_currency_code' => $currency['code'] ?? null,
                    'base_currency_code' => $organization->base_currency_code,
                    'exchange_rate' => $currency['exchange_rate'] ?? null,
                    'exchange_rate_date' => $currency['rate_date'] ?? null,
                    'accounting_source_key' => $event->idempotency_key.':reservation:'.$reservation->id,
                    'matching_state' => $parent ? 'partially_matched' : 'review_required',
                    'safe_metadata' => [
                        'item_id' => (string) $reservation->item_id,
                        'warehouse_id' => (string) $reservation->warehouse_id,
                    ],
                    'last_verified_at' => now(),
                    'created_by_user_id' => auth()->id(),
                    'updated_by_user_id' => auth()->id(),
                ]);
                $this->audit($mapping, 'reservation_mapping_created');
            });
    }

    private function parentMapping(string $organizationMappingUuid, string $type, object $document): ?IntegrationDocumentLifecycleMapping
    {
        [$parentType, $parentId] = match ($type) {
            'goods_receipt' => ['purchase_order', $document->purchase_order_id ?? null],
            'supplier_return' => ['goods_receipt', $document->source_id ?? null],
            'reservation', 'pick_list', 'pack', 'shipment' => ['sales_order', $document->sales_order_id ?? null],
            'sales_return' => ['shipment', $document->shipment_id ?? $document->source_reversal_shipment_id ?? null],
            default => [null, null],
        };
        if (! $parentType || ! $parentId) {
            return null;
        }

        return IntegrationDocumentLifecycleMapping::query()
            ->where('organization_mapping_uuid', $organizationMappingUuid)
            ->where('source_application', 'solastock')
            ->where('source_document_type', $parentType)
            ->where('source_document_id', (string) $parentId)
            ->first();
    }

    private function quantities(string $type, $lines): array
    {
        $sum = fn (string $field): string => Decimal::qty($lines->reduce(
            fn (string $carry, $line): string => Decimal::add(
                $carry,
                (string) ($line->{$field} ?? 0),
            ),
            '0',
        ));

        return [
            'ordered_qty' => in_array($type, ['purchase_order', 'sales_order'], true) ? $sum('ordered_qty') : 0,
            'reserved_qty' => in_array($type, ['reservation', 'sales_order'], true) ? $sum('reserved_qty') : 0,
            'received_qty' => $type === 'goods_receipt' ? $sum('accepted_qty')
                : ($type === 'purchase_order' ? $sum('received_qty') : 0),
            'billed_qty' => 0,
            'shipped_qty' => $type === 'shipment' ? $sum('quantity')
                : ($type === 'sales_order' ? $sum('shipped_qty') : 0),
            'invoiced_qty' => 0,
            'returned_qty' => $type === 'sales_return' ? $sum('returned_qty') : 0,
            ...($type === 'supplier_return' ? ['returned_qty' => $sum('quantity')] : []),
        ];
    }

    private function canonicalType(string $aggregateType, object $document): string
    {
        return match (class_basename($aggregateType)) {
            'PurchaseOrder' => 'purchase_order',
            'GoodsReceipt' => 'goods_receipt',
            'InventoryReversal' => ($document->source_type ?? null) === 'goods_receipt'
                ? 'supplier_return' : 'inventory_reversal',
            'SalesOrder' => 'sales_order',
            'PickList' => 'pick_list',
            'Pack' => 'pack',
            'Shipment' => 'shipment',
            'SalesReturn' => 'sales_return',
            default => Str::snake(class_basename($aggregateType)),
        };
    }

    private function documentLines(string $type, object $document)
    {
        if ($type === 'supplier_return') {
            return StockLedger::query()
                ->where('source_type', get_class($document))
                ->where('source_id', $document->id)
                ->where('direction', 'out')
                ->get();
        }
        if (method_exists($document, 'lines')) {
            $document->loadMissing('lines');

            return collect($document->lines);
        }

        return collect();
    }

    private function amounts(IntegrationOutboxEvent $event, object $document): array
    {
        return [
            'transaction_subtotal' => $document->subtotal ?? null,
            'transaction_tax' => $document->tax_total ?? null,
            'transaction_total' => $document->total ?? null,
            'inventory_valuation_effect' => data_get(
                $event->payload,
                'total_inventory_value_change'
            ),
        ];
    }

    private function audit(IntegrationDocumentLifecycleMapping $mapping, string $action, ?array $before = null): void
    {
        DB::connection('tenant')->table('integration_document_lifecycle_audits')->insert([
            'organization_mapping_uuid' => $mapping->organization_mapping_uuid,
            'mapping_uuid' => $mapping->mapping_uuid,
            'action' => $action,
            'before_hash' => $before
                ? hash('sha256', json_encode($before, JSON_UNESCAPED_SLASHES))
                : null,
            'after_hash' => hash('sha256', json_encode($mapping->getAttributes(), JSON_UNESCAPED_SLASHES)),
            'safe_metadata' => json_encode([
                'source_document_type' => $mapping->source_document_type,
                'lifecycle_status' => $mapping->lifecycle_status,
                'matching_state' => $mapping->matching_state,
            ], JSON_UNESCAPED_SLASHES),
            'actor_user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['document_mapping' => $code]);
    }
}
