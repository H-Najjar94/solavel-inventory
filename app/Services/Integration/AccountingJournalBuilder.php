<?php

namespace App\Services\Integration;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationAccountMapping;
use App\Models\Tenant\IntegrationOutboxEvent;
use App\Models\Tenant\IntegrationTaxMapping;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\SalesOrderLine;
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
            'shipment.posted' => $this->shipment($event, $orgId),
            'adjustment.posted', 'stock_count.posted' => $this->adjustment($event, $orgId),
            default => $this->twoLine($event, $orgId),
        };
    }

    private function goodsReceipt(IntegrationOutboxEvent $event, int $orgId): array
    {
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($event->aggregate_id);
        $inventory = $this->inventoryValue($event);
        $taxByAccount = [];
        $taxTotal = '0';

        foreach ($receipt->lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $poLine = PurchaseOrderLine::query()->find($line->purchase_order_line_id);
            if (! $poLine || ! $poLine->tax_code) {
                continue;
            }
            $mapping = $this->taxMapping($orgId, (string) $poLine->tax_code, 'purchase');
            $ratio = Decimal::div((string) $line->accepted_qty, (string) $poLine->ordered_qty);
            $amount = Decimal::money(Decimal::mul((string) $poLine->tax_amount, $ratio));
            $taxTotal = Decimal::add($taxTotal, $amount);
            if (Decimal::gt($amount, '0')) {
                $account = (int) $mapping->input_tax_account_id;
                $taxByAccount[$account]['amount'] = Decimal::add($taxByAccount[$account]['amount'] ?? '0', $amount);
                $taxByAccount[$account]['mapping'] = $mapping;
            }
        }

        $lines = [$this->line($this->account($orgId, 'inventory_asset'), $inventory, '0', $event)];
        foreach ($taxByAccount as $account => $tax) {
            $lines[] = $this->line($account, $tax['amount'], '0', $event, $tax['mapping']);
        }
        $lines[] = $this->line($this->account($orgId, 'grni'), '0', Decimal::money(Decimal::add($inventory, $taxTotal)), $event);

        return $lines;
    }

    private function shipment(IntegrationOutboxEvent $event, int $orgId): array
    {
        $shipment = Shipment::query()->with('lines')->findOrFail($event->aggregate_id);
        $net = '0';
        $taxTotal = '0';
        $taxByAccount = [];
        foreach ($shipment->lines as $line) {
            $orderLine = $line->sales_order_line_id
                ? SalesOrderLine::query()->find($line->sales_order_line_id)
                : null;
            if (! $orderLine) {
                throw new RuntimeException('Shipment accounting requires a source sales-order line.');
            }
            $ratio = Decimal::div((string) $line->quantity, (string) $orderLine->ordered_qty);
            $gross = Decimal::mul((string) $line->quantity, (string) $orderLine->unit_price);
            $discount = Decimal::mul((string) $orderLine->discount_amount, $ratio);
            $net = Decimal::add($net, Decimal::sub($gross, $discount));
            if ($orderLine->tax_code) {
                $mapping = $this->taxMapping($orgId, (string) $orderLine->tax_code, 'sales');
                $amount = Decimal::money(Decimal::mul((string) $orderLine->tax_amount, $ratio));
                $taxTotal = Decimal::add($taxTotal, $amount);
                if (Decimal::gt($amount, '0')) {
                    $account = (int) $mapping->output_tax_account_id;
                    $taxByAccount[$account]['amount'] = Decimal::add($taxByAccount[$account]['amount'] ?? '0', $amount);
                    $taxByAccount[$account]['mapping'] = $mapping;
                }
            }
        }
        $net = Decimal::money($net);
        $cogs = $this->inventoryValue($event);
        $lines = [
            $this->line($this->account($orgId, 'accounts_receivable'), Decimal::money(Decimal::add($net, $taxTotal)), '0', $event),
            $this->line($this->account($orgId, 'sales_revenue'), '0', $net, $event),
        ];
        foreach ($taxByAccount as $account => $tax) {
            $lines[] = $this->line($account, '0', $tax['amount'], $event, $tax['mapping']);
        }
        $lines[] = $this->line($this->account($orgId, 'cogs'), $cogs, '0', $event);
        $lines[] = $this->line($this->account($orgId, 'inventory_asset'), '0', $cogs, $event);

        return $lines;
    }

    private function adjustment(IntegrationOutboxEvent $event, int $orgId): array
    {
        $change = (string) ($event->payload['total_inventory_value_change'] ?? '0');
        $amount = Decimal::money((string) abs((float) $change));
        if (! Decimal::gt($amount, '0')) {
            throw new RuntimeException('Integration event has no value to post.');
        }
        if ((float) $change > 0) {
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
        $amount = Decimal::money((string) abs((float) ($payload['total_inventory_value_change'] ?? 0)));
        if (! Decimal::gt($amount, '0')) {
            throw new RuntimeException('Integration event has no value to post.');
        }
        $debit = (string) ($payload['suggested_debit_account_mapping'] ?? '');
        $credit = (string) ($payload['suggested_credit_account_mapping'] ?? '');
        if ((float) ($payload['total_inventory_value_change'] ?? 0) < 0) {
            [$debit, $credit] = [$credit, $debit];
        }

        return [
            $this->line($this->account($orgId, $debit), $amount, '0', $event),
            $this->line($this->account($orgId, $credit), '0', $amount, $event),
        ];
    }

    private function inventoryValue(IntegrationOutboxEvent $event): string
    {
        return Decimal::money((string) abs((float) StockLedger::query()
            ->where('source_type', 'App\\Models\\Tenant\\'.$event->aggregate_type)
            ->where('source_id', $event->aggregate_id)
            ->sum('total_cost')));
    }

    private function account(int $orgId, string $type): int
    {
        $id = IntegrationAccountMapping::query()
            ->where('organization_id', $orgId)->where('integration', IntegrationEvents::INTEGRATION)
            ->where('mapping_type', $type)->whereIn('status', ['mapped', 'verified'])
            ->value('solabooks_account_id');
        if (! $id) {
            throw new RuntimeException("Missing SolaBooks account mapping '{$type}'.");
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
            throw new RuntimeException("Missing active SolaBooks tax mapping '{$taxCode}'.");
        }

        return $mapping;
    }

    private function line(int $accountId, string $debit, string $credit, IntegrationOutboxEvent $event, ?IntegrationTaxMapping $tax = null): array
    {
        return [
            'account_id' => $accountId,
            'debit' => Decimal::money($debit),
            'credit' => Decimal::money($credit),
            'description' => $event->aggregate_number,
            'tax_rate_id' => $tax?->solabooks_tax_id,
            'tax_rate_code' => $tax?->solabooks_tax_code,
            'is_tax_line' => $tax !== null,
        ];
    }
}
