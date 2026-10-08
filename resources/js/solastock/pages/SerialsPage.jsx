import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityPageText, traceabilityStatusText } from '../i18n/traceabilityPages.js';

const STATUSES = ['available', 'reserved', 'picked', 'packed', 'shipped', 'returned', 'damaged', 'quarantined', 'retired'];

export default function SerialsPage() {
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['serials', status, q], () => api.serials({ per_page: 50, status: status || undefined, q: q || undefined }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.serials') }]} />
        <EmptyState title={text('traceabilityPages.serials.loadErrorTitle')} hint={text('traceabilityPages.serials.loadErrorHint')}
            action={<button className="btn" onClick={() => refetch()}>{text('traceabilityPages.common.retry')}</button>} />
    </section>;
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.serials') }]} />
            <header className="page-head"><h1>{text('traceabilityPages.serials.title')}</h1>{isMock && <span className="badge badge--warn">{text('traceabilityPages.common.sampleData')}</span>}</header>
            <div className="filter-bar">
                <input className="input" placeholder={text('traceabilityPages.serials.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{text('traceabilityPages.common.allStatuses')}</option>
                    {STATUSES.map((s) => <option key={s} value={s}>{traceabilityStatusText(locale, s)}</option>)}
                </select>
            </div>
            {rows.length === 0 ? <EmptyState title={text('traceabilityPages.serials.emptyTitle')} hint={text('traceabilityPages.serials.emptyHint')} /> : (
                <table className="data-table"><thead><tr><th>{text('traceabilityPages.serials.table.serial')}</th><th>{text('traceabilityPages.common.item')}</th><th>{text('traceabilityPages.serials.table.location')}</th><th>{text('traceabilityPages.common.status')}</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/traceability/serials/${s.id}`}><bdi>{s.serial}</bdi></Link></td><td><bdi>{s.item ? `${s.item.sku} · ${s.item.name}` : `#${s.item_id}`}</bdi></td><td><bdi>{s.warehouse_id ? `#${s.warehouse_id}` : '—'}</bdi></td><td><span className="badge badge--muted">{traceabilityStatusText(locale, s.status)}</span></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
