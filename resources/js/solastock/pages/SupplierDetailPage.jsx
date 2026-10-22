import React, { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, Skeleton, StatusBadge, EmptyState, MetricCard, Tabs } from '../components/ui.jsx';
import { AccessNotice } from '../components/PlanAccess.jsx';
import PurchaseHistoryPanel from '../components/PurchaseHistoryPanel.jsx';
import VendorPricelistTransfer from '../components/VendorPricelistTransfer.jsx';
import {
    Num, Pager, PreferredBadge, PriceWithCurrency, QueryProblem, TermsStatusBadge, TERM_STATUSES,
    currentTier, qtyText, unitLabel, useSupplierTermsAccess,
} from '../components/SupplierTermsShared.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SupplierDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const gate = useCanCreate('inventory.manage_items');
    const termsAccess = useSupplierTermsAccess();
    const [searchParams, setSearchParams] = useSearchParams();
    const [tab, setTabState] = useState(() => searchParams.get('tab') || 'overview');
    const setTab = (key) => {
        setTabState(key);
        setSearchParams((current) => { const next = new URLSearchParams(current); if (key === 'overview') next.delete('tab'); else next.set('tab', key); return next; }, { replace: true });
    };
    const { data, isLoading } = useApiQuery(['supplier', id], () => api.supplier(id), { fallback: null });
    const s = data;
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!s) return <section className="page"><Breadcrumbs items={[{ label: t('partners.common.suppliers'), to: '/suppliers' }, { label: t('partners.common.notFound') }]} /><EmptyState title={t('partners.suppliers.detail.unavailableTitle')} hint={t('partners.suppliers.detail.unavailableHint')} /></section>;
    const c = s.contact ?? {};
    const m = s.metrics ?? {};
    const editStyle = { marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 };
    const tabs = [
        { key: 'overview', label: t('supplierTerms.tabs.overview') },
        { key: 'items', label: t('supplierTerms.tabs.items') },
        ...(termsAccess.history ? [{ key: 'purchase-history', label: t('supplierTerms.tabs.purchaseHistory') }] : []),
    ];
    const activeTab = tabs.some((x) => x.key === tab) ? tab : 'overview';
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('partners.common.suppliers'), to: '/suppliers' }, { label: s.name }]} />
            <header className="page-head"><h1>{s.name}</h1><StatusBadge active={s.is_active} labels={[t('partners.common.active'), t('partners.common.inactive')]} />
                <Link to={`/suppliers/${s.id}/edit`} className="btn btn--primary" style={editStyle} title={gate.allowed ? '' : t(gate.reason.includes('tenant') ? 'partners.permissions.selectTenant' : 'partners.permissions.denied')}>{t('partners.common.edit')}</Link></header>

            <Tabs tabs={tabs} active={activeTab} onChange={setTab} />

            {activeTab === 'overview' && <>
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
            </>}

            {activeTab === 'items' && <SupplierItemsTab supplierId={s.id} access={termsAccess} />}

            {activeTab === 'purchase-history' && <PurchaseHistoryPanel mode="supplier" id={s.id} />}
        </section>
    );
}

