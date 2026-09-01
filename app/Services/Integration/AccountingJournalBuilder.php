<?php

namespace App\Services\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\InventoryReversal;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\Shipment;
use App\Models\Tenant\StockLedger;
use App\Services\Stock\Support\Decimal;
use RuntimeException;

class AccountingJournalBuilder
{
    public function build(IntegrationOutboxEvent $event, int $orgId): array
    {
        return match ($event->event_type) {
            'grn.posted' => $this->goodsReceipt($event, $orgId),
            'grn.reversed', 'adjustment.reversed' => $this->inventoryReversal($event, $orgId),
            'shipment.posted' => $this->shipment($event, $orgId),
            'sales_return.posted' => $this->salesReturn($event, $orgId),
            'adjustment.posted', 'stock_count.posted' => $this->adjustment($event, $orgId),
            default => $this->twoLine($event, $orgId),
        };
    }

    private function inventoryReversal(IntegrationOutboxEvent $event, int $orgId): array
    {
        $reversal = InventoryReversal::query()->findOrFail($event->aggregate_id);
        $originalType = match ($reversal->source_type) {
            'goods_receipt' => 'grn.posted',
            'stock_adjustment' => 'adjustment.posted',
            default => throw new RuntimeException("Unsupported reversal source '{$reversal->source_type}'."),
        };
        $original = $this->originalEvent($originalType, (int) $reversal->source_id);
        $lines = $originalType === 'grn.posted'
            ? $this->goodsReceipt($original, $orgId)
            : $this->adjustment($original, $orgId);

        return $this->invert($lines, $event);
    }

    private function salesReturn(IntegrationOutboxEvent $event, int $orgId): array
    {
        $return = SalesReturn::query()->findOrFail($event->aggregate_id);
        if (! $return->is_source_reversal || ! $return->source_reversal_shipment_id) {
            return $this->twoLine($event, $orgId);
        }

        return $this->invert(
            $this->shipment($this->originalEvent('shipment.posted', (int) $return->source_reversal_shipment_id), $orgId),
            $event,
        );
    }

    private function originalEvent(string $eventType, int $aggregateId): IntegrationOutboxEvent
    {
        $event = IntegrationOutboxEvent::query()
            ->where('event_type', $eventType)
            ->where('aggregate_id', $aggregateId)
            ->first();
        if (! $event) {
            throw new RuntimeException("Original accounting event '{$eventType}' was not found.");
        }

        return $event;
    }

    private function invert(array $lines, IntegrationOutboxEvent $reversal): array
    {
        return array_map(function (array $line) use ($reversal) {
            [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];
            $line['description'] = $reversal->aggregate_number;

            return $line;
        }, $lines);
    }

    private function goodsReceipt(IntegrationOutboxEvent $event, int $orgId): array
    {
        $inventory = $this->inventoryValue($event);

        return [
            $this->line($this->account($orgId, 'inventory_asset'), $inventory, '0', $event),
            $this->line($this->account($orgId, 'grni'), '0', $inventory, $event),
        ];
    }

    private function shipment(IntegrationOutboxEvent $event, int $orgId): array
    {
        $cogs = $this->inventoryValue($event);

        return [
            $this->line($this->account($orgId, 'cogs'), $cogs, '0', $event),
            $this->line($this->account($orgId, 'inventory_asset'), '0', $cogs, $event),
        ];
    }

    private function adjustment(IntegrationOutboxEvent $event, int $orgId): array
    {
        $change = (string) ($event->payload['total_inventory_value_change'] ?? '0');
        $amount = Decimal::money($this->absolute($change));
        if (! Decimal::gt($amount, '0')) {
            throw new RuntimeException(__('inventory.integration.event_no_value'));
        }
        if (Decimal::gt($change, '0')) {
            return [
                $this->line($this->account($orgId, 'inventory_asset'), $amount, '0', $event),
                $this->line($this->account($orgId, 'adjustment_gain'), '0', $amount, $event),
            ];
        }

        return [
            $this->line($this->account($orgId, 'adjustment_loss'), $amount, '0', $event),
            $this->line($this->account($orgId, 'inventory_asset'), '0', $amount, $event),
        ];
    }

