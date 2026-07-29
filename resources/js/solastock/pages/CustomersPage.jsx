import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function CustomersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_sales_orders');
    const [search, setSearch] = useState('');
    const { data, isMock, isLoading, isError, refetch } = useApiQuery(['customers', search], () => api.customers({ search, per_page: 50 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    const gateReason = gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied');
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.customers') }]} />
            <header className="page-head"><h1>{t('partners.customers.list.title')}</h1>{isMock && <span className="badge badge--warn">{t('partners.common.sampleData')}</span>}
                <Link to="/customers/new" className="btn btn--primary" style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }} title={gateReason}>{t('partners.customers.list.new')}</Link></header>
            <div className="toolbar"><input className="input" aria-label={t('partners.customers.list.search')} placeholder={t('partners.customers.list.search')} value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            {isLoading ? <Skeleton /> : isError ? (
                <EmptyState title={t('partners.customers.list.loadErrorTitle')} hint={t('partners.customers.list.loadErrorHint')}
                    action={<button type="button" className="btn" onClick={() => refetch()}>{t('partners.common.retry')}</button>} />
            ) : list.length === 0 ? <EmptyState title={t('partners.customers.list.emptyTitle')} hint={t('partners.customers.list.emptyHint')} /> : (
                <table className="data-table"><thead><tr><th>{t('partners.common.code')}</th><th>{t('partners.common.name')}</th><th>{t('partners.common.email')}</th><th>{t('partners.common.status')}</th></tr></thead>
                <tbody>{list.map((c) => <tr key={c.id}><td><Link to={`/customers/${c.id}`}><bdi>{c.code}</bdi></Link></td><td>{c.name}</td><td><bdi>{c.contact?.email ?? '—'}</bdi></td><td>{c.is_active ? t('partners.common.active') : t('partners.common.inactive')}</td></tr>)}</tbody></table>
            )}
        </section>
    );
}
