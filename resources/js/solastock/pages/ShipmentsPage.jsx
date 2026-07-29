import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function ShipmentsPage() {
    const { t } = useI18n();
    const { data, isMock } = useApiQuery(['shipments'], () => api.shipments({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('fulfillment.shipments.title', 'Shipments') }]} />
            <header className="page-head"><h1>{t('fulfillment.shipments.title', 'Shipments')}</h1>{isMock && <span className="badge badge--warn">{t('fulfillment.common.sampleData', 'Sample data')}</span>}</header>
            <p className="muted">{t('fulfillment.shipments.descriptionBeforeEvent', 'Posting a shipment records stock OUT in the inventory ledger and creates the')} <code dir="ltr">{'shipment.posted'}</code> {t('fulfillment.shipments.descriptionAfterEvent', 'integration event. It does not create an invoice or journal entry.')}</p>
            {rows.length === 0 ? <EmptyState title={t('fulfillment.shipments.emptyTitle', 'No shipments')} hint={t('fulfillment.shipments.emptyHint', 'Create a shipment from a reserved sales order.')} /> : (
                <table className="data-table"><thead><tr><th>{t('fulfillment.shipments.number', 'Shipment #')}</th><th>{t('fulfillment.common.salesOrder', 'Sales order')}</th><th>{t('fulfillment.common.date', 'Date')}</th><th>{t('fulfillment.common.carrier', 'Carrier')}</th><th>{t('fulfillment.common.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/shipments/${s.id}`}>{s.shipment_number}</Link></td><td>#{s.sales_order_id}</td><td>{s.ship_date}</td><td>{s.carrier ?? '—'}</td><td><DocumentStatusBadge status={s.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
