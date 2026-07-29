import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityPageText, traceabilityStatusText } from '../i18n/traceabilityPages.js';

export default function LotsPage() {
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const [status, setStatus] = useState('');
    const [q, setQ] = useState('');
    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['lots', status, q], () => api.lots({ per_page: 50, status: status || undefined, q: q || undefined }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.lots') }]} />
        <EmptyState title={text('traceabilityPages.lots.loadErrorTitle')} hint={text('traceabilityPages.lots.loadErrorHint')}
            action={<button className="btn" onClick={() => refetch()}>{text('traceabilityPages.common.retry')}</button>} />
    </section>;
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.lots') }]} />
            <header className="page-head"><h1>{text('traceabilityPages.lots.title')}</h1>{isMock && <span className="badge badge--warn">{text('traceabilityPages.common.sampleData')}</span>}</header>
            <div className="filter-bar">
                <input className="input" placeholder={text('traceabilityPages.lots.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
                    <option value="">{text('traceabilityPages.common.allStatuses')}</option>
                    {['active', 'expired', 'quarantined', 'consumed', 'recalled'].map((s) => <option key={s} value={s}>{traceabilityStatusText(locale, s)}</option>)}
                </select>
            </div>
            {rows.length === 0 ? <EmptyState title={text('traceabilityPages.lots.emptyTitle')} hint={text('traceabilityPages.lots.emptyHint')} /> : (
                <table className="data-table"><thead><tr><th>{text('traceabilityPages.lots.table.code')}</th><th>{text('traceabilityPages.common.item')}</th><th>{text('traceabilityPages.lots.table.expiry')}</th><th>{text('traceabilityPages.common.status')}</th></tr></thead>
                <tbody>{rows.map((l) => (<tr key={l.id}><td><Link to={`/traceability/lots/${l.id}`}><bdi>{l.lot_code}</bdi></Link></td><td><bdi>{l.item ? `${l.item.sku} · ${l.item.name}` : `#${l.item_id}`}</bdi></td><td><bdi>{l.expiry_date ?? '—'}</bdi></td><td><span className="badge badge--muted">{traceabilityStatusText(locale, l.status)}</span></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
