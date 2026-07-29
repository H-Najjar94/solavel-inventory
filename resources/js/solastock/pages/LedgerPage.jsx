import React, { useState } from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, Field, Skeleton } from '../components/ui.jsx';
import { WarehousePicker, ItemPicker } from '../components/pickers.jsx';
import { SourceDocumentLink } from '../components/document.jsx';
import { t } from '../i18n/index.js';

export default function LedgerPage() {
    const [tab, setTab] = useState('ledger');
    const [filters, setFilters] = useState({ item_id: null, warehouse_id: null, direction: '', source_type: '', from: '', to: '', action: '', entity_type: '' });
    const params = {
        per_page: 100,
        item_id: filters.item_id || undefined,
        warehouse_id: filters.warehouse_id || undefined,
        direction: filters.direction || undefined,
        source_type: filters.source_type || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    };
    const auditParams = {
        per_page: 100,
        action: filters.action || undefined,
        entity_type: filters.entity_type || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    };
    const ledger = useApiQuery(['ledger', params], () => api.ledger(params), { fallback: { data: [] } });
    const audit = useApiQuery(['audit-logs', auditParams], () => api.auditLogs(auditParams), { fallback: { data: [] } });
    const rows = Array.isArray(ledger.data) ? ledger.data : (ledger.data?.data ?? []);
    const auditRows = Array.isArray(audit.data) ? audit.data : (audit.data?.data ?? []);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('ledger.title') }]} />
            <header className="page-head"><h1>{t('ledger.title')}</h1><span className="muted">{t('ledger.readOnly')}</span></header>

            <div className="tabs">
                <button className={`tab ${tab === 'ledger' ? 'tab--active' : ''}`} onClick={() => setTab('ledger')}>{t('ledger.movements')}</button>
                <button className={`tab ${tab === 'audit' ? 'tab--active' : ''}`} onClick={() => setTab('audit')}>{t('ledger.activityAudit')}</button>
            </div>

            <div className="panel report-filters">
                {tab === 'ledger' && <Field label={t('ledger.item')}><ItemPicker value={filters.item_id} onChange={(v) => setFilters({ ...filters, item_id: v })} /></Field>}
                {tab === 'ledger' && <Field label={t('ledger.warehouse')}><WarehousePicker value={filters.warehouse_id} onChange={(v) => setFilters({ ...filters, warehouse_id: v })} placeholder={t('ledger.allWarehouses')} /></Field>}
                {tab === 'ledger' && <Field label={t('ledger.direction')}><select className="input" value={filters.direction} onChange={(e) => setFilters({ ...filters, direction: e.target.value })}><option value="">{t('ledger.any')}</option><option value="in">{t('ledger.in')}</option><option value="out">{t('ledger.out')}</option></select></Field>}
                {tab === 'ledger' && <Field label={t('ledger.source')}><input className="input" value={filters.source_type} onChange={(e) => setFilters({ ...filters, source_type: e.target.value })} placeholder={t('ledger.documentClass')} /></Field>}
                {tab === 'audit' && <Field label={t('ledger.action')}><input className="input" value={filters.action} onChange={(e) => setFilters({ ...filters, action: e.target.value })} placeholder={t('ledger.actionPlaceholder')} dir="ltr" /></Field>}
                {tab === 'audit' && <Field label={t('ledger.entity')}><input className="input" value={filters.entity_type} onChange={(e) => setFilters({ ...filters, entity_type: e.target.value })} placeholder={t('ledger.entityPlaceholder')} dir="ltr" /></Field>}
                <Field label={t('ledger.from')}><input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>
                <Field label={t('ledger.to')}><input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>
                <div className="report-filter-actions"><button className="btn" onClick={() => setFilters({ item_id: null, warehouse_id: null, direction: '', source_type: '', from: '', to: '', action: '', entity_type: '' })}>{t('ledger.clear')}</button></div>
            </div>

            {tab === 'ledger' && <div className="panel">
                {ledger.isLoading ? <Skeleton rows={6} /> : rows.length === 0 ? <EmptyState title={t('ledger.empty')} /> : <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>{t('ledger.date')}</th><th>{t('ledger.item')}</th><th>{t('ledger.warehouse')}</th><th>{t('ledger.direction')}</th><th>{t('ledger.quantity')}</th><th>{t('ledger.unitCost')}</th><th>{t('ledger.total')}</th><th>{t('ledger.runningQuantity')}</th><th>{t('ledger.source')}</th></tr></thead>
                    <tbody>{rows.map((r) => (<tr key={r.id}>
                        <td>{r.moved_at}</td>
                        <td>{r.item_name ?? `#${r.item_id}`}{r.item_sku && <span className="muted"> · {r.item_sku}</span>}</td>
                        <td>{r.warehouse_name ?? `#${r.warehouse_id}`}</td>
                        <td>{t(`ledger.${r.direction}`, r.direction)}</td><td>{r.quantity}</td><td>{r.unit_cost}</td><td>{r.total_cost}</td><td>{r.balance_qty_after}</td>
                        <td><SourceDocumentLink sourceType={r.source_type} sourceId={r.source_id} sourceDisplay={r.source_display} sourceRoute={r.source_route} /></td>
                    </tr>))}</tbody></table></div>}
            </div>}

            {tab === 'audit' && <div className="panel">
                {audit.isLoading ? <Skeleton rows={6} /> : auditRows.length === 0 ? <EmptyState title={t('ledger.noAudit')} /> : <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>{t('ledger.time')}</th><th>{t('ledger.actor')}</th><th>{t('ledger.action')}</th><th>{t('ledger.entity')}</th><th>{t('ledger.document')}</th><th>{t('ledger.before')}</th><th>{t('ledger.after')}</th></tr></thead>
                    <tbody>{auditRows.map((r) => <tr key={r.id}><td>{r.created_at}</td><td>{r.actor_user_id ?? t('ledger.system')}</td><td>{r.action}</td><td>{r.entity_type} #{r.entity_id ?? '—'}</td><td>{r.document_ref ?? '—'}</td><td><code>{JSON.stringify(r.before ?? {})}</code></td><td><code>{JSON.stringify(r.after ?? {})}</code></td></tr>)}</tbody></table></div>}
            </div>}
        </section>
    );
}
