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
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.suppliers') }]} />
            <header className="page-head">
                <h1>{t('partners.suppliers.list.title')}</h1>{isMock && <span className="badge badge--warn">{t('partners.common.sampleData')}</span>}
                <Link to="/suppliers/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gateReason}>{t('partners.suppliers.list.new')}</Link>
            </header>
            <div className="toolbar"><input className="input" aria-label={t('partners.suppliers.list.search')} placeholder={t('partners.suppliers.list.search')} value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            {isLoading ? <Skeleton /> : isError ? (
                <EmptyState title={t('partners.suppliers.list.loadErrorTitle')} hint={t('partners.suppliers.list.loadErrorHint')}
                    action={<button type="button" className="btn" onClick={() => refetch()}>{t('partners.common.retry')}</button>} />
            ) : list.length === 0 ? <EmptyState title={t('partners.suppliers.list.emptyTitle')} hint={t('partners.suppliers.list.emptyHint')} /> : (
                <table className="data-table"><thead><tr><th>{t('partners.common.code')}</th><th>{t('partners.common.name')}</th><th>{t('partners.common.status')}</th></tr></thead>
                <tbody>{list.map((s) => <tr key={s.id}><td><Link to={`/suppliers/${s.id}`}><bdi>{s.code}</bdi></Link></td><td>{s.name}</td><td>{s.is_active ? t('partners.common.active') : t('partners.common.inactive')}</td></tr>)}</tbody></table>
            )}
        </section>
    );
}
