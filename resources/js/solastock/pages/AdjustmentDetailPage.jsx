import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, ConfirmReverseModal, LedgerPreview } from '../components/document.jsx';

export default function AdjustmentDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['adjustment', id], () => api.adjustment(id), { fallback: null });
    const adj = data?.adjustment;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!adj) return <section className="page"><Breadcrumbs items={[{ label: 'Adjustments', to: '/adjustments' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function act(fn, label) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['adjustment'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Adjustments', to: '/adjustments' }, { label: adj.adjustment_number }]} />
            <header className="page-head">
                <h1>{adj.adjustment_number}</h1><DocumentStatusBadge status={adj.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {adj.status === 'draft' && <Link to={`/adjustments/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Date</dt><dd>{adj.adjustment_date}</dd><dt>Warehouse</dt><dd>#{adj.warehouse_id}</dd>
                <dt>Reason</dt><dd>{adj.reason_code ?? '—'}</dd>
                <dt>Increase value</dt><dd>{adj.total_increase_value}</dd><dt>Decrease value</dt><dd>{adj.total_decrease_value}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Type</th><th>Item</th><th>Bin</th><th>Qty</th><th>Unit cost</th></tr></thead>
                <tbody>{(adj.lines ?? []).map((l) => <tr key={l.id}><td>{l.direction}</td><td>#{l.item_id}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.quantity}</td><td>{l.unit_cost}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="Posted/reversed events are recorded in inventory_audit_logs." /></div>}

            <DocumentActions status={adj.status} canManage={gate.allowed}
                onPost={() => setConfirmPost(true)} onReverse={() => setConfirmReverse(true)} />

            <ConfirmPostModal open={confirmPost} name="adjustment"
                onConfirm={() => { setConfirmPost(false); act(api.postAdjustment, 'Adjustment posted.'); }} onCancel={() => setConfirmPost(false)} />
            <ConfirmReverseModal open={confirmReverse} name="adjustment"
                onConfirm={() => { setConfirmReverse(false); act(api.reverseAdjustment, 'Adjustment reversed.'); }} onCancel={() => setConfirmReverse(false)} />
        </section>
    );
}
