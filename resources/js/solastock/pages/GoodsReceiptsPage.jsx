import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function GoodsReceiptsPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['grns'], () => api.goodsReceipts({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts') }]} />
            <header className="page-head"><h1>{t('receiving.grn.list.title', 'Goods Receipts')}</h1>{isMock && <span className="badge badge--warn">{t('receiving.common.sampleData', 'Sample data')}</span>}
                <Link to="/goods-receipts/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('receiving.grn.actions.new', 'New GRN')}</Link></header>
            <p className="muted">{t('receiving.grn.list.stockNotice', 'Posting a goods receipt adds the accepted quantities to stock and updates its purchase order.')}</p>
            {rows.length === 0 ? <EmptyState title={t('receiving.grn.empty.title', 'No goods receipts')} hint={t('receiving.grn.empty.hint', 'Create one, or receive from an approved purchase order.')} /> : (
                <table className="data-table"><thead><tr><th>{t('receiving.grn.fields.numberShort', 'GRN #')}</th><th>{t('receiving.po.abbreviation', 'PO')}</th><th>{t('receiving.common.warehouseShort', 'WH')}</th><th>{t('receiving.common.date', 'Date')}</th><th>{t('receiving.common.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((g) => (<tr key={g.id}><td><Link to={`/goods-receipts/${g.id}`}>{g.grn_number}</Link></td><td>{g.purchase_order_number ?? (g.purchase_order_id ? `#${g.purchase_order_id}` : '—')}</td><td>{g.warehouse_name ?? `#${g.warehouse_id}`}</td><td>{g.receipt_date}</td><td><DocumentStatusBadge status={g.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
