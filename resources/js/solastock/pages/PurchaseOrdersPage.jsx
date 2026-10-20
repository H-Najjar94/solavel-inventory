import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PurchaseOrdersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_purchase_orders');
    const { data, isMock, isLoading, isError, refetch } = useApiQuery(['pos'], () => api.purchaseOrders({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page index-page purchase-orders-page">
            <Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders') }]} />
            <header className="page-head po-heading"><div><h1>{t('receiving.po.list.title')}</h1><p>{t('receiving.po.list.designIntro')}</p></div>{isMock && <span className="badge badge--warn">{t('receiving.common.sampleData')}</span>}
                {gate.allowed ? <Link to="/purchase-orders/new" className="btn btn--primary po-new">+ {t('receiving.po.actions.new')}</Link> : null}</header>
            <div className="po-stock-notice"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v2"/></svg><p>{t('receiving.po.list.stockNotice')}</p></div>
            <section className="card po-directory">
            {isLoading ? <div className="card-body"><Skeleton /></div> : isError ? <EmptyState title={t('receiving.po.list.loadError')} hint={t('receiving.po.list.loadErrorHint')} action={<button type="button" className="btn" onClick={()=>refetch()}>{t('retry')}</button>} /> : rows.length === 0 ? <div className="po-empty"><div className="po-empty-icon" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M8 4H5v17h14V4h-3M9 2h6v4H9zM8 11h8M8 15h6"/></svg></div><EmptyState title={t('receiving.po.empty.title')} hint={t('receiving.po.empty.hint')} action={gate.allowed?<Link to="/purchase-orders/new" className="btn btn--primary">+ {t('receiving.po.actions.new')}</Link>:undefined} /></div> : (
                <div className="tbl-wrap po-table">
                <table className="data-table"><thead><tr><th>{t('receiving.po.fields.numberShort', 'PO #')}</th><th>{t('receiving.common.supplier', 'Supplier')}</th><th>{t('receiving.common.warehouseShort', 'WH')}</th><th>{t('receiving.po.fields.orderDate', 'Order date')}</th><th>{t('receiving.po.fields.expected', 'Expected')}</th><th>{t('receiving.common.status', 'Status')}</th><th>{t('receiving.po.fields.backorder', 'Backorder')}</th><th>{t('receiving.common.total', 'Total')}</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/purchase-orders/${p.id}`}>{p.po_number}</Link></td><td>{p.supplier_name ?? (p.supplier_id ? `#${p.supplier_id}` : '—')}</td><td>{p.warehouse_name ?? `#${p.warehouse_id}`}</td><td>{p.order_date}</td><td>{p.expected_date ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td><td>{Number(p.open_backorder_qty ?? 0) > 0 ? p.open_backorder_qty : '—'}</td><td>{p.total}</td></tr>))}</tbody></table></div>
            )}
            </section>
        </section>
    );
}
