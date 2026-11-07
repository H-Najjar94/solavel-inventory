import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PacksPage() {
    const { t } = useI18n();
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['packs', sort.key, sort.dir], () => api.packs({ ...sort.params, per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page index-page">
            <Breadcrumbs items={[{ label: t('fulfillment.packing.breadcrumb', 'Packing') }]} />
            <header className="page-head"><h1>{t('fulfillment.packing.title', 'Packs')}</h1>{isMock && <span className="badge badge--warn">{t('fulfillment.common.sampleData', 'Sample data')}</span>}</header>
            <p className="muted">{t('fulfillment.packing.description', 'Packs cartonize picked stock. Packing does not move stock; the stock OUT happens at shipment.')}</p>
            {rows.length === 0 ? <EmptyState title={t('fulfillment.packing.emptyTitle', 'No packs')} hint={t('fulfillment.packing.emptyHint', 'Create a pack from a picked pick list.')} /> : (
                <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('fulfillment.packing.number', 'Pack #')}</SortTh><SortTh sort={sort} col="order">{t('fulfillment.common.salesOrder', 'Sales order')}</SortTh><SortTh sort={sort} col="carrier">{t('fulfillment.common.carrier', 'Carrier')}</SortTh><SortTh sort={sort} col="status">{t('fulfillment.common.status', 'Status')}</SortTh></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/packs/${p.id}`}>{p.pack_number}</Link></td><td>{p.sales_order_number ?? <bdi>#{p.sales_order_id}</bdi>}</td><td>{p.carrier ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td></tr>))}</tbody></table></div>
            )}
        </section>
    );
}
