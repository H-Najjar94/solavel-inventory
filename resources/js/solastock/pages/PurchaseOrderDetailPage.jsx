import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function PurchaseOrderDetailPage() {
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

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!po) return <section className="page"><Breadcrumbs items={[{ label: 'Purchase Orders', to: '/purchase-orders' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function act(fn, label) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['po'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    const canReceive = ['approved', 'partially_received'].includes(po.status) && hasRemaining;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Purchase Orders', to: '/purchase-orders' }, { label: po.po_number }]} />
            <header className="page-head">
                <h1>{po.po_number}</h1><DocumentStatusBadge status={po.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    {po.status === 'draft' && <Link to={`/purchase-orders/${id}/edit`} className="btn" style={{ opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}
                    {po.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmApprove(true)}>Approve</button>}
                    {canReceive && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => nav(`/goods-receipts/from-po/${id}`)} title={gate.allowed ? '' : gate.reason}>Create GRN</button>}
                    {!['received', 'cancelled'].includes(po.status) && <button className="btn btn--danger" disabled={!gate.allowed} onClick={() => setConfirmCancel(true)}>Cancel</button>}
                </div>
            </header>

            <div className="panel"><dl className="kv">
                <dt>Supplier</dt><dd>{po.supplier_id ? `#${po.supplier_id}` : '—'}</dd>
                <dt>Warehouse</dt><dd>#{po.warehouse_id}</dd>
                <dt>Order date</dt><dd>{po.order_date}</dd>
                <dt>Expected</dt><dd>{po.expected_date ?? '—'}</dd>
                <dt>Total</dt><dd>{po.total}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'grns', label: `Linked GRNs (${grns.length})` }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Remaining</th><th>Unit cost</th></tr></thead>
                <tbody>{lines.map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.ordered_qty}</td><td>{l.received_qty}</td><td>{l.remaining_qty}</td><td>{l.unit_price}</td></tr>)}</tbody>
            </table></div>}

            {tab === 'grns' && <div className="panel">{grns.length === 0 ? <EmptyState title="No GRNs yet" hint={canReceive ? 'Create a GRN to receive stock.' : ''} /> : (
                <table className="data-table"><thead><tr><th>GRN</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>{grns.map((g) => <tr key={g.id}><td><Link to={`/goods-receipts/${g.id}`}>{g.grn_number}</Link></td><td>{g.receipt_date}</td><td>{g.status}</td></tr>)}</tbody></table>
            )}</div>}

            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="PO create/approve/cancel events are recorded in inventory_audit_logs." /></div>}

            <ConfirmModal open={confirmApprove} title="Approve PO?" message="Approving allows receiving against this PO. It will become read-only except for receiving status."
                confirmLabel="Approve" onConfirm={() => { setConfirmApprove(false); act(api.approvePurchaseOrder, 'PO approved.'); }} onCancel={() => setConfirmApprove(false)} />
            <ConfirmModal open={confirmCancel} danger title="Cancel PO?" message="This marks the PO cancelled. Posted GRNs are unaffected."
                confirmLabel="Cancel PO" onConfirm={() => { setConfirmCancel(false); act(api.cancelPurchaseOrder, 'PO cancelled.'); }} onCancel={() => setConfirmCancel(false)} />
        </section>
    );
}
