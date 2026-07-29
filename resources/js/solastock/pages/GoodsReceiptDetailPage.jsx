import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, ConfirmReverseModal, LedgerPreview } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function GoodsReceiptDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['grn', id], () => api.goodsReceipt(id), { fallback: null });
    const grn = data?.grn;
    const ledger = data?.ledger ?? [];
    const po = data?.purchase_order;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!grn) return <section className="page"><Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts'), to: '/goods-receipts' }, { label: t('receiving.common.notFound', 'Not found') }]} /><EmptyState title={t('receiving.common.unavailable', 'Unavailable')} hint={t('receiving.common.selectOrganization', 'Select an organization to load data.')} /></section>;

    async function post() {
        try { await api.postGoodsReceipt(id); toast.push(t('receiving.grn.messages.posted', 'Goods receipt posted. Stock has been received.'), 'success'); qc.invalidateQueries({ queryKey: ['grn'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    async function reverse(reason) {
        try { await api.reverseGoodsReceipt(id, reason); toast.push(t('receiving.grn.messages.reversed', 'Goods receipt reversed from its original ledger source.'), 'success'); qc.invalidateQueries({ queryKey: ['grn'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts'), to: '/goods-receipts' }, { label: grn.grn_number }]} />
            <header className="page-head">
                <h1>{grn.grn_number}</h1><DocumentStatusBadge status={grn.reversal_id ? 'reversed' : grn.status} />
                {isMock && <span className="badge badge--warn">{t('receiving.common.sampleData', 'Sample data')}</span>}
                {grn.status === 'draft' && <Link to={`/goods-receipts/${id}/edit`} className="btn" style={{ marginInlineStart: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>{t('receiving.common.edit', 'Edit')}</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>{t('receiving.grn.fields.receivedDate', 'Received date')}</dt><dd>{grn.receipt_date}</dd>
                <dt>{t('receiving.common.warehouse', 'Warehouse')}</dt><dd>{grn.warehouse_name ?? `#${grn.warehouse_id}`}</dd>
                <dt>{t('receiving.common.supplier', 'Supplier')}</dt><dd>{grn.supplier_name ?? (grn.supplier_id ? `#${grn.supplier_id}` : '—')}</dd>
                <dt>{t('receiving.grn.fields.sourcePo', 'Source PO')}</dt><dd>{po ? <Link to={`/purchase-orders/${po.id}`}>{po.po_number}</Link> : '—'}</dd>
                <dt>{t('receiving.grn.fields.reversal', 'Reversal')}</dt><dd>{grn.reversal ? `${grn.reversal.reversal_number} — ${grn.reversal.reason}` : '—'}</dd>
                <dt>{t('receiving.common.notes', 'Notes')}</dt><dd>{grn.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('receiving.common.lines', 'Lines') }, { key: 'ledger', label: t('receiving.grn.tabs.ledgerResult', 'Ledger result') }, { key: 'audit', label: t('receiving.common.audit', 'Audit') }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>{t('receiving.common.item', 'Item')}</th><th>{t('receiving.grn.fields.received', 'Received')}</th><th>{t('receiving.grn.fields.accepted', 'Accepted')}</th><th>{t('receiving.grn.fields.rejected', 'Rejected')}</th><th>{t('receiving.grn.fields.disposition', 'Disposition')}</th><th>{t('receiving.common.bin', 'Bin')}</th><th>{t('receiving.common.unitCost', 'Unit cost')}</th></tr></thead>
                <tbody>{(grn.lines ?? []).map((l) => <tr key={l.id}><td>{l.item?.name ?? `#${l.item_id}`}{l.item?.sku && <span className="muted"> · {l.item.sku}</span>}</td><td>{l.received_qty}{l.entered_unit ? <span className="muted"> ({l.entered_qty} {l.entered_unit.code})</span> : null}</td><td>{l.accepted_qty}</td><td>{l.rejected_qty ?? '0.0000'}</td><td>{t(`receiving.disposition.${l.disposition ?? l.inspection_status ?? 'restock'}`, l.disposition ?? l.inspection_status ?? 'restock')}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.unit_cost}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={t('receiving.common.auditTimeline', 'Audit timeline')} hint={t('receiving.grn.audit.hint', 'Goods-receipt creation and posting events are recorded in the audit log.')} /></div>}

            <DocumentActions status={grn.reversal_id ? 'reversed' : grn.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} onReverse={() => setConfirmReverse(true)} postLabel={t('receiving.grn.actions.post', 'Post GRN')} />
            <ConfirmPostModal open={confirmPost} name={t('receiving.grn.singular', 'goods receipt')}
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
            <ConfirmReverseModal open={confirmReverse} name={t('receiving.grn.singular', 'goods receipt')}
                onConfirm={(reason) => { setConfirmReverse(false); reverse(reason); }} onCancel={() => setConfirmReverse(false)} />
        </section>
    );
}
