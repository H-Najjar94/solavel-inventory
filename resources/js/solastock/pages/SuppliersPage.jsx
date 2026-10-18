import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SuppliersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_items');
    const [search, setSearch] = useState('');
    const { data, isMock, isLoading, isError, refetch } = useApiQuery(['suppliers', search], () => api.suppliers({ search, per_page: 50 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    const gateReason = gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied');
    return (
        <section className="page index-page suppliers-page">
            <Breadcrumbs items={[{ label: t('partners.common.suppliers') }]} />
            <header className="page-head suppliers-heading">
                <div><h1>{t('partners.suppliers.list.title')}</h1><p>{t('partners.suppliers.list.designIntro')}</p></div>
                {isMock && <span className="badge badge--warn">{t('partners.common.sampleData')}</span>}
                {gate.allowed ? <Link to="/suppliers/new" className="btn btn--primary suppliers-new"><span aria-hidden="true">+</span> {t('partners.suppliers.list.new')}</Link> : <button type="button" className="btn btn--primary suppliers-new" disabled title={gateReason}>{t('partners.suppliers.list.new')}</button>}
            </header>
            <section className="card suppliers-directory">
                <div className="suppliers-toolbar">
                    <label className="suppliers-search"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input className="input" aria-label={t('partners.suppliers.list.search')} placeholder={t('partners.suppliers.list.search')} value={search} onChange={(e) => setSearch(e.target.value)} /></label>
                    {search && <button type="button" className="btn suppliers-clear" onClick={() => setSearch('')}>{t('partners.suppliers.list.clearSearch')}</button>}
                    {!isLoading && !isError && <span className="suppliers-count">{t('partners.suppliers.list.shown',undefined,{count:list.length})}</span>}
                </div>
            {isLoading ? <Skeleton /> : isError ? (
                <EmptyState title={t('partners.suppliers.list.loadErrorTitle')} hint={t('partners.suppliers.list.loadErrorHint')}
                    action={<button type="button" className="btn" onClick={() => refetch()}>{t('partners.common.retry')}</button>} />
            ) : list.length === 0 ? <div className="suppliers-empty"><div className="suppliers-empty-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M4 21V5l8-3 8 3v16M2 21h20M9 21v-5h6v5M8 7h2m4 0h2M8 11h2m4 0h2"/></svg></div><EmptyState title={t(search?'partners.suppliers.list.noMatches':'partners.suppliers.list.emptyTitle')} hint={t(search?'partners.suppliers.list.noMatchesHint':'partners.suppliers.list.emptyHint')}
                action={search ? <button className="btn" type="button" onClick={() => setSearch('')}>{t('partners.suppliers.list.clearSearch')}</button> : gate.allowed ? <Link className="btn btn--primary" to="/suppliers/new">+ {t('partners.suppliers.list.new')}</Link> : undefined} /></div> : (
                <div className="tbl-wrap suppliers-table"><table className="data-table"><thead><tr><th>{t('partners.common.code')}</th><th>{t('partners.common.name')}</th><th>{t('partners.common.status')}</th></tr></thead>
                <tbody>{list.map((s) => <tr key={s.id}><td><Link to={`/suppliers/${s.id}`}><bdi>{s.code}</bdi></Link></td><td><Link className="suppliers-name" to={`/suppliers/${s.id}`}>{s.name}</Link></td><td><span className={`suppliers-status ${s.is_active?'is-active':''}`}><span aria-hidden="true" />{s.is_active ? t('partners.common.active') : t('partners.common.inactive')}</span></td></tr>)}</tbody></table></div>
            )}
            </section>
        </section>
    );
}
