import React, { useState } from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, Field, Skeleton } from '../components/ui.jsx';
import { WarehousePicker, ItemPicker } from '../components/pickers.jsx';
import { SourceDocumentLink } from '../components/document.jsx';

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
            <Breadcrumbs items={[{ label: 'Stock Ledger' }]} />
            <header className="page-head"><h1>Stock Ledger</h1><span className="muted">read-only</span></header>

            <div className="tabs">
                <button className={`tab ${tab === 'ledger' ? 'tab--active' : ''}`} onClick={() => setTab('ledger')}>Movements</button>
                <button className={`tab ${tab === 'audit' ? 'tab--active' : ''}`} onClick={() => setTab('audit')}>Activity audit</button>
            </div>

            <div className="panel report-filters">
                {tab === 'ledger' && <Field label="Item"><ItemPicker value={filters.item_id} onChange={(v) => setFilters({ ...filters, item_id: v })} /></Field>}
                {tab === 'ledger' && <Field label="Warehouse"><WarehousePicker value={filters.warehouse_id} onChange={(v) => setFilters({ ...filters, warehouse_id: v })} placeholder="All warehouses" /></Field>}
                {tab === 'ledger' && <Field label="Direction"><select className="input" value={filters.direction} onChange={(e) => setFilters({ ...filters, direction: e.target.value })}><option value="">Any</option><option value="in">In</option><option value="out">Out</option></select></Field>}
                {tab === 'ledger' && <Field label="Source"><input className="input" value={filters.source_type} onChange={(e) => setFilters({ ...filters, source_type: e.target.value })} placeholder="Document class" /></Field>}
                {tab === 'audit' && <Field label="Action"><input className="input" value={filters.action} onChange={(e) => setFilters({ ...filters, action: e.target.value })} placeholder="item.updated" /></Field>}
                {tab === 'audit' && <Field label="Entity"><input className="input" value={filters.entity_type} onChange={(e) => setFilters({ ...filters, entity_type: e.target.value })} placeholder="item" /></Field>}
                <Field label="From"><input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>
                <Field label="To"><input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>
                <div className="report-filter-actions"><button className="btn" onClick={() => setFilters({ item_id: null, warehouse_id: null, direction: '', source_type: '', from: '', to: '', action: '', entity_type: '' })}>Clear</button></div>
            </div>

            {tab === 'ledger' && <div className="panel">
                {ledger.isLoading ? <Skeleton rows={6} /> : rows.length === 0 ? <EmptyState title="No ledger entries" /> : <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Date</th><th>Item</th><th>Warehouse</th><th>Dir</th><th>Qty</th><th>Unit cost</th><th>Total</th><th>Running qty</th><th>Source</th></tr></thead>
                    <tbody>{rows.map((r) => (<tr key={r.id}>
                        <td>{r.moved_at}</td>
                        <td>{r.item_name ?? `#${r.item_id}`}{r.item_sku && <span className="muted"> · {r.item_sku}</span>}</td>
                        <td>{r.warehouse_name ?? `#${r.warehouse_id}`}</td>
                        <td>{r.direction}</td><td>{r.quantity}</td><td>{r.unit_cost}</td><td>{r.total_cost}</td><td>{r.balance_qty_after}</td>
                        <td><SourceDocumentLink sourceType={r.source_type} sourceId={r.source_id} sourceDisplay={r.source_display} sourceRoute={r.source_route} /></td>
                    </tr>))}</tbody></table></div>}
            </div>}

            {tab === 'audit' && <div className="panel">
                {audit.isLoading ? <Skeleton rows={6} /> : auditRows.length === 0 ? <EmptyState title="No audit events" /> : <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th><th>Document</th><th>Before</th><th>After</th></tr></thead>
                    <tbody>{auditRows.map((r) => <tr key={r.id}><td>{r.created_at}</td><td>{r.actor_user_id ?? 'System'}</td><td>{r.action}</td><td>{r.entity_type} #{r.entity_id ?? '—'}</td><td>{r.document_ref ?? '—'}</td><td><code>{JSON.stringify(r.before ?? {})}</code></td><td><code>{JSON.stringify(r.after ?? {})}</code></td></tr>)}</tbody></table></div>}
            </div>}
        </section>
    );
}
