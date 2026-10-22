<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\GoodsReceipt;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only purchase history derived from posted documents. Never writes and
 * never re-values: receipts come from posted, non-reversed GRNs (receipt cost,
 * base cost taken from the stock ledger row the posting wrote); bills come from
 * SolaCount bills whose journal entry is posted and not reversed, and only while
 * the connection is readable.
 */
final class SupplierPurchaseHistory
{
    public function __construct(private readonly FinanceLink $link) {}

    /**
     * @param array{item_id?: int, supplier_id?: int, from?: string, to?: string} $filters
     * @return array{receipts: array, bills: array, summary: array, finance: array}
     */
    public function history(array $filters, int $limit = 200): array
    {
        $receipts = $this->receipts($filters, $limit);
        $bills = $this->bills($filters, $limit);

        return [
            'receipts' => $receipts,
            'bills' => $bills,
            'summary' => $this->summary($bills !== [] ? $bills : $receipts, $bills !== [] ? 'bills' : 'receipts'),
            'finance' => ['state' => $this->link->state()['state']],
            'base_currency_code' => app(InventoryReportService::class)->baseCurrency(),
        ];
    }

    private function receipts(array $filters, int $limit): array
    {
        $org = app(OrganizationContext::class)->idOrFail();
        $rows = DB::connection('tenant')->table('goods_receipt_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->leftJoin('inventory_purchase_orders as p', 'p.id', '=', 'g.purchase_order_id')
            ->leftJoin('inventory_suppliers as s', 's.id', '=', 'g.supplier_id')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('stock_ledger as sl', function ($join) {
                $join->on('sl.source_line_id', '=', 'l.id')
                    ->where('sl.source_type', '=', GoodsReceipt::class)
                    ->where('sl.direction', '=', 'in')
                    ->whereNull('sl.original_ledger_id');
            })
            ->where('g.organization_id', $org)->where('l.organization_id', $org)
            ->where('g.status', 'posted')->whereNull('g.reversed_at')->whereNull('g.deleted_at')
            ->where('l.accepted_qty', '>', 0)
            ->when($filters['item_id'] ?? null, fn ($q, $id) => $q->where('l.item_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('g.supplier_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('g.receipt_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('g.receipt_date', '<=', $d))
            ->orderByDesc('g.receipt_date')->orderByDesc('l.id')->limit($limit)
            ->get([
                'l.id', 'l.item_id', 'i.sku as item_sku', 'i.name as item_name', 'g.id as document_id', 'g.grn_number as document_number',
                'g.receipt_date as date', 'g.supplier_id', 's.name as supplier_name', 's.code as supplier_code',
                'l.accepted_qty as quantity', 'l.unit_cost', 'p.currency_code as po_currency', 'p.po_number',
                'sl.unit_cost as base_unit_cost',
            ]);
        $base = app(InventoryReportService::class)->baseCurrency();

        return $rows->map(fn ($r) => [
            'source' => 'receipt',
            'line_id' => (int) $r->id,
            'item_id' => (int) $r->item_id,
            'item_sku' => $r->item_sku,
            'item_name' => $r->item_name,
            'document_id' => (int) $r->document_id,
            'document_number' => $r->document_number,
            'reference' => $r->po_number,
            'date' => (string) $r->date,
            'supplier_id' => $r->supplier_id ? (int) $r->supplier_id : null,
            'supplier_name' => $r->supplier_name,
            'supplier_code' => $r->supplier_code,
            'quantity' => Decimal::qty((string) $r->quantity),
            'unit_cost' => Decimal::cost((string) $r->unit_cost),
            'currency_code' => $r->po_currency ?: $base,
            'exchange_rate' => null,
            'discount' => null,
            'tax' => null,
            'base_unit_cost' => $r->base_unit_cost !== null ? Decimal::cost((string) $r->base_unit_cost) : null,
            'path' => '/goods-receipts/'.$r->document_id,
            'url' => null,
        ])->all();
    }

    private function bills(array $filters, int $limit): array
    {
        $state = $this->link->state();
        if (! $this->link->readable() || ! Schema::connection('tenant')->hasTable('bill_lines')) {
            return [];
        }
        $financeOrg = (int) $state['finance_organization_id'];
        $itemMap = [];
        if ($filters['item_id'] ?? null) {
            $itemMap = $this->link->financeIds('item', [(int) $filters['item_id']]);
            if ($itemMap === []) {
                return [];
            }
        }
        $supplierMap = [];
        if ($filters['supplier_id'] ?? null) {
            $supplierMap = $this->link->financeIds('supplier', [(int) $filters['supplier_id']]);
            if ($supplierMap === []) {
                return [];
            }
        }
        $rows = DB::connection('tenant')->table('bill_lines as l')
            ->join('bills as b', 'b.id', '=', 'l.bill_id')
            ->join('journal_entries as je', 'je.id', '=', 'b.journal_entry_id')
            ->where('b.organization_id', $financeOrg)
            ->whereNotIn('b.status', ['draft', 'void'])
            ->whereNotNull('je.posted_at')->whereNull('je.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as rev')
                ->whereColumn('rev.reverses_entry_id', 'je.id')->whereNull('rev.deleted_at'))
            ->whereNotNull('l.inventory_item_id')->where('l.qty', '>', 0)
            ->when($itemMap, fn ($q) => $q->whereIn('l.inventory_item_id', array_values($itemMap)))
            ->when($supplierMap, fn ($q) => $q->whereIn('b.supplier_id', array_values($supplierMap)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('b.date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('b.date', '<=', $d))
            ->orderByDesc('b.date')->orderByDesc('l.id')->limit($limit)
            ->get(['l.id', 'l.inventory_item_id', 'l.item_name', 'b.id as bill_id', 'b.number', 'b.reference', 'b.date',
                'b.supplier_id', 'b.supplier_name', 'b.currency_code', 'b.exchange_rate', 'l.qty', 'l.unit_price',
                'l.line_subtotal', 'l.line_tax', 'l.line_discount_amount', 'l.document_discount_allocated', 'l.line_discount']);

        // Translate Finance identities back to Stock; unmapped rows stay visible and flagged.
        $stockItems = $this->reverse('item', $state, $rows->pluck('inventory_item_id')->unique()->all());
        $stockSuppliers = $this->reverse('supplier', $state, $rows->pluck('supplier_id')->filter()->unique()->all());

        return $rows->map(function ($r) use ($stockItems, $stockSuppliers) {
            $qty = (string) $r->qty;
            $net = Decimal::div((string) $r->line_subtotal, $qty);
            $rate = (string) ($r->exchange_rate ?: '1');
            $discount = Decimal::add((string) ($r->line_discount_amount ?? $r->line_discount ?? '0'), (string) ($r->document_discount_allocated ?? '0'));

            return [
                'source' => 'bill',
                'line_id' => (int) $r->id,
                'item_id' => $stockItems[(int) $r->inventory_item_id] ?? null,
                'item_sku' => null,
                'item_name' => $r->item_name,
                'document_id' => (int) $r->bill_id,
                'document_number' => $r->number,
                'reference' => $r->reference,
                'date' => (string) $r->date,
                'supplier_id' => $stockSuppliers[(int) $r->supplier_id] ?? null,
                'supplier_name' => $r->supplier_name,
                'supplier_code' => null,
                'supplier_mapped' => isset($stockSuppliers[(int) $r->supplier_id]),
                'quantity' => Decimal::qty($qty),
                'unit_price' => Decimal::cost((string) $r->unit_price),
                'unit_cost' => Decimal::cost($net),
                'currency_code' => $r->currency_code,
                'exchange_rate' => $rate,
                'discount' => Decimal::cost($discount),
                'tax' => Decimal::cost((string) ($r->line_tax ?? '0')),
                'base_unit_cost' => Decimal::gt($rate, '0') ? Decimal::cost(Decimal::div($net, $rate)) : null,
                'path' => null,
                'url' => config('supplier_terms.finance_url').'/ap/bills/'.$r->bill_id,
            ];
        })->all();
    }

    /** @return array<int, int> finance id => stock id */
    private function reverse(string $type, array $state, array $financeIds): array
    {
        if ($financeIds === []) {
            return [];
        }

        return DB::connection('tenant')->table('integration_master_data_mappings')
            ->where('organization_mapping_uuid', $state['mapping_uuid'])
            ->where('entity_type', $type)->where('status', 'verified')
            ->whereIn('solabooks_record_id', array_map('strval', $financeIds))
            ->pluck('solastock_record_id', 'solabooks_record_id')
            ->mapWithKeys(fn ($stock, $finance) => [(int) $finance => (int) $stock])->all();
    }

    /** Per supplier: last, lowest, highest, quantity-weighted average (base currency), totals. */
    public function summary(array $rows, string $basis): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['supplier_id'] ?? ('name:'.$row['supplier_name']);
            $groups[$key][] = $row;
        }
        $out = [];
        foreach ($groups as $group) {
            usort($group, fn ($a, $b) => strcmp($b['date'], $a['date']) ?: $b['line_id'] <=> $a['line_id']);
            $priced = array_values(array_filter($group, fn ($r) => $r['base_unit_cost'] !== null));
            $qty = '0';
            $value = '0';
            foreach ($priced as $r) {
                $qty = Decimal::add($qty, $r['quantity']);
                $value = Decimal::add($value, Decimal::mul($r['quantity'], $r['base_unit_cost']));
            }
            $costs = array_column($priced, 'base_unit_cost');
            usort($costs, fn ($a, $b) => Decimal::cmp($a, $b));
            $out[] = [
                'supplier_id' => $group[0]['supplier_id'],
                'supplier_name' => $group[0]['supplier_name'],
                'basis' => $basis,
                'documents' => count(array_unique(array_column($group, 'document_id'))),
                'last_date' => $group[0]['date'],
                'last_unit_cost' => $group[0]['unit_cost'],
                'last_currency_code' => $group[0]['currency_code'],
                'last_base_unit_cost' => $group[0]['base_unit_cost'],
                'lowest_base_unit_cost' => $costs[0] ?? null,
                'highest_base_unit_cost' => $costs !== [] ? end($costs) : null,
                'weighted_average_base_unit_cost' => Decimal::gt($qty, '0') ? Decimal::cost(Decimal::div($value, $qty)) : null,
                'total_quantity' => Decimal::qty($qty),
                'total_base_value' => Decimal::value($value),
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_date'], (string) $a['last_date']));

        return $out;
    }
}
