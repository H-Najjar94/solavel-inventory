import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, LedgerPreview } from '../components/document.jsx';

export default function GoodsReceiptDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['grn', id], () => api.goodsReceipt(id), { fallback: null });
    const grn = data?.grn;
    const ledger = data?.ledger ?? [];
    const po = data?.purchase_order;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!grn) return <section className="page"><Breadcrumbs items={[{ label: 'Goods Receipts', to: '/goods-receipts' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function post() {
        try { await api.postGoodsReceipt(id); toast.push('GRN posted — stock received.', 'success'); qc.invalidateQueries({ queryKey: ['grn'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Goods Receipts', to: '/goods-receipts' }, { label: grn.grn_number }]} />
            <header className="page-head">
                <h1>{grn.grn_number}</h1><DocumentStatusBadge status={grn.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {grn.status === 'draft' && <Link to={`/goods-receipts/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Received date</dt><dd>{grn.receipt_date}</dd>
                <dt>Warehouse</dt><dd>#{grn.warehouse_id}</dd>
                <dt>Source PO</dt><dd>{po ? <Link to={`/purchase-orders/${po.id}`}>{po.po_number}</Link> : '—'}</dd>
                <dt>Notes</dt><dd>{grn.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Received</th><th>Accepted</th><th>Bin</th><th>Unit cost</th></tr></thead>
                <tbody>{(grn.lines ?? []).map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.received_qty}</td><td>{l.accepted_qty}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.unit_cost}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="GRN create/post events are recorded in inventory_audit_logs." /></div>}

            <DocumentActions status={grn.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} postLabel="Post GRN" />
            <ConfirmPostModal open={confirmPost} name="goods receipt"
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
