import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, SourceDocumentLink } from '../components/document.jsx';

const EVENT_TYPES = ['', 'opening_stock.posted', 'opening_stock.reversed', 'adjustment.posted', 'adjustment.reversed', 'grn.posted', 'transfer.posted', 'stock_count.posted'];
const STATUSES = ['', 'pending', 'sent', 'failed', 'ignored'];

export default function IntegrationEventsPage() {
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.integration.retry');
    const [filters, setFilters] = useState({ status: '', event_type: '', from: '', to: '' });
    const [selected, setSelected] = useState(null);

    const { data, isMock } = useApiQuery(['integration-events', filters],
        () => api.integrationEvents({ status: filters.status || undefined, event_type: filters.event_type || undefined, from: filters.from || undefined, to: filters.to || undefined, per_page: 100 }),
        { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);

    async function act(fn, id, label) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['integration-events'] }); setSelected(null); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Integration', to: '/settings/solabooks' }, { label: 'Events' }]} />
            <header className="page-head"><h1>SolaBooks Integration Events</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>

            <div className="toolbar" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <select className="input" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                    {STATUSES.map((s) => <option key={s} value={s}>{s || 'All statuses'}</option>)}
                </select>
                <select className="input" value={filters.event_type} onChange={(e) => setFilters({ ...filters, event_type: e.target.value })}>
                    {EVENT_TYPES.map((t) => <option key={t} value={t}>{t || 'All event types'}</option>)}
                </select>
                <input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                <input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
            </div>

            <div className="panel">
                {rows.length === 0 ? <EmptyState title="No events" hint="Events appear when inventory documents post (with an active tenant)." /> : (
                    <table className="data-table">
                        <thead><tr><th>Occurred</th><th>Event</th><th>Document</th><th>Status</th><th>Mapping</th><th></th></tr></thead>
                        <tbody>{rows.map((e) => (
                            <tr key={e.id}>
                                <td>{e.occurred_at}</td><td>{e.event_type}</td>
                                <td><SourceDocumentLink sourceType={e.aggregate_type} sourceId={e.aggregate_id} /></td>
                                <td><DocumentStatusBadge status={e.status} /></td>
                                <td>{e.mapping_status === 'incomplete' ? <span className="badge badge--warn">incomplete</span> : <span className="badge badge--live">complete</span>}</td>
                                <td><button className="btn btn--sm" onClick={() => setSelected(e)}>View</button></td>
                            </tr>
                        ))}</tbody>
                    </table>
                )}
            </div>

            {selected && (
                <div className="modal-overlay" onClick={() => setSelected(null)}>
                    <div className="modal" style={{ maxWidth: 640 }} onClick={(ev) => ev.stopPropagation()}>
                        <div className="page-head"><h3 style={{ margin: 0 }}>{selected.event_type}</h3><DocumentStatusBadge status={selected.status} /></div>
                        <dl className="kv">
                            <dt>Event UUID</dt><dd style={{ fontFamily: 'monospace', fontSize: 12 }}>{selected.event_uuid}</dd>
                            <dt>Document</dt><dd>{selected.aggregate_type} #{selected.aggregate_id} {selected.aggregate_number ? `(${selected.aggregate_number})` : ''}</dd>
                            <dt>Mapping</dt><dd>{selected.mapping_status}</dd>
                            <dt>Attempts</dt><dd>{selected.attempts}</dd>
                            <dt>Last error</dt><dd>{selected.last_error ?? '—'}</dd>
                        </dl>
                        <strong>Payload</strong>
                        <pre className="payload-view">{JSON.stringify(selected.payload, null, 2)}</pre>
                        <div className="modal-actions">
                            {gate.allowed && (selected.status === 'pending' || selected.status === 'failed') &&
                                <button className="btn" onClick={() => act(api.ignoreIntegrationEvent, selected.id, 'Event ignored.')}>Ignore</button>}
                            {gate.allowed && <button className="btn" title="Delivery worker not implemented yet"
                                onClick={() => act(api.retryIntegrationEvent, selected.id, 'Retry attempted.')}>Retry</button>}
                            <button className="btn btn--primary" onClick={() => setSelected(null)}>Close</button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
