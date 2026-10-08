import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function CustomerDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_sales_orders');
    const { data, isLoading } = useApiQuery(['customer', id], () => api.customer(id), { fallback: null });
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!data) return <section className="page"><Breadcrumbs items={[{ label: t('partners.common.customers'), to: '/customers' }, { label: t('partners.common.notFound') }]} /><EmptyState title={t('partners.customers.detail.unavailableTitle')} hint={t('partners.customers.detail.unavailableHint')} /></section>;
    const c = data.contact ?? {};
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.customers'), to: '/customers' }, { label: data.name }]} />
            <header className="page-head"><h1>{data.name}</h1><StatusBadge active={data.is_active} labels={[t('partners.common.active'), t('partners.common.inactive')]} />
                <Link to={`/customers/${data.id}/edit`} className="btn btn--primary" style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }} title={gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied')}>{t('partners.common.edit')}</Link></header>
            <div className="panel"><dl className="kv">
                <dt>{t('partners.common.code')}</dt><dd><bdi>{data.code}</bdi></dd><dt>{t('partners.common.email')}</dt><dd><bdi>{c.email ?? '—'}</bdi></dd>
                <dt>{t('partners.common.phone')}</dt><dd><bdi>{c.phone ?? '—'}</bdi></dd><dt>{t('partners.common.address')}</dt><dd>{c.address ?? '—'}</dd>
                <dt>{t('partners.common.taxNumber')}</dt><dd><bdi>{c.tax_number ?? '—'}</bdi></dd><dt>{t('partners.common.currency')}</dt><dd><bdi>{c.currency ?? '—'}</bdi></dd>
                <dt>{t('partners.common.paymentTerms')}</dt><dd>{c.payment_terms ?? '—'}</dd><dt>{t('partners.common.notes')}</dt><dd>{c.notes ?? '—'}</dd>
            </dl></div>
        </section>
    );
}
