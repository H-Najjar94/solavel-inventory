import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PickListsPage() {
    const { t } = useI18n();
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['pick-lists', sort.key, sort.dir], () => api.pickLists({ ...sort.params, per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page index-page">
            <Breadcrumbs items={[{ label: t('fulfillment.picking.breadcrumb', 'Picking') }]} />
            <header className="page-head"><h1>{t('fulfillment.picking.title', 'Pick Lists')}</h1>{isMock && <span className="badge badge--warn">{t('fulfillment.common.sampleData', 'Sample data')}</span>}</header>
            <p className="muted">{t('fulfillment.picking.description', 'Pick lists are generated from reserved sales orders. Picking stages stock; the stock OUT happens at shipment.')}</p>
            {rows.length === 0 ? <EmptyState title={t('fulfillment.picking.emptyTitle', 'No pick lists')} hint={t('fulfillment.picking.emptyHint', 'Create a pick list from a reserved sales order.')} /> : (
                <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('fulfillment.picking.number', 'Pick #')}</SortTh><SortTh sort={sort} col="order">{t('fulfillment.common.salesOrder', 'Sales order')}</SortTh><SortTh sort={sort} col="warehouse">{t('fulfillment.common.warehouse', 'Warehouse')}</SortTh><SortTh sort={sort} col="status">{t('fulfillment.common.status', 'Status')}</SortTh></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/pick-lists/${p.id}`}>{p.pick_number}</Link></td><td>{p.sales_order_number ?? <bdi>#{p.sales_order_id}</bdi>}</td><td>{p.warehouse_name ?? <bdi>#{p.warehouse_id}</bdi>}</td><td><DocumentStatusBadge status={p.status} /></td></tr>))}</tbody></table></div>
            )}
        </section>
    );
}
