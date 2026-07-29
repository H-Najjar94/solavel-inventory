import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PurchaseOrdersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['pos'], () => api.purchaseOrders({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders') }]} />
            <header className="page-head"><h1>{t('receiving.po.list.title', 'Purchase Orders')}</h1>{isMock && <span className="badge badge--warn">{t('receiving.common.sampleData', 'Sample data')}</span>}
                <Link to="/purchase-orders/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('receiving.po.actions.new', 'New PO')}</Link></header>
            <p className="muted">{t('receiving.po.list.stockNotice', 'Purchase orders do not move stock. Receive against them through Goods Receipts.')}</p>
            {rows.length === 0 ? <EmptyState title={t('receiving.po.empty.title', 'No purchase orders')} hint={t('receiving.po.empty.hint', 'Create one to order from a supplier.')} /> : (
                <table className="data-table"><thead><tr><th>{t('receiving.po.fields.numberShort', 'PO #')}</th><th>{t('receiving.common.supplier', 'Supplier')}</th><th>{t('receiving.common.warehouseShort', 'WH')}</th><th>{t('receiving.po.fields.orderDate', 'Order date')}</th><th>{t('receiving.po.fields.expected', 'Expected')}</th><th>{t('receiving.common.status', 'Status')}</th><th>{t('receiving.po.fields.backorder', 'Backorder')}</th><th>{t('receiving.common.total', 'Total')}</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/purchase-orders/${p.id}`}>{p.po_number}</Link></td><td>{p.supplier_name ?? (p.supplier_id ? `#${p.supplier_id}` : '—')}</td><td>{p.warehouse_name ?? `#${p.warehouse_id}`}</td><td>{p.order_date}</td><td>{p.expected_date ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td><td>{Number(p.open_backorder_qty ?? 0) > 0 ? p.open_backorder_qty : '—'}</td><td>{p.total}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
