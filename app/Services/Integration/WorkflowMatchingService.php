<?php

namespace App\Services\Integration;

use App\Services\Stock\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Deterministic, read-only lifecycle matching. It deliberately reports timing
 * and amount differences; it never chooses an account or mutates a document.
 */
final class WorkflowMatchingService
{
    public function evaluate(array $quantities, array $amounts = []): array
    {
        $q = [];
        foreach ([
            'ordered', 'reserved', 'received', 'billed',
            'shipped', 'invoiced', 'returned',
        ] as $field) {
            $q[$field] = Decimal::qty((string) ($quantities[$field] ?? 0));
            if (Decimal::lt($q[$field], '0')) {
                $this->fail('workflow_quantity_negative', $field);
            }
        }

        $hint = $quantities['lifecycle'] ?? null;
        $purchase = $hint === 'purchasing'
            || Decimal::gt($q['received'], '0') || Decimal::gt($q['billed'], '0');
        $sales = $hint === 'sales'
            || Decimal::gt($q['reserved'], '0') || Decimal::gt($q['shipped'], '0')
            || Decimal::gt($q['invoiced'], '0');
        if ($purchase && $sales) {
            $this->fail('workflow_mixed_lifecycle');
        }
        if ($purchase) {
            $this->limitIfKnown($q['received'], $q['ordered'], 'over_receipt');
            $this->limitIfKnown($q['billed'], $q['ordered'], 'over_billing');
            $this->limitIfKnown($q['returned'], $q['received'], 'over_supplier_return');
        } elseif ($sales) {
            $this->limitIfKnown($q['reserved'], $q['ordered'], 'over_reservation');
            $this->limitIfKnown($q['shipped'], $q['ordered'], 'over_shipment');
            $this->limitIfKnown($q['invoiced'], $q['ordered'], 'over_invoicing');
            $this->limitIfKnown($q['returned'], $q['shipped'], 'over_customer_return');
        }

        $differences = [];
        if ($purchase && Decimal::gt($q['billed'], $q['received'])) {
            $differences[] = ['code' => 'bill_before_receipt', 'classification' => 'timing'];
        }
        if ($purchase && Decimal::gt($q['received'], $q['billed'])) {
            $differences[] = ['code' => 'receipt_not_fully_billed', 'classification' => 'timing'];
        }
        if ($sales && Decimal::gt($q['invoiced'], $q['shipped'])) {
            $differences[] = ['code' => 'invoice_before_shipment', 'classification' => 'timing'];
        }
        if ($sales && Decimal::gt($q['shipped'], $q['invoiced'])) {
            $differences[] = ['code' => 'shipment_not_fully_invoiced', 'classification' => 'timing'];
        }

        $valuation = Decimal::money((string) ($amounts['inventory_valuation'] ?? 0));
        $financial = Decimal::money((string) ($amounts['financial_subtotal'] ?? 0));
        if (Decimal::gt($valuation, '0') && Decimal::gt($financial, '0')
            && Decimal::cmp($valuation, $financial, 2) !== 0) {
            $differences[] = [
                'code' => 'price_or_currency_difference',
                'classification' => $amounts['difference_classification'] ?? 'review_required',
                'amount' => Decimal::money(Decimal::sub($financial, $valuation)),
            ];
        }

        return [
            'valid' => true,
            'lifecycle' => $purchase ? 'purchasing' : ($sales ? 'sales' : 'operational_only'),
            'quantities' => $q,
            'matching_state' => $differences === [] ? 'matched' : 'partially_matched',
            'differences' => $differences,
            'mutation' => [
                'events' => 0, 'attempts' => 0, 'nonces' => 0,
                'api_usage' => 0, 'journals' => 0, 'documents' => 0,
                'inventory' => 0, 'mappings' => 0,
            ],
        ];
    }

    public function landedCost(string $total, string $allocated, string $method): array
    {
        if (! in_array($method, ['quantity', 'weight', 'value'], true)) {
            $this->fail('landed_cost_method_invalid');
        }
        $total = Decimal::money($total);
        $allocated = Decimal::money($allocated);
        $this->limit($allocated, $total, 'landed_cost_over_allocation');

        return [
            'valid' => true,
            'allocation_method' => $method,
            'total' => $total,
            'allocated' => $allocated,
            'remaining' => Decimal::money(Decimal::sub($total, $allocated)),
            'matching_state' => Decimal::cmp($allocated, $total, 2) === 0
                ? 'matched' : 'partially_matched',
            'accounting_ownership' => [
                'solastock' => 'inventory_valuation',
                'solabooks' => 'supplier_or_expense_document_and_landed_cost_clearing',
            ],
            'mutation' => [
                'events' => 0, 'attempts' => 0, 'nonces' => 0,
                'api_usage' => 0, 'journals' => 0, 'documents' => 0,
                'inventory' => 0, 'mappings' => 0,
            ],
        ];
    }

    private function limit(string $actual, string $limit, string $code): void
    {
        if (Decimal::gt($actual, $limit)) {
            $this->fail($code);
        }
    }

    private function limitIfKnown(string $actual, string $limit, string $code): void
    {
        if (Decimal::gt($limit, '0')) {
            $this->limit($actual, $limit, $code);
        }
    }

    private function fail(string $code, ?string $field = null): never
    {
        throw ValidationException::withMessages([
            'workflow' => [json_encode(array_filter([
                'code' => $code,
                'field' => $field,
            ]), JSON_UNESCAPED_SLASHES)],
        ]);
    }
}
