import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, SourceDocumentLink } from '../components/document.jsx';
import { useSettingsTranslation } from '../i18n/useSettingsTranslation.js';

const EVENT_TYPES = [
    '', 'opening_stock.posted', 'opening_stock.reversed', 'adjustment.posted', 'adjustment.reversed',
    'grn.posted', 'transfer.posted', 'stock_count.posted', 'sales_order.confirmed', 'stock_reserved',
    'stock_reservation_released', 'pick_list.picked', 'pack.packed', 'shipment.posted', 'sales_return.posted',
];
const STATUSES = [
    '', 'pending', 'review_required', 'blocked_mapping', 'blocked_contract',
    'ready', 'processing', 'retry_scheduled', 'sent', 'failed', 'dead_letter',
    'ignored', 'superseded', 'reversed',
];

export default function IntegrationEventsPage() {
    const tr = useSettingsTranslation();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.integration.retry');
    const [filters, setFilters] = useState({ status: '', event_type: '', search: '', from: '', to: '' });
    const [selected, setSelected] = useState(null);
    const [reviewNote, setReviewNote] = useState('');
    const status = useApiQuery(['integration-status'], api.integrationStatus, { fallback: null });
    const deliveryEnabled = status.data?.delivery_enabled !== false;

    const { data, isMock, isError, error } = useApiQuery(['integration-events', filters],
        () => api.integrationEvents({ status: filters.status || undefined, event_type: filters.event_type || undefined, search: filters.search || undefined, from: filters.from || undefined, to: filters.to || undefined, per_page: 100 }),
        { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);

    async function act(fn, id, label) {
        try { await fn(id); toast.push(tr(label), 'success'); qc.invalidateQueries({ queryKey: ['integration-events'] }); setSelected(null); }
        catch (e) { toast.push(e.message || tr('settings.common.errorFallback'), 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: tr('integration.title'), to: '/settings/solabooks' }, { label: tr('integration.events.breadcrumb') }]} />
            <header className="page-head"><h1>{tr('integration.events.title')}</h1>{isMock && <span className="badge badge--warn">{tr('integration.events.sampleData')}</span>}</header>
            {status.isError && <div className="panel" role="alert"><strong>{tr('integration.loadFailed')}</strong><p>{status.error?.message || tr('settings.common.errorFallback')}</p></div>}
            {!deliveryEnabled && <div className="panel" role="status"><strong>{tr('integration.safetyHold.title')}</strong><p>{status.data?.delivery_disabled_message || tr('integration.safetyHold.message')}</p></div>}

            <div className="toolbar" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <select className="input" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                    {STATUSES.map((s) => <option key={s} value={s}>{s ? tr(`integration.status.${s}`) : tr('integration.events.allStatuses')}</option>)}
                </select>
                <select className="input" value={filters.event_type} onChange={(e) => setFilters({ ...filters, event_type: e.target.value })}>
                    {EVENT_TYPES.map((type) => <option key={type} value={type}>{type ? tr(`integration.eventType.${type}`, {}, type) : tr('integration.events.allTypes')}</option>)}
                </select>
                <input className="input" value={filters.search} placeholder={tr('integration.events.search')} onChange={(e) => setFilters({ ...filters, search: e.target.value })} />
                <input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                <input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
            </div>

            <div className="panel">
                {isError ? <EmptyState title={tr('integration.loadFailed')} hint={error?.message || tr('settings.common.errorFallback')} /> : rows.length === 0 ? <EmptyState title={tr('integration.events.none')} hint={tr('integration.events.noneHint')} /> : (
                    <table className="data-table">
                        <thead><tr><th>{tr('integration.events.occurred')}</th><th>{tr('integration.events.event')}</th><th>{tr('integration.events.document')}</th><th>{tr('integration.events.status')}</th><th>{tr('integration.events.mapping')}</th><th></th></tr></thead>
                        <tbody>{rows.map((e) => (
                            <tr key={e.id}>
                                <td>{e.occurred_at}</td><td>{tr(`integration.eventType.${e.event_type}`, {}, e.event_type)}</td>
                                <td><SourceDocumentLink sourceType={e.aggregate_type} sourceId={e.aggregate_id} sourceDisplay={e.source_display} sourceRoute={e.source_route} /></td>
                                <td><DocumentStatusBadge status={e.status} /></td>
                                <td>{e.mapping_status === 'incomplete' ? <span className="badge badge--warn">{tr('integration.events.incomplete')}</span> : <span className="badge badge--live">{tr('integration.events.complete')}</span>}</td>
                                <td><button className="btn btn--sm" onClick={() => setSelected(e)}>{tr('integration.events.view')}</button></td>
                            </tr>
                        ))}</tbody>
                    </table>
                )}
            </div>

            {selected && (
                <div className="modal-overlay" onClick={() => setSelected(null)}>
                    <div className="modal" style={{ maxWidth: 640 }} onClick={(ev) => ev.stopPropagation()}>
                        <div className="page-head"><h3 style={{ margin: 0 }}>{tr(`integration.eventType.${selected.event_type}`, {}, selected.event_type)}</h3><DocumentStatusBadge status={selected.status} /></div>
                        <dl className="kv">
                            <dt>{tr('integration.events.uuid')}</dt><dd style={{ fontFamily: 'monospace', fontSize: 12 }}>{selected.event_uuid}</dd>
                            <dt>{tr('integration.events.document')}</dt><dd>{selected.source_display ?? selected.aggregate_number ?? `${selected.aggregate_type} #${selected.aggregate_id}`}</dd>
                            <dt>{tr('integration.events.mapping')}</dt><dd>{selected.mapping_status === 'incomplete' ? tr('integration.events.incomplete') : tr('integration.events.complete')}</dd>
                            <dt>{tr('integration.events.attempts')}</dt><dd>{selected.attempts}</dd>
                            <dt>{tr('integration.events.failureCategory')}</dt><dd>{selected.failure_category ?? '—'}</dd>
                            <dt>{tr('integration.events.lastError')}</dt><dd>{selected.safe_error ?? '—'}</dd>
                            <dt>{tr('integration.events.leaseExpiry')}</dt><dd>{selected.lease_expires_at ?? '—'}</dd>
                        </dl>
                        <strong>{tr('integration.events.payload')}</strong>
                        <pre className="payload-view">{JSON.stringify(selected.payload, null, 2)}</pre>
                        <div className="modal-actions">
                            {gate.allowed && deliveryEnabled && (selected.status === 'pending' || selected.status === 'failed') &&
                                <button className="btn" onClick={() => act(api.ignoreIntegrationEvent, selected.id, 'integration.events.ignored')}>{tr('integration.events.ignore')}</button>}
                            {gate.allowed && deliveryEnabled && <button className="btn" title={tr('integration.events.retryTitle')}
                                onClick={() => act(api.retryIntegrationEvent, selected.id, 'integration.events.retryAttempted')}>{tr('integration.events.retry')}</button>}
                            {selected.status === 'dead_letter' && <label className="field" style={{ width: '100%' }}>
                                <span className="field-label">{tr('integration.events.reviewNote')}</span>
                                <textarea className="input" value={reviewNote} onChange={(e) => setReviewNote(e.target.value)} />
                            </label>}
                            {selected.status === 'dead_letter' && gate.allowed && deliveryEnabled &&
                                <button className="btn" disabled={!reviewNote.trim()} onClick={() => act(
                                    (id) => api.reviewIntegrationDeadLetter(id, reviewNote, false),
                                    selected.id,
                                    'integration.events.reviewed'
                                )}>{tr('integration.events.markReviewed')}</button>}
                            {selected.status === 'dead_letter' && gate.allowed && deliveryEnabled &&
                                <button className="btn btn--primary" disabled={!reviewNote.trim()} onClick={() => act(
                                    (id) => api.reviewIntegrationDeadLetter(id, reviewNote, true),
                                    selected.id,
                                    'integration.events.retry'
                                )}>{tr('integration.events.reviewRetry')}</button>}
                            <button className="btn btn--primary" onClick={() => setSelected(null)}>{tr('integration.events.close')}</button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
