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
    ['fulfillment-status', 'Fulfillment Status', 'Sales order fulfillment progress'],
    ['pick-list', 'Pick List Report', 'Picking workload'],
    ['shipment', 'Shipment Report', 'Shipment throughput'],
    ['reservation', 'Reservation Report', 'Active stock reservations'],
    ['lot-trace', 'Lot Trace Report', 'Lot origins and movement impact'],
    ['serial-lifecycle', 'Serial Lifecycle', 'Serial status and ownership trail'],
    ['expiry-risk', 'Expiry Risk', 'Lots expiring inside the selected window'],
    ['recall-impact', 'Recall Impact', 'Recall case stock impact'],
];

const LAST_KEY = 'solastock_last_report';
const needsItem = new Set(['item-ledger']);
const hasDate = new Set(['stock-movement', 'adjustment', 'receiving', 'transfer', 'fulfillment-status', 'shipment']);

export default function ReportsPage() {
    const can = useCan();
    const tenant = useTenant();
    const [active, setActive] = useState(localStorage.getItem(LAST_KEY) || 'inventory-valuation');
    const [filters, setFilters] = useState({ warehouse_id: null, item_id: null, from: '', to: '', as_at: '', currency: '' });
    const [applied, setApplied] = useState(0);
    const [schedule, setSchedule] = useState({ name: '', recipients: '', frequency: 'weekly', format: 'csv' });

    function selectReport(k) { setActive(k); localStorage.setItem(LAST_KEY, k); setApplied((n) => n + 1); }

    const params = {
        warehouse_id: filters.warehouse_id || undefined,
        item_id: filters.item_id || undefined,
        from: hasDate.has(active) ? (filters.from || undefined) : undefined,
        to: hasDate.has(active) ? (filters.to || undefined) : undefined,
        as_at: active === 'inventory-valuation' ? (filters.as_at || undefined) : undefined,
        currency: active === 'inventory-valuation' ? (filters.currency || undefined) : undefined,
    };

    const { data, isLoading, isError } = useQuery({
        queryKey: ['report', active, applied],
        queryFn: () => api.report(active, params),
        retry: false,
    });
    const report = data?.data;
    const schedulesQuery = useQuery({
        queryKey: ['report-schedules'],
        queryFn: api.reportSchedules,
        retry: false,
    });
    const schedules = schedulesQuery.data?.data?.schedules ?? [];
    const cols = report?.columns ?? [];
    const rows = report?.rows ?? [];
    const summary = report?.summary ?? {};

    const canExport = can('inventory.export_reports') && tenant.hasTenant;
    const exportHref = (format) => api.reportExportUrl(active, params, format);

    async function saveSchedule(e) {
        e.preventDefault();
        const recipients = schedule.recipients.split(',').map((x) => x.trim()).filter(Boolean);
        await api.createReportSchedule({
            report_key: active,
            name: schedule.name || `${REPORTS.find((r) => r[0] === active)?.[1] ?? active} schedule`,
            filters: params,
            recipients,
            frequency: schedule.frequency,
            format: schedule.format,
            is_active: true,
        });
        setSchedule({ name: '', recipients: '', frequency: 'weekly', format: 'csv' });
        await schedulesQuery.refetch();
    }

    async function runSchedule(id) {
        await api.runReportSchedule(id);
        await schedulesQuery.refetch();
    }

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
                {active === 'inventory-valuation' && <Field label="As at"><input className="input" type="date" value={filters.as_at} onChange={(e) => setFilters({ ...filters, as_at: e.target.value })} /></Field>}
                {active === 'inventory-valuation' && <Field label="Currency"><input className="input" value={filters.currency} onChange={(e) => setFilters({ ...filters, currency: e.target.value.toUpperCase().slice(0, 3) })} placeholder="Base" /></Field>}
                <div className="report-filter-actions">
                    <button className="btn btn--primary" onClick={() => setApplied((n) => n + 1)}>Run report</button>
                    <button className="btn" onClick={() => { setFilters({ warehouse_id: null, item_id: null, from: '', to: '', as_at: '', currency: '' }); setApplied((n) => n + 1); }}>Clear</button>
                    <a className="btn" href={canExport ? exportHref('csv') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? 'Download CSV' : (tenant.hasTenant ? 'You lack export permission' : 'Select a tenant to export')}>Export CSV</a>
                    <a className="btn" href={canExport ? exportHref('xlsx') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? 'Download Excel' : (tenant.hasTenant ? 'You lack export permission' : 'Select a tenant to export')}>Export XLS</a>
                    <a className="btn" href={canExport ? exportHref('pdf') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? 'Print or save PDF' : (tenant.hasTenant ? 'You lack export permission' : 'Select a tenant to export')}>Print PDF</a>
                </div>
            </div>

            {canExport && <div className="panel">
                <h2>Scheduled delivery</h2>
                <form className="fg2" onSubmit={saveSchedule}>
                    <Field label="Schedule name"><input className="input" value={schedule.name} onChange={(e) => setSchedule({ ...schedule, name: e.target.value })} placeholder="Weekly valuation" /></Field>
                    <Field label="Recipients"><input className="input" value={schedule.recipients} onChange={(e) => setSchedule({ ...schedule, recipients: e.target.value })} placeholder="ops@example.com, finance@example.com" /></Field>
                    <Field label="Frequency"><select className="input" value={schedule.frequency} onChange={(e) => setSchedule({ ...schedule, frequency: e.target.value })}><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></Field>
                    <Field label="Format"><select className="input" value={schedule.format} onChange={(e) => setSchedule({ ...schedule, format: e.target.value })}><option value="csv">CSV</option><option value="xlsx">XLSX</option><option value="pdf">PDF</option></select></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">Save schedule</button></div>
                </form>
                {schedules.length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Name</th><th>Report</th><th>Frequency</th><th>Recipients</th><th>Last status</th><th>Next run</th><th></th></tr></thead><tbody>
                    {schedules.map((s) => <tr key={s.id}><td>{s.name}</td><td>{s.report_key}</td><td>{s.frequency}</td><td>{(s.recipients ?? []).join(', ') || '—'}</td><td>{s.last_status ?? 'Not run'}</td><td>{s.next_run_at ?? '—'}</td><td><button className="btn btn--sm" onClick={() => runSchedule(s.id)}>Run now</button></td></tr>)}
                </tbody></table>}
            </div>}

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
