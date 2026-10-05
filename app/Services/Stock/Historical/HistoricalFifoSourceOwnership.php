<?php
namespace App\Services\Stock\Historical;

use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

final class HistoricalFifoSourceOwnership
{
    public function assert(array $event, StockLedger $row, int $org, string $segmentQuantity): void
    {
        foreach (['finance_document_id', 'finance_document_type', 'finance_source_id', 'finance_line_id', 'finance_line_ids'] as $key) if (! isset($event[$key])) throw new RuntimeException('Historical FIFO original Finance source identity missing');
        if ($row->source_type === \App\Models\Tenant\HistoricalFifoCorrection::class) {
            $correction = \App\Models\Tenant\HistoricalFifoCorrection::query()->where('organization_id', $org)->findOrFail($row->source_id);
            $plan = \App\Models\Tenant\HistoricalFifoPlan::query()->where('organization_id', $org)->where('status', 'applied')->findOrFail($correction->plan_id);
            $c = $correction->causal_payload;
            $original = collect($plan->plan['events'])->firstWhere('source_id', $c['planner_unique_id']);
            if (! $original || (int) $original['finance_document_id'] !== (int) $event['finance_document_id']
                || $original['finance_document_type'] !== $event['finance_document_type'] || $original['finance_source_id'] !== $event['finance_source_id']) {
                throw new RuntimeException('Historical FIFO prior correction original parent mismatch');
            }
            if ($c['source_id'] !== $event['finance_source_id'] || (int) $c['stock_item_id'] !== (int) $event['stock_item_id'] || (int) $c['warehouse_id'] !== (int) $event['warehouse_id']
                || ! in_array((int) $row->id, $correction->ledger_ids, true) || array_diff($c['finance_line_ids'], $event['finance_line_ids'])) throw new RuntimeException('Historical FIFO prior correction ownership mismatch');
            return;
        }
                        $sourceType = match (class_basename($row->source_type)) { 'GoodsReceipt' => 'goods_receipt', 'Shipment' => 'shipment', 'SalesReturn' => 'sales_return', default => null };
                        $destinationType = match ($event['finance_document_type']) { 'bill' => 'supplier_bill', 'invoice' => 'customer_invoice', 'credit_note' => 'customer_credit_note', default => null };
                        $sourceKeys = \App\Models\Tenant\IntegrationOutboxEvent::query()->where('organization_id', $org)->where('aggregate_id', $row->source_id)
                            ->where('aggregate_type', class_basename($row->source_type))->pluck('idempotency_key')->all();
                        $allocation = \App\Models\Tenant\IntegrationFinancialLineAllocation::query()->where('solastock_organization_id', $org)
                            ->where('source_document_type', $sourceType)->where('source_document_id', (string) $row->source_id)->where('source_line_id', $row->source_line_id)
                            ->where('destination_document_type', $destinationType)->where('destination_document_id', $event['finance_document_id'])
                            ->whereIn('destination_line_id', $event['finance_line_ids'] ?? [$event['finance_line_id']])->where('state', 'posted')->sum('base_quantity');
                        if (! $sourceType || ! $destinationType || ! array_intersect($sourceKeys, $event['previous_stock_source_keys'] ?? []) || Decimal::lt((string) $allocation, $segmentQuantity)) throw new RuntimeException('Historical FIFO original Finance/Stock document binding unproven');
    }
}
