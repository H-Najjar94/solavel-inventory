import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useCan } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { Breadcrumbs, Skeleton, EmptyState, Field } from '../components/ui.jsx';
import { WarehousePicker, ItemPicker } from '../components/pickers.jsx';

const REPORTS = [
    ['inventory-valuation', 'Inventory Valuation', 'Stock value by item & warehouse'],
    ['stock-movement', 'Stock Movement', 'All ledger movements'],
    ['item-ledger', 'Item Ledger', 'Running balance for one item'],
    ['warehouse-stock', 'Warehouse Stock', 'On-hand by warehouse/bin'],
    ['low-stock', 'Low Stock', 'At/below reorder point'],
    ['out-of-stock', 'Out of Stock', 'Nothing available'],
    ['dead-stock', 'Dead Stock', 'No outbound in N days'],
    ['fast-moving', 'Fast Moving', 'Top movers'],
    ['stock-aging', 'Stock Aging', 'Cost-layer age'],
    ['lot-expiry', 'Lot / Expiry', 'Upcoming expiries'],
    ['serial', 'Serial Report', 'Serial status'],
    ['adjustment', 'Adjustment Report', 'Adjustment documents'],
    ['receiving', 'Receiving Report', 'GRNs'],
    ['transfer', 'Transfer Report', 'Transfers'],
    ['count-variance', 'Count Variance', 'Count variances'],
];

const LAST_KEY = 'solastock_last_report';
const needsItem = new Set(['item-ledger']);
const hasDate = new Set(['stock-movement', 'adjustment', 'receiving', 'transfer']);

export default function ReportsPage() {
    const can = useCan();
    const tenant = useTenant();
    const [active, setActive] = useState(localStorage.getItem(LAST_KEY) || 'inventory-valuation');
    const [filters, setFilters] = useState({ warehouse_id: null, item_id: null, from: '', to: '' });
    const [applied, setApplied] = useState(0);

    function selectReport(k) { setActive(k); localStorage.setItem(LAST_KEY, k); setApplied((n) => n + 1); }

    const params = {
        warehouse_id: filters.warehouse_id || undefined,
        item_id: filters.item_id || undefined,
        from: hasDate.has(active) ? (filters.from || undefined) : undefined,
        to: hasDate.has(active) ? (filters.to || undefined) : undefined,
    };

    const { data, isLoading, isError } = useQuery({
        queryKey: ['report', active, applied],
        queryFn: () => api.report(active, params),
        retry: false,
    });
    const report = data?.data;
    const cols = report?.columns ?? [];
    const rows = report?.rows ?? [];
    const summary = report?.summary ?? {};

    const canExport = can('inventory.export_reports') && tenant.hasTenant;
    const exportHref = api.reportExportUrl(active, params);

    function cell(row, c) {
        const v = (row[c] ?? '');
        if (c === 'sku' && row.item_id) return <a href={`/inventory/items/${row.item_id}`}>{v}</a>;
        if (c === 'adjustment_id' && v) return <a href={`/inventory/adjustments/${v}`}>#{v}</a>;
        return typeof v === 'object' ? JSON.stringify(v) : String(v);
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Reports' }]} />
            <header className="page-head"><h1>Reports</h1>{!tenant.hasTenant && <span className="badge badge--warn">no tenant — preview</span>}</header>

            <div className="report-cards">
                {REPORTS.map(([k, label, hint]) => (
                    <button key={k} className={`report-card ${active === k ? 'report-card--active' : ''}`} onClick={() => selectReport(k)}>
                        <div className="report-card-title">{label}</div>
                        <div className="report-card-hint">{hint}</div>
                    </button>
                ))}
            </div>

            <div className="panel report-filters">
                <Field label="Warehouse"><WarehousePicker value={filters.warehouse_id} onChange={(v) => setFilters({ ...filters, warehouse_id: v })} placeholder="All warehouses" /></Field>
                <Field label={needsItem.has(active) ? 'Item (required)' : 'Item'}><ItemPicker value={filters.item_id} onChange={(v) => setFilters({ ...filters, item_id: v })} /></Field>
                {hasDate.has(active) && <Field label="From"><input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>}
                {hasDate.has(active) && <Field label="To"><input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>}
                <div className="report-filter-actions">
                    <button className="btn btn--primary" onClick={() => setApplied((n) => n + 1)}>Run report</button>
                    <button className="btn" onClick={() => { setFilters({ warehouse_id: null, item_id: null, from: '', to: '' }); setApplied((n) => n + 1); }}>Clear</button>
                    <a className="btn" href={canExport ? exportHref : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? 'Download CSV' : (tenant.hasTenant ? 'You lack export permission' : 'Select a tenant to export')}>Export CSV</a>
                </div>
            </div>

            {Object.keys(summary).length > 0 && (
                <div className="widget-grid">
                    {Object.entries(summary).map(([k, v]) => (
                        <div className="widget-card" key={k}><div className="widget-card-label">{k.replace(/_/g, ' ')}</div><div className="widget-card-value">{typeof v === 'object' ? JSON.stringify(v) : String(v)}</div></div>
                    ))}
                </div>
            )}

            <div className="panel">
                {isLoading && <Skeleton rows={6} />}
                {isError && <EmptyState title="No data" hint="This report needs an active tenant with data (Item Ledger needs an item)." />}
                {!isLoading && !isError && (rows.length === 0
                    ? <EmptyState title="No rows" hint="Adjust filters and run again." />
                    : <div style={{ overflowX: 'auto' }}>
                        <table className="data-table">
                            <thead><tr>{cols.map((c) => <th key={c}>{c.replace(/_/g, ' ')}</th>)}</tr></thead>
                            <tbody>{rows.slice(0, 500).map((r, i) => <tr key={i}>{cols.map((c) => <td key={c}>{cell(r, c)}</td>)}</tr>)}</tbody>
                        </table>
                        {rows.length > 500 && <p className="muted">Showing first 500 rows — export CSV for the full set.</p>}
                    </div>
                )}
            </div>
        </section>
    );
}
