import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SalesOrdersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_sales_orders');
    const { data, isMock } = useApiQuery(['sales-orders'], () => api.salesOrders({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('salesOrders.common.title', 'Sales Orders') }]} />
            <header className="page-head"><h1>{t('salesOrders.common.title', 'Sales Orders')}</h1>{isMock && <span className="badge badge--warn">{t('salesOrders.common.sampleData', 'Sample data')}</span>}
                <Link to="/sales-orders/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('salesOrders.list.new', 'New sales order')}</Link></header>
            <p className="muted">{t('salesOrders.list.description', 'Fulfillment documents only — SolaStock reserves and ships stock. Invoices and accounting remain in SolaBooks.')}</p>
            {rows.length === 0 ? <EmptyState title={t('salesOrders.list.emptyTitle', 'No sales orders')} hint={t('salesOrders.list.emptyHint', 'Create a sales order to reserve, pick, pack, and ship stock.')} /> : (
                <table className="data-table"><thead><tr><th>{t('salesOrders.list.orderNumberShort', 'Order #')}</th><th>{t('salesOrders.common.customer', 'Customer')}</th><th>{t('salesOrders.list.date', 'Date')}</th><th>{t('salesOrders.common.warehouse', 'Warehouse')}</th><th>{t('salesOrders.common.status', 'Status')}</th><th>{t('salesOrders.list.total', 'Total')}</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/sales-orders/${s.id}`}>{s.order_number}</Link></td><td>{s.customer_name ?? '—'}</td><td>{s.order_date}</td><td>{s.warehouse_name ?? `#${s.warehouse_id}`}</td><td><DocumentStatusBadge status={s.status} /></td><td>{s.total ?? '0.00'}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
