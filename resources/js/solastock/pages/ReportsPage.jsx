import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useCan } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { Breadcrumbs, Skeleton, EmptyState, Field } from '../components/ui.jsx';
import { WarehousePicker, ItemPicker } from '../components/pickers.jsx';
import { t } from '../i18n/index.js';

const REPORTS = [
    'inventory-valuation', 'stock-movement', 'item-ledger', 'warehouse-stock', 'low-stock',
    'out-of-stock', 'dead-stock', 'fast-moving', 'stock-aging', 'lot-expiry', 'serial',
    'adjustment', 'receiving', 'transfer', 'count-variance', 'fulfillment-status',
    'pick-list', 'shipment', 'reservation', 'lot-trace', 'serial-lifecycle',
    'expiry-risk', 'recall-impact',
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
    const columnLabels = report?.column_labels ?? {};
    const summaryLabels = report?.summary_labels ?? {};

    const canExport = can('inventory.export_reports') && tenant.hasTenant;
    const exportHref = (format) => api.reportExportUrl(active, params, format);

    async function saveSchedule(e) {
        e.preventDefault();
        const recipients = schedule.recipients.split(',').map((x) => x.trim()).filter(Boolean);
        await api.createReportSchedule({
            report_key: active,
            name: schedule.name || `${t(`reports.${active}.title`, active)} ${t('reports.scheduleSuffix')}`,
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
    const displayKey = (value) => t(`reports.field.${value}`, String(value).replace(/_/g, ' '));

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('reports.title') }]} />
            <header className="page-head"><h1>{t('reports.title')}</h1>{!tenant.hasTenant && <span className="badge badge--warn">{t('reports.noTenantPreview')}</span>}</header>

            <div className="report-cards">
                {REPORTS.map((k) => (
                    <button key={k} className={`report-card ${active === k ? 'report-card--active' : ''}`} onClick={() => selectReport(k)}>
                        <div className="report-card-title">{t(`reports.${k}.title`, k)}</div>
                        <div className="report-card-hint">{t(`reports.${k}.hint`)}</div>
                    </button>
                ))}
            </div>

            <div className="panel report-filters">
                <Field label={t('reports.warehouse')}><WarehousePicker value={filters.warehouse_id} onChange={(v) => setFilters({ ...filters, warehouse_id: v })} placeholder={t('reports.allWarehouses')} /></Field>
                <Field label={t(needsItem.has(active) ? 'reports.itemRequired' : 'reports.item')}><ItemPicker value={filters.item_id} onChange={(v) => setFilters({ ...filters, item_id: v })} /></Field>
                {hasDate.has(active) && <Field label={t('reports.from')}><input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>}
                {hasDate.has(active) && <Field label={t('reports.to')}><input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>}
                {active === 'inventory-valuation' && <Field label={t('reports.asAt')}><input className="input" type="date" value={filters.as_at} onChange={(e) => setFilters({ ...filters, as_at: e.target.value })} /></Field>}
                {active === 'inventory-valuation' && <Field label={t('reports.currency')}><input className="input" value={filters.currency} onChange={(e) => setFilters({ ...filters, currency: e.target.value.toUpperCase().slice(0, 3) })} placeholder={t('reports.baseCurrency')} /></Field>}
                <div className="report-filter-actions">
                    <button className="btn btn--primary" onClick={() => setApplied((n) => n + 1)}>{t('reports.run')}</button>
                    <button className="btn" onClick={() => { setFilters({ warehouse_id: null, item_id: null, from: '', to: '', as_at: '', currency: '' }); setApplied((n) => n + 1); }}>{t('reports.clear')}</button>
                    <a className="btn" href={canExport ? exportHref('csv') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? t('reports.downloadCsv') : (tenant.hasTenant ? t('reports.exportDenied') : t('reports.selectTenantExport'))}>{t('reports.exportCsv')}</a>
                    <a className="btn" href={canExport ? exportHref('xlsx') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? t('reports.downloadExcel') : (tenant.hasTenant ? t('reports.exportDenied') : t('reports.selectTenantExport'))}>{t('reports.exportXls')}</a>
                    <a className="btn" href={canExport ? exportHref('pdf') : undefined}
                        style={{ pointerEvents: canExport ? 'auto' : 'none', opacity: canExport ? 1 : 0.5 }}
                        title={canExport ? t('reports.printPdf') : (tenant.hasTenant ? t('reports.exportDenied') : t('reports.selectTenantExport'))}>{t('reports.printPdfButton')}</a>
                </div>
            </div>

            {canExport && <div className="panel">
                <h2>{t('reports.scheduledDelivery')}</h2>
                <form className="fg2" onSubmit={saveSchedule}>
                    <Field label={t('reports.scheduleName')}><input className="input" value={schedule.name} onChange={(e) => setSchedule({ ...schedule, name: e.target.value })} placeholder={t('reports.weeklyValuation')} /></Field>
                    <Field label={t('reports.recipients')}><input className="input" value={schedule.recipients} onChange={(e) => setSchedule({ ...schedule, recipients: e.target.value })} placeholder={t('reports.recipientsPlaceholder')} dir="ltr" /></Field>
                    <Field label={t('reports.frequency')}><select className="input" value={schedule.frequency} onChange={(e) => setSchedule({ ...schedule, frequency: e.target.value })}><option value="daily">{t('reports.daily')}</option><option value="weekly">{t('reports.weekly')}</option><option value="monthly">{t('reports.monthly')}</option></select></Field>
                    <Field label={t('reports.format')}><select className="input" value={schedule.format} onChange={(e) => setSchedule({ ...schedule, format: e.target.value })}><option value="csv">{t('reports.formatCsv')}</option><option value="xlsx">{t('reports.formatXlsx')}</option><option value="pdf">{t('reports.formatPdf')}</option></select></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">{t('reports.saveSchedule')}</button></div>
                </form>
                {schedules.length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{t('reports.name')}</th><th>{t('reports.report')}</th><th>{t('reports.frequency')}</th><th>{t('reports.recipients')}</th><th>{t('reports.lastStatus')}</th><th>{t('reports.nextRun')}</th><th></th></tr></thead><tbody>
                    {schedules.map((s) => <tr key={s.id}><td>{s.name}</td><td>{t(`reports.${s.report_key}.title`, s.report_key)}</td><td>{t(`reports.${s.frequency}`, s.frequency)}</td><td>{(s.recipients ?? []).join(', ') || '—'}</td><td>{s.last_status ? t(`reports.status.${s.last_status}`, s.last_status) : t('reports.notRun')}</td><td>{s.next_run_at ?? '—'}</td><td><button className="btn btn--sm" onClick={() => runSchedule(s.id)}>{t('reports.runNow')}</button></td></tr>)}
                </tbody></table>}
            </div>}

            {Object.keys(summary).length > 0 && (
                <div className="widget-grid">
                    {Object.entries(summary).map(([k, v]) => (
                        <div className="widget-card" key={k}><div className="widget-card-label">{summaryLabels[k] ?? displayKey(k)}</div><div className="widget-card-value"><bdi>{typeof v === 'object' ? JSON.stringify(v) : String(v)}</bdi></div></div>
                    ))}
                </div>
            )}

            <div className="panel">
                {isLoading && <Skeleton rows={6} />}
                {isError && <EmptyState title={t('reports.noData')} hint={t('reports.noDataHint')} />}
                {!isLoading && !isError && (rows.length === 0
                    ? <EmptyState title={t('reports.noRows')} hint={t('reports.noRowsHint')} />
                    : <div style={{ overflowX: 'auto' }}>
                        <table className="data-table">
                            <thead><tr>{cols.map((c) => <th key={c}>{columnLabels[c] ?? displayKey(c)}</th>)}</tr></thead>
                            <tbody>{rows.slice(0, 500).map((r, i) => <tr key={i}>{cols.map((c) => <td key={c}>{cell(r, c)}</td>)}</tr>)}</tbody>
                        </table>
                        {rows.length > 500 && <p className="muted">{t('reports.first500')}</p>}
                    </div>
                )}
            </div>
        </section>
    );
}
