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
        <section className="page index-page customers-page">
            <Breadcrumbs items={[{ label: t('partners.common.customers') }]} />
            <header className="page-head customers-heading">
                <div><h1>{t('partners.customers.list.title')}</h1><p>{t('partners.customers.list.designIntro')}</p></div>
                {isMock && <span className="badge badge--warn">{t('partners.common.sampleData')}</span>}
                {gate.allowed ? <Link to="/customers/new" className="btn btn--primary customers-new"><span aria-hidden="true">+</span> {t('partners.customers.list.new')}</Link> : <button type="button" className="btn btn--primary customers-new" disabled title={gateReason}>{t('partners.customers.list.new')}</button>}
            </header>
            <section className="card customers-directory">
                <div className="customers-toolbar">
                    <label className="customers-search"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input className="input" aria-label={t('partners.customers.list.search')} placeholder={t('partners.customers.list.search')} value={search} onChange={(e) => setSearch(e.target.value)} /></label>
                    {search && <button type="button" className="btn customers-clear" onClick={() => setSearch('')}>{t('partners.customers.list.clearSearch')}</button>}
                    {!isLoading && !isError && <span className="customers-count">{t('partners.customers.list.shown',undefined,{count:list.length})}</span>}
                </div>
            {isLoading ? <Skeleton /> : isError ? (
                <EmptyState title={t('partners.customers.list.loadErrorTitle')} hint={t('partners.customers.list.loadErrorHint')}
                    action={<button type="button" className="btn" onClick={() => refetch()}>{t('partners.common.retry')}</button>} />
            ) : list.length === 0 ? <div className="customers-empty"><div className="customers-empty-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5M8 7h2m4 0h2M8 11h2m4 0h2"/></svg></div><EmptyState title={t(search?'partners.customers.list.noMatches':'partners.customers.list.emptyTitle')} hint={t(search?'partners.customers.list.noMatchesHint':'partners.customers.list.emptyHint')}
                action={search ? <button className="btn" type="button" onClick={() => setSearch('')}>{t('partners.customers.list.clearSearch')}</button> : gate.allowed ? <Link className="btn btn--primary" to="/customers/new">+ {t('partners.customers.list.new')}</Link> : undefined} /></div> : (
                <div className="tbl-wrap customers-table"><table className="data-table"><thead><tr><th>{t('partners.common.code')}</th><th>{t('partners.common.name')}</th><th>{t('partners.common.email')}</th><th>{t('partners.common.status')}</th></tr></thead>
                <tbody>{list.map((s) => <tr key={s.id}><td><Link to={`/customers/${s.id}`}><bdi>{s.code}</bdi></Link></td><td><Link className="customers-name" to={`/customers/${s.id}`}>{s.name}</Link></td><td><bdi>{s.contact?.email ?? '—'}</bdi></td><td><span className={`customers-status ${s.is_active?'is-active':''}`}><span aria-hidden="true" />{s.is_active ? t('partners.common.active') : t('partners.common.inactive')}</span></td></tr>)}</tbody></table></div>
            )}
            </section>
        </section>
    );
}
