<?php

namespace App\Services\Purchasing\SupplierTerms;

use App\Models\Tenant\GoodsReceipt;
use App\Services\Reports\InventoryReportService;
use App\Services\Stock\Support\Decimal;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Query\Builder;
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
            // Stats cover every matching posted line, not only the capped lists above.
            'summary' => $this->summary($this->receiptSummary($filters), $this->billSummary($filters)),
            'finance' => ['state' => $this->link->state()['state']],
            'base_currency_code' => app(InventoryReportService::class)->baseCurrency(),
        ];
    }

    /** Posted, non-reversed GRN lines matching the filters (no ordering, no limit). */
    private function receiptQuery(array $filters): Builder
    {
        $org = app(OrganizationContext::class)->idOrFail();

        return DB::connection('tenant')->table('goods_receipt_lines as l')
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
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('g.receipt_date', '<=', $d));
    }

    private function receipts(array $filters, int $limit): array
    {
        $rows = $this->receiptQuery($filters)
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
        $query = $this->billQuery($filters);
        if (! $query) {
            return [];
        }
        $state = $this->link->state();
        $rows = $query
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

    /** Posted, non-reversed SolaCount bill lines matching the filters, or null when not readable / unmapped. */
    private function billQuery(array $filters): ?Builder
    {
        $state = $this->link->state();
        if (! $this->link->readable() || ! Schema::connection('tenant')->hasTable('bill_lines')) {
            return null;
        }
        $itemMap = [];
        if ($filters['item_id'] ?? null) {
            $itemMap = $this->link->financeIds('item', [(int) $filters['item_id']]);
            if ($itemMap === []) {
                return null;
            }
        }
        $supplierMap = [];
        if ($filters['supplier_id'] ?? null) {
            $supplierMap = $this->link->financeIds('supplier', [(int) $filters['supplier_id']]);
            if ($supplierMap === []) {
                return null;
            }
        }

        return DB::connection('tenant')->table('bill_lines as l')
            ->join('bills as b', 'b.id', '=', 'l.bill_id')
            ->join('journal_entries as je', 'je.id', '=', 'b.journal_entry_id')
            ->where('b.organization_id', (int) $state['finance_organization_id'])
            ->whereNotIn('b.status', ['draft', 'void'])
            ->whereNotNull('je.posted_at')->whereNull('je.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as rev')
                ->whereColumn('rev.reverses_entry_id', 'je.id')->whereNull('rev.deleted_at'))
            ->whereNotNull('l.inventory_item_id')->where('l.qty', '>', 0)
            ->when($itemMap, fn ($q) => $q->whereIn('l.inventory_item_id', array_values($itemMap)))
            ->when($supplierMap, fn ($q) => $q->whereIn('b.supplier_id', array_values($supplierMap)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('b.date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('b.date', '<=', $d));
    }

    /** SQL aggregates per supplier over every matching receipt line, keyed like summary(). */
    private function receiptSummary(array $filters): array
    {
        $cost = 'sl.unit_cost';
        $stats = $this->receiptQuery($filters)->groupBy('g.supplier_id')->selectRaw("g.supplier_id, MAX(s.name) supplier_name,
            COUNT(DISTINCT g.id) documents, MIN($cost) lowest, MAX($cost) highest,
            SUM(CASE WHEN $cost IS NOT NULL THEN l.accepted_qty ELSE 0 END) quantity,
            SUM(CASE WHEN $cost IS NOT NULL THEN l.accepted_qty * $cost ELSE 0 END) base_value")->get();
        $ranked = $this->receiptQuery($filters)->selectRaw("g.supplier_id, g.receipt_date date, l.unit_cost, p.currency_code,
            $cost base_unit_cost, ROW_NUMBER() OVER (PARTITION BY g.supplier_id ORDER BY g.receipt_date DESC, l.id DESC) rn");
        $last = DB::connection('tenant')->query()->fromSub($ranked, 'r')->where('r.rn', 1)->get()->keyBy(fn ($r) => (string) $r->supplier_id);
        $base = app(InventoryReportService::class)->baseCurrency();

        $out = [];
        foreach ($stats as $row) {
            $supplierId = $row->supplier_id ? (int) $row->supplier_id : null;
            $l = $last->get((string) $row->supplier_id);
            $out[$supplierId ?? 'name:'.$row->supplier_name] = $this->entry($supplierId, $row->supplier_name, 'receipts', $row, $l ? [
                'date' => (string) $l->date,
                'unit_cost' => Decimal::cost((string) $l->unit_cost),
                'currency_code' => $l->currency_code ?: $base,
                'base_unit_cost' => $l->base_unit_cost !== null ? Decimal::cost((string) $l->base_unit_cost) : null,
            ] : null);
        }

        return $out;
    }

    /** SQL aggregates per SolaCount supplier over every matching bill line, translated to Stock suppliers. */
    private function billSummary(array $filters): array
    {
        $query = $this->billQuery($filters);
        if (! $query) {
            return [];
        }
        // Same rule as the row list: no rate means 1; a non-positive rate leaves the line un-normalized.
        $rate = 'COALESCE(b.exchange_rate, 1)';
        $baseCost = "CASE WHEN $rate > 0 THEN l.line_subtotal / l.qty / $rate END";
        $stats = (clone $query)->groupBy('b.supplier_id')->selectRaw("b.supplier_id, MAX(b.supplier_name) supplier_name,
            COUNT(DISTINCT b.id) documents, MIN($baseCost) lowest, MAX($baseCost) highest,
            SUM(CASE WHEN $rate > 0 THEN l.qty ELSE 0 END) quantity,
            SUM(CASE WHEN $rate > 0 THEN l.line_subtotal / $rate ELSE 0 END) base_value")->get();
        $ranked = (clone $query)->selectRaw("b.supplier_id, b.supplier_name, b.date, l.line_subtotal / l.qty unit_cost, b.currency_code,
            $baseCost base_unit_cost, ROW_NUMBER() OVER (PARTITION BY b.supplier_id ORDER BY b.date DESC, l.id DESC) rn");
        $last = DB::connection('tenant')->query()->fromSub($ranked, 'r')->where('r.rn', 1)->get()->keyBy(fn ($r) => (string) $r->supplier_id);
        $stockSuppliers = $this->reverse('supplier', $this->link->state(), $stats->pluck('supplier_id')->filter()->unique()->all());

        $out = [];
        foreach ($stats as $row) {
            $supplierId = $row->supplier_id ? ($stockSuppliers[(int) $row->supplier_id] ?? null) : null;
            $l = $last->get((string) $row->supplier_id);
            $key = $supplierId ?? 'name:'.$row->supplier_name;
            $entry = $this->entry($supplierId, $l->supplier_name ?? $row->supplier_name, 'bills', $row, $l ? [
                'date' => (string) $l->date,
                'unit_cost' => Decimal::cost((string) $l->unit_cost),
                'currency_code' => $l->currency_code,
                'base_unit_cost' => $l->base_unit_cost !== null ? Decimal::cost((string) $l->base_unit_cost) : null,
            ] : null);
            // Two unmapped SolaCount suppliers with one name stay one row, as the list groups them.
            $out[$key] = isset($out[$key]) && strcmp((string) $out[$key]['last_date'], (string) $entry['last_date']) > 0 ? $out[$key] : $entry;
        }

        return $out;
    }

    private function entry(?int $supplierId, ?string $name, string $basis, object $stats, ?array $last): array
    {
        $qty = (string) ($stats->quantity ?? '0');

        return [
            'supplier_id' => $supplierId,
            'supplier_name' => $name,
            'basis' => $basis,
            'documents' => (int) $stats->documents,
            'last_date' => $last['date'] ?? null,
            'last_unit_cost' => $last['unit_cost'] ?? null,
            'last_currency_code' => $last['currency_code'] ?? null,
            'last_base_unit_cost' => $last['base_unit_cost'] ?? null,
            'lowest_base_unit_cost' => $stats->lowest !== null ? Decimal::cost((string) $stats->lowest) : null,
            'highest_base_unit_cost' => $stats->highest !== null ? Decimal::cost((string) $stats->highest) : null,
            'weighted_average_base_unit_cost' => Decimal::gt($qty, '0') ? Decimal::cost(Decimal::div((string) $stats->base_value, $qty)) : null,
            'total_quantity' => Decimal::qty($qty),
            'total_base_value' => Decimal::value((string) ($stats->base_value ?? '0')),
        ];
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

    /**
     * One row per supplier: the bills-based figures (what was actually paid) when the
     * supplier has posted bills, else its receipt figures; receipt-only suppliers stay listed.
     */
    private function summary(array $receipts, array $bills): array
    {
        $out = array_values(array_replace($receipts, $bills));
        usort($out, fn ($a, $b) => strcmp((string) $b['last_date'], (string) $a['last_date']));

        return $out;
    }
}
