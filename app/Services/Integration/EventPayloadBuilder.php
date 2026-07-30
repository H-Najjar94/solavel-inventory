<?php

namespace App\Services\Integration;

use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;

/**
 * Builds the integration event payload for a posted/reversed document from its
 * canonical ledger rows. Includes accounting *hints* only (suggested debit/credit
 * mapping + mapping_status) — never a final journal entry.
 */
class EventPayloadBuilder
{
    public function __construct(private readonly WorkflowCurrencyResolver $currencies) {}

    /**
     * @param  object  $document  the posted document (has id, number, date)
     */
    public function build(string $eventType, object $document, string $documentType, ?string $number, ?string $date, bool $mappingComplete): array
    {
        $orgId = $document->organization_id;
        $aggregateType = IntegrationEvents::aggregateType($eventType);

        // Ledger rows for this document (the source of truth for movements).
        $sourceClass = 'App\\Models\\Tenant\\'.$aggregateType;
        $ledger = StockLedger::query()
            ->where('source_type', $sourceClass)
            ->where('source_id', $document->id)
            ->get();

        $totalChange = '0';
        $lines = [];
        foreach ($ledger as $row) {
            $signed = $row->direction === 'in' ? (string) $row->total_cost : '-'.$row->total_cost;
            $totalChange = Decimal::add($totalChange, $signed);
            $lines[] = [
                'item_id' => (int) $row->item_id,
                'sku' => null, // resolved lazily by consumer if needed
                'warehouse_id' => (int) $row->warehouse_id,
                'bin_id' => $row->bin_id ? (int) $row->bin_id : null,
                'quantity' => (string) $row->quantity,
                'unit_cost' => (string) $row->unit_cost,
                'total_cost' => (string) $row->total_cost,
                'movement_direction' => $row->direction,
                'ledger_entry_ids' => [(int) $row->id],
                'costing_method' => $row->costing_method,
                'lot_id' => $row->lot_id ? (int) $row->lot_id : null,
                'serial_id' => $row->serial_id ? (int) $row->serial_id : null,
            ];
        }

        $suggested = IntegrationEvents::suggestedAccounts($eventType);

        $currency = $this->currencies->resolve($document, $documentType, $date);

        return array_merge([
            'source_app' => 'solastock',
            'event_type' => $eventType,
            'organization_id' => (int) $orgId,
            'document_type' => $documentType,
            'document_id' => (int) $document->id,
            'document_number' => $number,
            'document_date' => $date,
            'currency' => $currency,
            'total_inventory_value_change' => Decimal::money($totalChange),
            'lines' => $lines,
            'original_source' => $this->originalSource($document),
        ], $suggested, [
            'mapping_status' => $mappingComplete ? 'complete' : 'incomplete',
            'requires_review' => ! $mappingComplete,
        ]);
    }

    private function originalSource(object $document): ?array
    {
        if ($document instanceof InventoryReversal) {
            return [
                'type' => $document->source_type,
                'id' => (int) $document->source_id,
                'number' => $document->source_number,
                'event_uuid' => $document->original_event_uuid,
                'reason' => $document->reason,
            ];
        }
        if ($document instanceof SalesReturn && $document->is_source_reversal) {
            return [
                'type' => 'shipment',
                'id' => (int) $document->source_reversal_shipment_id,
                'event_uuid' => $document->original_event_uuid,
                'reason' => $document->reason,
            ];
        }

        return null;
    }
}
