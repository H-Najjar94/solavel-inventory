import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PurchaseOrdersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_purchase_orders');
    const sort = useTableSort();
    const { data, isMock, isLoading, isError, refetch } = useApiQuery(['pos', sort.key, sort.dir], () => api.purchaseOrders({ ...sort.params, per_page: 50 }), { fallback: [] });
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
                <table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('receiving.po.fields.numberShort', 'PO #')}</SortTh><SortTh sort={sort} col="supplier">{t('receiving.common.supplier', 'Supplier')}</SortTh><SortTh sort={sort} col="warehouse">{t('receiving.common.warehouseShort', 'WH')}</SortTh><SortTh sort={sort} col="date">{t('receiving.po.fields.orderDate', 'Order date')}</SortTh><SortTh sort={sort} col="expected">{t('receiving.po.fields.expected', 'Expected')}</SortTh><SortTh sort={sort} col="status">{t('receiving.common.status', 'Status')}</SortTh><th>{t('receiving.po.fields.backorder', 'Backorder')}</th><SortTh sort={sort} col="total">{t('receiving.common.total', 'Total')}</SortTh></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/purchase-orders/${p.id}`}>{p.po_number}</Link></td><td>{p.supplier_name ?? (p.supplier_id ? `#${p.supplier_id}` : '—')}</td><td>{p.warehouse_name ?? `#${p.warehouse_id}`}</td><td>{p.order_date}</td><td>{p.expected_date ?? '—'}</td><td><DocumentStatusBadge status={p.status} /></td><td>{Number(p.open_backorder_qty ?? 0) > 0 ? p.open_backorder_qty : '—'}</td><td>{p.total}</td></tr>))}</tbody></table></div>
            )}
            </section>
        </section>
    );
}
