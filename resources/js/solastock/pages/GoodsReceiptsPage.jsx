import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function GoodsReceiptsPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.receive_goods');
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['grns', sort.key, sort.dir], () => api.goodsReceipts({ ...sort.params, per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page index-page">
            <Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts') }]} />
            <header className="page-head"><h1>{t('receiving.grn.list.title', 'Goods Receipts')}</h1>{isMock && <span className="badge badge--warn">{t('receiving.common.sampleData', 'Sample data')}</span>}
                {gate.allowed && <Link to="/goods-receipts/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('receiving.grn.actions.new', 'New GRN')}</Link>}</header>
            <p className="muted">{t('receiving.grn.list.stockNotice', 'Posting a goods receipt adds the accepted quantities to stock and updates its purchase order.')}</p>
            {rows.length === 0 ? <EmptyState title={t('receiving.grn.empty.title', 'No goods receipts')} hint={t('receiving.grn.empty.hint', 'Create one, or receive from an approved purchase order.')} /> : (
                <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('receiving.grn.fields.numberShort', 'GRN #')}</SortTh><SortTh sort={sort} col="po">{t('receiving.po.abbreviation', 'PO')}</SortTh><SortTh sort={sort} col="warehouse">{t('receiving.common.warehouseShort', 'WH')}</SortTh><SortTh sort={sort} col="date">{t('receiving.common.date', 'Date')}</SortTh><SortTh sort={sort} col="status">{t('receiving.common.status', 'Status')}</SortTh></tr></thead>
                <tbody>{rows.map((g) => (<tr key={g.id}><td><Link to={`/goods-receipts/${g.id}`}>{g.grn_number}</Link></td><td>{g.purchase_order_number ?? (g.purchase_order_id ? `#${g.purchase_order_id}` : '—')}</td><td>{g.warehouse_name ?? `#${g.warehouse_id}`}</td><td>{g.receipt_date}</td><td><DocumentStatusBadge status={g.reversal_id ? 'reversed' : g.status} /></td></tr>))}</tbody></table></div>
            )}
        </section>
    );
}
