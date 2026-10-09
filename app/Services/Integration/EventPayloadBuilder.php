<?php

namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;

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
        if ($document instanceof \App\Models\Tenant\HistoricalFifoCorrection) return $this->historicalFifo($eventType, $document, $mappingComplete);
        if ($document instanceof \App\Models\Tenant\LandedCost
            || ($document instanceof InventoryReversal && $document->source_type === 'landed_cost')) {
            return $this->landedCost($eventType, $document, $documentType, $number, $date, $mappingComplete);
        }
        $orgId = $document->organization_id;
        $aggregateType = IntegrationEvents::aggregateType($eventType);

        // Ledger rows for this document (the source of truth for movements).
        $sourceClass = 'App\\Models\\Tenant\\'.$aggregateType;
        $ledger = StockLedger::query()
            ->where('organization_id', $orgId)
            ->where('source_type', $sourceClass)
            ->where('source_id', $document->id)
            ->get();

        $totalChange = '0';
        $lines = [];
        // A complete source reversal preserves the original event line ordering.
        // A partial return has its own selected source-line provenance and must
        // use the return ledger row snapshot instead of indexing into all lines
        // from the original shipment.
        $originalConversionLines = $document instanceof SalesReturn && ! $document->is_source_reversal
            ? []
            : $this->originalConversionLines($document);
        foreach ($ledger as $index => $row) {
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
                'unit_conversion' => $originalConversionLines[$index] ?? $this->conversionForLedger($row),
            ];
        }

        $suggested = IntegrationEvents::suggestedAccounts($eventType);

        $currency = $this->currencies->resolve($document, $documentType, $date);
        // The currency authority validates and canonicalizes the transaction
        // date. Persist that exact YYYY-MM-DD value in the immutable event
        // payload instead of a model-cast midnight timestamp.
        $transactionDate = substr((string) ($date ?? ''), 0, 10);
        $original = $this->originalSource($document);
        // Only an inventory reversal of a document posted while standalone may have no original event
        // (IntegrationEvents::reversesDocumentUnknownToFinance excludes it from Finance). Every other
        // source, e.g. a sales return against a pre-connection shipment, still fails closed here.
        $originalPayload = self::mustInheritOriginal($original, $document) ? (array) IntegrationOutboxEvent::query()->where('organization_id', $orgId)
            ->where('event_uuid', $original['event_uuid'])->firstOrFail()->payload : null;
        $valuation = $ledger->isNotEmpty() ? app(FinanceBaseValuation::class)->contract((int) $orgId) : null;

        return array_merge([
            'source_app' => 'solastock',
            'event_type' => $eventType,
            'organization_id' => (int) $orgId,
            'document_type' => $documentType,
            'document_id' => (int) $document->id,
            'document_number' => $number,
            'document_date' => $transactionDate,
            'currency' => $currency,
            'inventory_valuation_basis' => $originalPayload !== null ? ($originalPayload['inventory_valuation_basis'] ?? null) : ($valuation ? FinanceBaseValuation::BASIS : null),
            'inventory_value_currency' => $originalPayload !== null ? ($originalPayload['inventory_value_currency'] ?? null) : ($valuation['base_currency_code'] ?? null),
            'total_inventory_value_change' => Decimal::money($totalChange),
            'lines' => $lines,
            'original_source' => $this->originalSource($document),
        ], $suggested, [
            'mapping_status' => $mappingComplete ? 'complete' : 'incomplete',
            'requires_review' => ! $mappingComplete,
        ]);
    }

    private function historicalFifo(string $eventType, \App\Models\Tenant\HistoricalFifoCorrection $document, bool $mappingComplete): array
    {
        $causal = $document->causal_payload;
        $valuation = app(FinanceBaseValuation::class)->contract((int) $document->organization_id);
        $rows = StockLedger::query()->where('organization_id', $document->organization_id)->whereIn('id', $document->ledger_ids)->get();
        $value = '0';
        foreach ($rows as $row) $value = Decimal::add($value, $row->direction === 'in' ? (string) $row->total_cost : '-'.$row->total_cost);
        if (Decimal::cmp($value, $causal['inventory_value_delta']) !== 0) throw new \RuntimeException('Historical FIFO causal/ledger delta mismatch');
        return ['source_app' => 'solastock', 'event_type' => $eventType, 'organization_id' => (int) $document->organization_id,
            'document_type' => 'historical_fifo_correction', 'document_id' => (int) $document->id, 'document_number' => $document->correction_uuid,
            'document_date' => $causal['business_date'], 'currency' => $this->currencies->resolve($document, 'historical_fifo_correction', $causal['business_date']),
            'inventory_valuation_basis' => FinanceBaseValuation::BASIS, 'inventory_value_currency' => $valuation['base_currency_code'],
            'total_inventory_value_change' => Decimal::money($value), 'historical_fifo_correction' => $causal, 'original_quantity_is_reference' => true, 'missing_quantity' => $causal['missing_quantity'], 'quantity_delta' => $causal['quantity_delta'],
            'cost_revision_ledger_ids' => $rows->filter(fn ($row) => Decimal::isZero((string) $row->quantity))->pluck('id')->all(),
            'missing_quantity_ledger_ids' => $rows->filter(fn ($row) => Decimal::gt((string) $row->quantity, '0'))->pluck('id')->all(),
            'lines' => [['item_id' => $causal['stock_item_id'], 'warehouse_id' => $causal['warehouse_id'], 'quantity' => $causal['quantity'],
                'unit_cost' => Decimal::cost(Decimal::div($causal['reconstructed_cost'], $causal['quantity'])), 'total_cost' => $causal['reconstructed_cost'],
                'movement_direction' => Decimal::gt($causal['cogs_delta'], '0') ? 'out' : 'in', 'ledger_entry_ids' => $document->ledger_ids,
                'costing_method' => 'fifo', 'lot_id' => null, 'serial_id' => null, 'unit_conversion' => $document->conversion_snapshot]],
            'original_source' => null, 'mapping_status' => $mappingComplete ? 'complete' : 'incomplete', 'requires_review' => ! $mappingComplete,
            'suggested_debit_account_mapping' => 'cogs', 'suggested_credit_account_mapping' => 'inventory_asset'];
    }

    /**
     * A landed cost writes no ledger rows: it revalues receipts. Its payload
     * carries one line per revalued receipt ledger row (with that row's frozen
     * unit-conversion snapshot) and the immutable per-role journal totals.
     * A reversal reuses the posted landed cost's lines, negated, and links to
     * the posted event through original_source.
     */
    private function landedCost(string $eventType, object $document, string $documentType, ?string $number, ?string $date, bool $mappingComplete): array
    {
        $reversal = $document instanceof InventoryReversal;
        $landed = $reversal ? \App\Models\Tenant\LandedCost::query()->findOrFail($document->source_id) : $document;
        $sign = $reversal ? '-' : '';
        $components = \App\Models\Tenant\LandedCostComponent::query()->where('landed_cost_id', $landed->id)->orderBy('id')->get();
        $journal = ['inventory_asset' => '0.00', 'cogs' => '0.00', 'adjustment_loss' => '0.00'];
        $perRow = [];
        foreach ($components as $component) {
            $journal[$component->destination_role] = Decimal::add($journal[$component->destination_role], (string) $component->posted_base_amount, 2);
            $rowId = (int) data_get($component->provenance, 'landed_cost_receipt_ledger_id', $component->stock_ledger_id);
            $perRow[$rowId] ??= ['inventory' => '0.00', 'total' => '0.00'];
            $perRow[$rowId]['total'] = Decimal::add($perRow[$rowId]['total'], (string) $component->posted_base_amount, 2);
            if ($component->destination_role === 'inventory_asset') {
                $perRow[$rowId]['inventory'] = Decimal::add($perRow[$rowId]['inventory'], (string) $component->posted_base_amount, 2);
            }
        }
        $journal['landed_cost_clearing'] = Decimal::add(Decimal::add($journal['inventory_asset'], $journal['cogs'], 2), $journal['adjustment_loss'], 2);
        ksort($perRow);
        $lines = [];
        foreach (StockLedger::query()->withoutGlobalScope('warehouse_access')->where('organization_id', $landed->organization_id)
            ->whereIn('id', array_keys($perRow))->orderBy('id')->get() as $row) {
            $lines[] = [
                'item_id' => (int) $row->item_id, 'sku' => null, 'warehouse_id' => (int) $row->warehouse_id,
                'bin_id' => $row->bin_id ? (int) $row->bin_id : null, 'quantity' => (string) $row->quantity,
                'unit_cost' => Decimal::cost(Decimal::div($perRow[$row->id]['total'], (string) $row->quantity)),
                'total_cost' => $sign === '' ? $perRow[$row->id]['inventory'] : Decimal::money('-'.$perRow[$row->id]['inventory']),
                'landed_cost_amount' => $perRow[$row->id]['total'],
                'movement_direction' => 'revaluation', 'ledger_entry_ids' => [(int) $row->id],
                'costing_method' => $row->costing_method,
                'lot_id' => $row->lot_id ? (int) $row->lot_id : null, 'serial_id' => $row->serial_id ? (int) $row->serial_id : null,
                'unit_conversion' => $this->conversionForLedger($row),
            ];
        }
        $original = $reversal ? $this->originalSource($document) : null;
        $originalPayload = $original && $original['event_uuid'] ? (array) IntegrationOutboxEvent::query()->where('organization_id', $landed->organization_id)
            ->where('event_uuid', $original['event_uuid'])->firstOrFail()->payload : null;
        $valuation = $originalPayload === null ? app(FinanceBaseValuation::class)->contract((int) $landed->organization_id) : null;

        return array_merge([
            'source_app' => 'solastock',
            'event_type' => $eventType,
            'organization_id' => (int) $landed->organization_id,
            'document_type' => $documentType,
            'document_id' => (int) $document->id,
            'document_number' => $number,
            'document_date' => substr((string) ($date ?? ''), 0, 10),
            'currency' => $this->currencies->resolve($document, $reversal ? 'inventory_reversal' : 'landed_cost', $date),
            'inventory_valuation_basis' => $originalPayload !== null ? ($originalPayload['inventory_valuation_basis'] ?? null) : ($valuation ? FinanceBaseValuation::BASIS : null),
            'inventory_value_currency' => $originalPayload !== null ? ($originalPayload['inventory_value_currency'] ?? null) : ($valuation['base_currency_code'] ?? null),
            'total_inventory_value_change' => $sign === '' ? $journal['inventory_asset'] : Decimal::money('-'.$journal['inventory_asset']),
            'lines' => $lines,
            'landed_cost' => [
                'landed_cost_id' => (int) $landed->id, 'landed_cost_number' => (string) $landed->landed_cost_number,
                'direction' => $reversal ? 'reversed' : 'posted', 'allocation_method' => (string) $landed->allocation_method,
                'currency_code' => (string) $landed->currency_code, 'exchange_rate' => (string) $landed->exchange_rate,
                'supplier_reference' => $landed->supplier_reference, 'total_amount' => (string) $landed->total_amount,
                'total_base_amount' => $journal['landed_cost_clearing'], 'journal' => $journal,
            ],
            'original_source' => $original,
        ], IntegrationEvents::suggestedAccounts($eventType), [
            'mapping_status' => $mappingComplete ? 'complete' : 'incomplete',
            'requires_review' => ! $mappingComplete,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function originalConversionLines(object $document): array
    {
        $original = $this->originalSource($document);
        if (! $original || empty($original['event_uuid'])) {
            return [];
        }
        $payload = IntegrationOutboxEvent::query()
            ->where('organization_id', $document->organization_id)->where('event_uuid', $original['event_uuid'])->value('payload');
        $payload = is_string($payload) ? json_decode($payload, true) : $payload;

        return collect((array) data_get($payload, 'lines', []))
            ->pluck('unit_conversion')->filter(fn ($snapshot) => is_array($snapshot))->values()->all();
    }

    /** @return array<string,mixed>|null */
    private function conversionForLedger(StockLedger $row): ?array
    {
        if (! $row->source_line_id) {
            return null;
        }
        $table = match (class_basename((string) $row->source_type)) {
            'GoodsReceipt' => 'goods_receipt_lines',
            'OpeningStockEntry' => 'opening_stock_entry_lines',
            'Shipment' => 'shipment_lines',
            'SalesReturn' => 'sales_return_lines',
            'SupplierReturn' => 'supplier_return_lines',
            'StockAdjustment' => 'stock_adjustment_lines',
            'StockTransfer' => 'stock_transfer_lines',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $line = DB::connection('tenant')->table($table)->where('id', $row->source_line_id)->first();
        if (! $line || empty($line->unit_conversion_hash)) {
            return null;
        }

        return [
            'item_id' => (int) $row->item_id,
            'source_quantity' => Decimal::qty(Decimal::div((string) $row->quantity, (string) $line->unit_conversion_factor)),
            'source_unit_id' => (int) $line->entered_unit_id,
            'base_quantity' => (string) $row->quantity,
            'base_unit_id' => (int) $line->base_unit_id,
            'conversion_id' => $line->unit_conversion_id === null ? null : (int) $line->unit_conversion_id,
            'factor' => (string) $line->unit_conversion_factor,
            'version' => (string) $line->unit_conversion_version,
            'hash' => (string) $line->unit_conversion_hash,
            'precision' => (int) $line->unit_conversion_precision,
            'rounding_mode' => (string) $line->unit_conversion_rounding_mode,
        ];
    }

    /**
     * Whether the original event payload must be loaded (and must exist). Only an InventoryReversal of a
     * document posted while standalone may lack an original event; anything else fails closed.
     */
    public static function mustInheritOriginal(?array $original, object $document): bool
    {
        if (! $original) return false;
        return ! empty($original['event_uuid']) || ! $document instanceof InventoryReversal;
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
        if ($document instanceof SalesReturn && ($document->is_source_reversal || $document->shipment_id)) {
            return [
                'type' => 'shipment',
                'id' => (int) ($document->source_reversal_shipment_id ?: $document->shipment_id),
                'event_uuid' => $document->original_event_uuid,
                'reason' => $document->reason,
            ];
        }

        return null;
    }
}