    private function twoLine(IntegrationOutboxEvent $event, int $orgId): array
    {
        $payload = $event->payload ?? [];
        $change = (string) ($payload['total_inventory_value_change'] ?? 0);
        $amount = Decimal::money($this->absolute($change));
        if (! Decimal::gt($amount, '0')) {
            throw new RuntimeException(__('inventory.integration.event_no_value'));
        }
        $debit = (string) ($payload['suggested_debit_account_mapping'] ?? '');
        $credit = (string) ($payload['suggested_credit_account_mapping'] ?? '');
        if (Decimal::lt($change, '0')) {
            [$debit, $credit] = [$credit, $debit];
        }

        return [
            $this->line($this->account($orgId, $debit), $amount, '0', $event),
            $this->line($this->account($orgId, $credit), '0', $amount, $event),
        ];
    }

    private function inventoryValue(IntegrationOutboxEvent $event): string
    {
        $total = StockLedger::query()
            ->where('source_type', 'App\\Models\\Tenant\\'.$event->aggregate_type)
            ->where('source_id', $event->aggregate_id)
            ->orderBy('id')->pluck('total_cost')
            ->reduce(fn (string $carry, $value) => Decimal::add($carry, (string) $value), '0');

        return Decimal::money($this->absolute($total));
    }

    private function absolute(string $value): string
    {
        return Decimal::lt($value, '0') ? Decimal::sub('0', $value) : $value;
    }

    private function account(int $orgId, string $type): int
    {
        $id = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)->where('integration', IntegrationEvents::INTEGRATION)
            ->where('mapping_type', $type)->whereIn('status', ['mapped', 'verified'])
            ->value('solabooks_account_id');
        if (! $id) {
            throw new RuntimeException("Missing SolaCount account mapping '{$type}'.");
        }

        return (int) $id;
    }

    private function taxMapping(int $orgId, string $taxCode, string $use): IntegrationTaxMapping
    {
        $mapping = IntegrationTaxMapping::query()->where('organization_id', $orgId)
            ->where('integration', IntegrationEvents::INTEGRATION)->where('tax_code', $taxCode)
            ->where('status', 'mapped')->first();
        $account = $use === 'purchase' ? $mapping?->input_tax_account_id : $mapping?->output_tax_account_id;
        if (! $mapping || ! $mapping->solabooks_tax_id || ($mapping->treatment === 'standard' && ! $account)) {
            throw new RuntimeException("Missing active SolaCount tax mapping '{$taxCode}'.");
        }

        return $mapping;
    }

    private function line(
        int $accountId,
        string $debit,
        string $credit,
        IntegrationOutboxEvent $event,
        ?IntegrationTaxMapping $tax = null,
        ?string $taxableBaseAmount = null,
    ): array {
        $role = $tax
            ? 'tax'
            : (string) IntegrationAccountMapping::query()
                ->where('organization_id', $event->organization_id)
                ->where('integration', IntegrationEvents::INTEGRATION)
                ->where('solabooks_account_id', (string) $accountId)
                ->whereIn('status', ['mapped', 'verified'])
                ->orderBy('id')
                ->value('mapping_type');
        if ($role === '') {
            throw new RuntimeException("SolaCount account {$accountId} has no active organization mapping role.");
        }

        return [
            'account_id' => $accountId,
            'account_role' => $role,
            'debit' => Decimal::money($debit),
            'credit' => Decimal::money($credit),
            'description' => $event->aggregate_number,
            'tax_rate_id' => $tax?->solabooks_tax_id,
            'tax_rate_code' => $tax?->solabooks_tax_code,
            'is_tax_line' => $tax !== null,
            'taxable_base_amount' => $tax ? Decimal::money((string) $taxableBaseAmount) : null,
        ];
    }
}
