<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationMasterDataMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\IntegrationSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkflowValidationService
{
    public function __construct(private readonly WorkflowCurrencyResolver $currencies) {}

    /**
     * Connected organizations fail closed before stock or document state is
     * mutated. Unconnected organizations keep their existing SolaStock flow.
     */
    public function assertOperationalDocumentReady(object $document, string $eventType): void
    {
        $orgId = (int) $document->organization_id;
        $connected = IntegrationSetting::query()
            ->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)
            ->whereIn('mode', ['connected_readonly', 'connected_pending_mapping', 'active', 'paused'])
            ->exists();
        if (! $connected) {
            return;
        }
        $mapping = IntegrationOrganizationMapping::query()
            ->where('solastock_organization_id', $orgId)
            ->where('tenant_database_identity', (string) DB::connection('tenant')->getDatabaseName())
            ->where('contract_version', SolaStockJournalContract::VERSION)
            ->where('status', 'verified_hold')
            ->where('activation_state', 'maintenance_hold')
            ->first();
        if (! $mapping) {
            throw ValidationException::withMessages([
                'workflow' => [json_encode([
                    'code' => 'mapping_review_required',
                    'event_type' => $eventType,
                    'missing_mappings' => [[
                        'entity_type' => 'organization',
                        'solastock_record_id' => (string) $orgId,
                        'code' => 'verified_organization_mapping_required',
                    ]],
                ], JSON_UNESCAPED_SLASHES)],
            ]);
        }

        $documentType = match ($eventType) {
            'purchase_order.approved' => 'purchase_order',
            'grn.posted' => 'goods_receipt',
            'grn.reversed' => 'goods_receipt',
            'sales_order.confirmed', 'stock_reserved', 'stock_reservation_released' => 'sales_order',
            'pick_list.picked' => 'pick_list',
            'pack.packed' => 'pack',
            'shipment.posted' => 'shipment',
            'sales_return.posted' => 'sales_return',
            default => class_basename($document),
        };
        $date = match ($documentType) {
            'purchase_order', 'sales_order' => optional($document->order_date)->toDateString(),
            'goods_receipt' => optional($document->receipt_date)->toDateString(),
            'shipment' => optional($document->ship_date)->toDateString(),
            'sales_return' => optional($document->return_date)->toDateString(),
            'inventory_reversal' => optional($document->reversal_date)->toDateString(),
            default => optional($document->updated_at)->toDateString(),
        };
        $this->currencies->resolve($document, $documentType, $date);

        $document->loadMissing('lines');
        $required = collect();
        foreach ($document->lines as $line) {
            if ($line->item_id) {
                $required->push(['item', (string) $line->item_id]);
            }
            if ($line->entered_unit_id ?? null) {
                $required->push(['unit', (string) $line->entered_unit_id]);
            }
        }
        if ($document->warehouse_id ?? null) {
            $required->push(['warehouse', (string) $document->warehouse_id]);
        }
        if ($document->supplier_id ?? null) {
            $required->push(['supplier', (string) $document->supplier_id]);
        }
        if ($document->customer_id ?? null) {
            $required->push(['customer', (string) $document->customer_id]);
        }
        if (in_array($documentType, ['pick_list', 'pack', 'shipment'], true)
            && ($document->sales_order_id ?? null)) {
            $customerId = DB::connection('tenant')->table('inventory_sales_orders')
                ->where('id', $document->sales_order_id)->value('customer_id');
            if ($customerId) {
                $required->push(['customer', (string) $customerId]);
            }
        }
        foreach ($this->accountRoles($eventType) as $role) {
            $required->push(['account_role', $role]);
        }

        $missing = $required->unique(fn (array $identity) => implode('|', $identity))
            ->reject(fn (array $identity) => $this->mapped($mapping->mapping_uuid, ...$identity))
            ->map(fn (array $identity) => [
                'entity_type' => $identity[0],
                'solastock_record_id' => $identity[1],
                'code' => 'mapping_review_required',
            ])->values()->all();

        $taxCodes = collect($document->lines)
            ->pluck('tax_code')->filter()->unique()->values();
        foreach ($taxCodes as $taxCode) {
            $mapped = DB::connection('tenant')->table('integration_tax_mappings')
                ->where('organization_id', $orgId)
                ->where('integration', IntegrationEvents::INTEGRATION)
                ->where('tax_code', $taxCode)
                ->where('status', 'mapped')
                ->exists();
            if (! $mapped) {
                $missing[] = [
                    'entity_type' => 'tax',
                    'solastock_record_id' => (string) $taxCode,
                    'code' => 'mapping_review_required',
                ];
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'workflow' => [json_encode([
                    'code' => 'mapping_review_required',
                    'event_type' => $eventType,
                    'missing_mappings' => $missing,
                ], JSON_UNESCAPED_SLASHES)],
            ]);
        }
    }

    private function mapped(string $organizationMappingUuid, string $entityType, string $solastockRecordId): bool
    {
        if ($entityType === 'account_role') {
            $accountMappingId = DB::connection('tenant')->table('integration_account_mappings')
                ->where('integration', IntegrationEvents::INTEGRATION)
                ->where('mapping_type', $solastockRecordId)
                ->whereIn('status', ['mapped', 'verified'])
                ->value('id');

            return $accountMappingId && IntegrationMasterDataMapping::query()
                ->where('organization_mapping_uuid', $organizationMappingUuid)
                ->where('entity_type', 'account_role')
                ->where('solastock_record_id', (string) $accountMappingId)
                ->whereIn('status', ['mapped', 'verified'])
                ->exists();
        }

        return IntegrationMasterDataMapping::query()
            ->where('organization_mapping_uuid', $organizationMappingUuid)
            ->where('entity_type', $entityType)
            ->where('solastock_record_id', $solastockRecordId)
            ->whereIn('status', ['mapped', 'verified'])
            ->exists();
    }

    /** @return Collection<int,string> */
    private function accountRoles(string $eventType): Collection
    {
        return collect(match ($eventType) {
            'grn.posted' => ['inventory_asset', 'grni'],
            'grn.reversed' => ['grni', 'inventory_asset'],
            'shipment.posted' => ['cogs', 'inventory_asset'],
            'sales_return.posted' => ['inventory_asset', 'cogs'],
            default => [],
        });
    }
}
