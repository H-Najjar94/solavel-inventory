import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PickListsPage() {
    const { t } = useI18n();
    const { data, isMock } = useApiQuery(['pick-lists'], () => api.pickLists({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('fulfillment.picking.breadcrumb', 'Picking') }]} />
            <header className="page-head"><h1>{t('fulfillment.picking.title', 'Pick Lists')}</h1>{isMock && <span className="badge badge--warn">{t('fulfillment.common.sampleData', 'Sample data')}</span>}</header>
            <p className="muted">{t('fulfillment.picking.description', 'Pick lists are generated from reserved sales orders. Picking stages stock; the stock OUT happens at shipment.')}</p>
            {rows.length === 0 ? <EmptyState title={t('fulfillment.picking.emptyTitle', 'No pick lists')} hint={t('fulfillment.picking.emptyHint', 'Create a pick list from a reserved sales order.')} /> : (
                <table className="data-table"><thead><tr><th>{t('fulfillment.picking.number', 'Pick #')}</th><th>{t('fulfillment.common.salesOrder', 'Sales order')}</th><th>{t('fulfillment.common.warehouse', 'Warehouse')}</th><th>{t('fulfillment.common.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/pick-lists/${p.id}`}>{p.pick_number}</Link></td><td>#{p.sales_order_id}</td><td>#{p.warehouse_id}</td><td><DocumentStatusBadge status={p.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
