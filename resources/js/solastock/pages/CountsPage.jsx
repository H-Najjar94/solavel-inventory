import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';
import { translateCounts } from '../i18n/counts.js';

export default function CountsPage() {
    const { locale } = useI18n();
    const t = (key, fallback, params) => translateCounts(locale, key, fallback, params);
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['counts'], () => api.counts({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb') }]} />
            <EmptyState title={t('counts.error.title')} hint={t('counts.error.hint')}
                action={<button className="btn" onClick={() => refetch()}>{t('counts.retry')}</button>} />
        </section>
    );
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('counts.breadcrumb') }]} />
            <header className="page-head"><h1>{t('counts.title')}</h1>{isMock && <span className="badge badge--warn">{t('counts.sampleData')}</span>}
                <Link to="/counts/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('counts.new')}</Link></header>
            <p className="muted">{t('counts.description')}</p>
            {rows.length === 0 ? <EmptyState title={t('counts.empty.title')} hint={t('counts.empty.hint')} /> : (
                <table className="data-table"><thead><tr><th>{t('counts.table.number')}</th><th>{t('counts.table.type')}</th><th>{t('counts.table.plan')}</th><th>{t('counts.table.warehouse')}</th><th>{t('counts.table.status')}</th><th>{t('counts.table.adjustment')}</th></tr></thead>
                <tbody>{rows.map((c) => (<tr key={c.id}><td><Link to={`/counts/${c.id}`}><bdi>{c.count_number}</bdi></Link></td><td>{t(`counts.type.${c.count_type}`, c.count_type)}{c.blind_count ? ` · ${t('counts.blindSuffix')}` : ''}</td><td><bdi>{c.scheduled_for ?? '—'}</bdi>{c.abc_class ? ` · ${t('counts.abcPlan', undefined, { class: c.abc_class })}` : ''}</td><td>{c.warehouse_name ?? <bdi>#{c.warehouse_id}</bdi>}</td><td><DocumentStatusBadge status={c.status} /></td><td><bdi>{c.adjustment_number ?? (c.adjustment_id ? `#${c.adjustment_id}` : '—')}</bdi></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
