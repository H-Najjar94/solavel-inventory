import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function PurchaseOrderDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmApprove, setConfirmApprove] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['po', id], () => api.purchaseOrder(id), { fallback: null });
    const po = data?.purchase_order;
    const lines = data?.lines ?? [];
    const grns = data?.linked_grns ?? [];
    const hasRemaining = data?.has_remaining;
    const openBackorderQty = data?.open_backorder_qty ?? '0.0000';

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!po) return <section className="page"><Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders'), to: '/purchase-orders' }, { label: t('receiving.common.notFound', 'Not found') }]} /><EmptyState title={t('receiving.common.unavailable', 'Unavailable')} hint={t('receiving.common.selectOrganization', 'Select an organization to load data.')} /></section>;

    async function act(fn, label) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['po'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    const canReceive = ['approved', 'partially_received'].includes(po.status) && hasRemaining;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders'), to: '/purchase-orders' }, { label: po.po_number }]} />
            <header className="page-head">
                <h1>{po.po_number}</h1><DocumentStatusBadge status={po.status} />
                {isMock && <span className="badge badge--warn">{t('receiving.common.sampleData', 'Sample data')}</span>}
                <div style={{ marginInlineStart: 'auto', display: 'flex', gap: 8 }}>
                    {po.status === 'draft' && <Link to={`/purchase-orders/${id}/edit`} className="btn" style={{ opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>{t('receiving.common.edit', 'Edit')}</Link>}
                    {po.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmApprove(true)}>{t('receiving.po.actions.approve', 'Approve')}</button>}
                    {canReceive && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => nav(`/goods-receipts/from-po/${id}`)} title={gate.allowed ? '' : gate.reason}>{t('receiving.grn.actions.create', 'Create GRN')}</button>}
                    {!['received', 'cancelled'].includes(po.status) && <button className="btn btn--danger" disabled={!gate.allowed} onClick={() => setConfirmCancel(true)}>{t('receiving.common.cancel', 'Cancel')}</button>}
                </div>
            </header>

            <div className="panel"><dl className="kv">
                <dt>{t('receiving.common.supplier', 'Supplier')}</dt><dd>{po.supplier_name ?? (po.supplier_id ? `#${po.supplier_id}` : '—')}</dd>
                <dt>{t('receiving.common.warehouse', 'Warehouse')}</dt><dd>{po.warehouse_name ?? `#${po.warehouse_id}`}</dd>
                <dt>{t('receiving.po.fields.orderDate', 'Order date')}</dt><dd>{po.order_date}</dd>
                <dt>{t('receiving.po.fields.expected', 'Expected')}</dt><dd>{po.expected_date ?? '—'}</dd>
                <dt>{t('receiving.po.fields.openBackorder', 'Open backorder')}</dt><dd>{Number(openBackorderQty) > 0 ? openBackorderQty : '—'}</dd>
                <dt>{t('receiving.common.net', 'Net')}</dt><dd>{po.subtotal}</dd>
                <dt>{t('receiving.common.tax', 'Tax')}</dt><dd>{po.tax_total}</dd>
                <dt>{t('receiving.common.total', 'Total')}</dt><dd>{po.total}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('receiving.common.lines', 'Lines') }, { key: 'backorders', label: t('receiving.po.tabs.backorders', 'Backorders') }, { key: 'grns', label: t('receiving.po.tabs.linkedReceipts', 'Linked GRNs (:count)', { count: grns.length }) }, { key: 'audit', label: t('receiving.common.audit', 'Audit') }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>{t('receiving.common.item', 'Item')}</th><th>{t('receiving.po.fields.ordered', 'Ordered')}</th><th>{t('receiving.grn.fields.received', 'Received')}</th><th>{t('receiving.common.remaining', 'Remaining')}</th><th>{t('receiving.po.fields.backorder', 'Backorder')}</th><th>{t('receiving.common.unitCost', 'Unit cost')}</th><th>{t('receiving.common.tax', 'Tax')}</th><th>{t('receiving.common.lineTotal', 'Line total')}</th></tr></thead>
                <tbody>{lines.map((l) => <tr key={l.id}><td>{l.item_name ?? `#${l.item_id}`}{l.item_sku && <span className="muted"> · {l.item_sku}</span>}</td><td>{l.ordered_qty}{l.entered_unit ? <span className="muted"> ({l.entered_qty} {l.entered_unit.code})</span> : null}</td><td>{l.received_qty}</td><td>{l.remaining_qty}</td><td>{Number(l.backorder_qty ?? 0) > 0 ? l.backorder_qty : '—'}</td><td>{l.unit_price}</td><td>{l.tax_code ?? '—'} · {l.tax_amount ?? '0.00'}</td><td>{l.line_total ?? '0.00'}</td></tr>)}</tbody>
            </table></div>}

            {tab === 'backorders' && <div className="panel">{Number(openBackorderQty) <= 0 ? <EmptyState title={t('receiving.po.backorders.emptyTitle', 'No open backorders')} hint={t('receiving.po.backorders.emptyHint', 'All approved quantities have been received, or the purchase order is not yet approved.')} /> : (
                <table className="data-table"><thead><tr><th>{t('receiving.common.item', 'Item')}</th><th>{t('receiving.po.fields.openQuantity', 'Open quantity')}</th><th>{t('receiving.po.fields.expected', 'Expected')}</th><th>{t('receiving.common.status', 'Status')}</th></tr></thead>
                <tbody>{lines.filter((l) => Number(l.backorder_qty ?? 0) > 0).map((l) => <tr key={l.id}><td>{l.item_name ?? `#${l.item_id}`}{l.item_sku && <span className="muted"> · {l.item_sku}</span>}</td><td>{l.backorder_qty}</td><td>{l.expected_date ?? po.expected_date ?? '—'}</td><td>{t(`receiving.backorderStatus.${l.backorder_status ?? 'open'}`, l.backorder_status ?? 'open')}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'grns' && <div className="panel">{grns.length === 0 ? <EmptyState title={t('receiving.po.receipts.emptyTitle', 'No goods receipts yet')} hint={canReceive ? t('receiving.po.receipts.emptyHint', 'Create a goods receipt to receive stock.') : ''} /> : (
                <table className="data-table"><thead><tr><th>{t('receiving.grn.abbreviation', 'GRN')}</th><th>{t('receiving.common.date', 'Date')}</th><th>{t('receiving.common.status', 'Status')}</th></tr></thead>
                <tbody>{grns.map((g) => <tr key={g.id}><td><Link to={`/goods-receipts/${g.id}`}>{g.grn_number}</Link></td><td>{g.receipt_date}</td><td><DocumentStatusBadge status={g.status} /></td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'audit' && <div className="panel"><EmptyState title={t('receiving.common.auditTimeline', 'Audit timeline')} hint={t('receiving.po.audit.hint', 'Purchase-order creation, approval, and cancellation events are recorded in the audit log.')} /></div>}

            <ConfirmModal open={confirmApprove} title={t('receiving.po.approve.title', 'Approve purchase order?')} message={t('receiving.po.approve.message', 'Approval allows receiving against this purchase order. The order then becomes read-only except for its receiving status.')}
                confirmLabel={t('receiving.po.actions.approve', 'Approve')} onConfirm={() => { setConfirmApprove(false); act(api.approvePurchaseOrder, t('receiving.po.messages.approved', 'Purchase order approved.')); }} onCancel={() => setConfirmApprove(false)} />
            <ConfirmModal open={confirmCancel} danger title={t('receiving.po.cancel.title', 'Cancel purchase order?')} message={t('receiving.po.cancel.message', 'This marks the purchase order as cancelled. Posted goods receipts are unaffected.')}
                confirmLabel={t('receiving.po.actions.cancel', 'Cancel PO')} onConfirm={() => { setConfirmCancel(false); act(api.cancelPurchaseOrder, t('receiving.po.messages.cancelled', 'Purchase order cancelled.')); }} onCancel={() => setConfirmCancel(false)} />
        </section>
    );
}
