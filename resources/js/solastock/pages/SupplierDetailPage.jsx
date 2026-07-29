import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState, MetricCard } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SupplierDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const { data, isLoading } = useApiQuery(['supplier', id], () => api.supplier(id), { fallback: null });
    const s = data;
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!s) return <section className="page"><Breadcrumbs items={[{ label: t('partners.common.suppliers'), to: '/suppliers' }, { label: t('partners.common.notFound') }]} /><EmptyState title={t('partners.suppliers.detail.unavailableTitle')} hint={t('partners.suppliers.detail.unavailableHint')} /></section>;
    const c = s.contact ?? {};
    const m = s.metrics ?? {};
    const editStyle = { marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 };
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.suppliers'), to: '/suppliers' }, { label: s.name }]} />
            <header className="page-head"><h1>{s.name}</h1><StatusBadge active={s.is_active} labels={[t('partners.common.active'), t('partners.common.inactive')]} />
                <Link to={`/suppliers/${s.id}/edit`} className="btn btn--primary" style={editStyle} title={gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied')}>{t('partners.common.edit')}</Link></header>
            <div className="panel"><dl className="kv">
                <dt>{t('partners.common.code')}</dt><dd><bdi>{s.code}</bdi></dd><dt>{t('partners.common.email')}</dt><dd><bdi>{c.email ?? '—'}</bdi></dd>
                <dt>{t('partners.common.phone')}</dt><dd><bdi>{c.phone ?? '—'}</bdi></dd><dt>{t('partners.common.address')}</dt><dd>{c.address ?? '—'}</dd>
                <dt>{t('partners.common.taxNumber')}</dt><dd><bdi>{c.tax_number ?? '—'}</bdi></dd><dt>{t('partners.common.currency')}</dt><dd><bdi>{c.currency ?? '—'}</bdi></dd>
                <dt>{t('partners.common.paymentTerms')}</dt><dd>{c.payment_terms ?? '—'}</dd>
            </dl></div>
            <div className="panel">
                <h2>{t('partners.suppliers.detail.performance')}</h2>
                <div className="metric-cards">
                    <MetricCard label={t('partners.suppliers.detail.purchaseOrders')} value={m.purchase_orders ?? 0} />
                    <MetricCard label={t('partners.suppliers.detail.openPurchaseOrders')} value={m.open_purchase_orders ?? 0} />
                    <MetricCard label={t('partners.suppliers.detail.postedReceipts')} value={m.posted_receipts ?? 0} />
                    <MetricCard label={t('partners.suppliers.detail.averageLeadDays')} value={m.average_lead_days ?? '—'} />
                    <MetricCard label={t('partners.suppliers.detail.onTimeRate')} value={m.on_time_rate == null ? '—' : `${m.on_time_rate}%`} tone={m.on_time_rate >= 90 ? 'ok' : (m.on_time_rate == null ? undefined : 'warn')} />
                </div>
            </div>
        </section>
    );
}