/** Items this supplier sells, with the supplier's own SKU, status and current price. */
function SupplierItemsTab({ supplierId, access }) {
    const { t } = useI18n();
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    useEffect(() => {
        const timer = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 350);
        return () => clearTimeout(timer);
    }, [searchInput]);
    const query = useApiQuery(['supplier-items', String(supplierId), search, status, page],
        () => api.supplierItems(supplierId, { search, status, page, per_page: 25 }),
        { fallback: null, enabled: access.viewTerms, select: (res) => res, placeholderData: (previous) => previous });
    const rows = query.data?.data ?? [];
    const meta = query.data?.meta;

    if (!access.viewTerms) return <AccessNotice reason={access.viewTermsReason ?? 'permission_denied'} />;

    return (
        <div className="card">
            <div className="card-head st-card-head">
                <h3>{t('supplierTerms.supplierItems.title')}</h3>
                <span className="spacer" />
                <div className="st-actions"><VendorPricelistTransfer supplierId={supplierId} compact /></div>
            </div>
            <div className="card-body">
                <div className="filters">
                    <input className="input" type="search" dir="auto" aria-label={t('supplierTerms.supplierItems.search')} placeholder={t('supplierTerms.supplierItems.search')}
                        value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
                    <select className="input" aria-label={t('supplierTerms.common.status')} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                        <option value="">{t('supplierTerms.supplierItems.allStatuses')}</option>
                        {TERM_STATUSES.map((value) => <option key={value} value={value}>{t(`supplierTerms.status.${value}`)}</option>)}
                    </select>
                </div>
                {query.isLoading ? <Skeleton rows={4} /> : query.isError ? <QueryProblem error={query.error} onRetry={query.refetch} /> : rows.length === 0 ? (
                    <EmptyState title={t(search || status ? 'supplierTerms.supplierItems.noMatches' : 'supplierTerms.supplierItems.emptyTitle')}
                        hint={t(search || status ? 'supplierTerms.supplierItems.noMatchesHint' : 'supplierTerms.supplierItems.emptyHint')} />
                ) : (
                    <>
                        <div className="tbl-wrap"><table className="data-table st-table">
                            <thead><tr>
                                <th>{t('supplierTerms.common.item')}</th>
                                <th>{t('supplierTerms.common.status')}</th>
                                <th>{t('supplierTerms.list.supplierProduct')}</th>
                                <th className="num">{t('supplierTerms.list.currentPrice')}</th>
                                <th>{t('supplierTerms.list.moqMultiple')}</th>
                                <th className="num">{t('supplierTerms.list.leadTime')}</th>
                                <th />
                            </tr></thead>
                            <tbody>{rows.map((r) => {
                                const tier = currentTier(r);
                                const unit = unitLabel(r.purchase_unit);
                                return (
                                    <tr key={r.id}>
                                        <td>
                                            <div className="st-cell-stack">
                                                {r.item ? <Link to={`/items/${r.item.id}?tab=suppliers`}>{r.item.name}</Link> : <span>#{r.item_id}</span>}
                                                <span className="st-badges">
                                                    {r.item?.sku && <span className="muted"><Num>{r.item.sku}</Num></span>}
                                                    {r.is_preferred && <PreferredBadge />}
                                                </span>
                                            </div>
                                        </td>
                                        <td><TermsStatusBadge status={r.status} /></td>
                                        <td>
                                            <div className="st-cell-stack">
                                                {r.supplier_sku ? <Num>{r.supplier_sku}</Num> : <span className="muted">—</span>}
                                                {r.supplier_item_name && <span className="muted">{r.supplier_item_name}</span>}
                                            </div>
                                        </td>
                                        <td className="num">{tier ? <div className="st-cell-stack st-cell-stack--end">
                                            <PriceWithCurrency value={tier.unit_cost} currency={r.currency_code} scale={r.price_scale} />
                                            <span className="muted">{t('supplierTerms.list.perUnitFrom', undefined, { unit: unit || '—', qty: qtyText(tier.minimum_qty) })}</span>
                                        </div> : <span className="muted">{t('supplierTerms.list.noCurrentPrice')}</span>}</td>
                                        <td>
                                            <div className="st-cell-stack">
                                                <span>{t('supplierTerms.list.moq')}: <Num>{r.minimum_order_qty ? `${qtyText(r.minimum_order_qty)} ${unit}` : '—'}</Num></span>
                                                <span className="muted">{t('supplierTerms.list.multiple')}: <Num>{r.order_multiple ? `${qtyText(r.order_multiple)} ${unit}` : '—'}</Num></span>
                                            </div>
                                        </td>
                                        <td className="num">{r.lead_time_days !== null && r.lead_time_days !== undefined ? t('supplierTerms.common.days', undefined, { days: r.lead_time_days }) : '—'}</td>
                                        <td>{r.item && <Link className="btn btn--sm" to={`/items/${r.item.id}?tab=suppliers`}>{t('supplierTerms.supplierItems.openTerms')}</Link>}</td>
                                    </tr>
                                );
                            })}</tbody>
                        </table></div>
                        <Pager meta={meta} onPage={setPage} />
                    </>
                )}
            </div>
        </div>
    );
}
