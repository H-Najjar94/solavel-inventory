import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function RecallDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_recalls');
    const [tab, setTab] = useState('impact');
    const [confirmActivate, setConfirmActivate] = useState(false);

    const { data, isLoading } = useApiQuery(['recall', id], () => api.recall(id), { fallback: null });
    const recall = data?.recall;
    const impact = data?.impact;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!recall) return <section className="page"><Breadcrumbs items={[{ label: 'Recalls', to: '/recalls' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function act(fn, msg) {
        try { await fn(); toast.push(msg, 'success'); qc.invalidateQueries({ queryKey: ['recall', id] }); qc.invalidateQueries({ queryKey: ['recalls'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    function exportCsv() {
        const rows = impact?.lines ?? [];
        const head = ['item_id', 'lot_id', 'serial_id', 'on_hand', 'reserved', 'shipped', 'warehouses'];
        const csv = [head.join(',')].concat(rows.map((l) => [l.item_id, l.lot_id ?? '', l.serial_id ?? '', l.on_hand, l.reserved, l.shipped, `"${(l.warehouses ?? []).join(' ')}"`].join(','))).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = `${recall.recall_number}-impact.csv`; a.click();
        URL.revokeObjectURL(a.href);
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Recalls', to: '/recalls' }, { label: recall.recall_number }]} />
            <header className="page-head"><h1>{recall.recall_number}</h1><DocumentStatusBadge status={recall.status} /></header>

            <div className="panel"><dl className="kv">
                <dt>Item</dt><dd>{recall.item ? `${recall.item.sku} · ${recall.item.name}` : `#${recall.item_id}`}</dd>
                <dt>Scope</dt><dd>{recall.scope}</dd>
                <dt>Reason</dt><dd>{recall.reason ?? '—'}</dd>
                <dt>Affected on-hand</dt><dd>{impact?.totals?.on_hand}</dd>
                <dt>Affected reserved</dt><dd>{impact?.totals?.reserved}</dd>
                <dt>Affected shipped</dt><dd>{impact?.totals?.shipped}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'impact', label: 'Impact' }, { key: 'actions', label: 'Actions timeline' }]} active={tab} onChange={setTab} />
            {tab === 'impact' && <div className="panel">
                <div className="page-head" style={{ marginTop: 0 }}><h3 style={{ margin: 0 }}>Affected stock</h3>
                    <button className="btn btn--sm" style={{ marginLeft: 'auto' }} onClick={exportCsv}>Export CSV</button></div>
                <table className="data-table">
                    <thead><tr><th>Lot</th><th>Serial</th><th>On hand</th><th>Reserved</th><th>Shipped</th><th>Warehouses</th><th>Shipped docs</th></tr></thead>
                    <tbody>{(impact?.lines ?? []).map((l) => (
                        <tr key={l.recall_line_id}><td>{l.lot_id ? `#${l.lot_id}` : '—'}</td><td>{l.serial_id ? `#${l.serial_id}` : '—'}</td>
                            <td>{l.on_hand}</td><td>{l.reserved}</td><td>{l.shipped}</td><td>{(l.warehouses ?? []).map((w) => `#${w}`).join(', ') || '—'}</td>
                            <td>{(l.shipped_documents ?? []).length} shipment(s)</td></tr>
                    ))}</tbody>
                </table>
            </div>}
            {tab === 'actions' && <div className="panel">
                {(recall.actions ?? []).length === 0 ? <p className="muted">No actions recorded.</p> : (
                    <ul className="audit-timeline">{recall.actions.map((a) => <li key={a.id}><span className="audit-action">{a.action}</span> <span className="muted">{a.created_at}</span>{a.detail ? ` · ${a.detail}` : ''}</li>)}</ul>
                )}
            </div>}

            <div className="doc-actions">
                {recall.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmActivate(true)}>Activate recall</button>}
                {recall.status === 'active' && <button className="btn btn--danger" disabled={!gate.allowed} onClick={() => act(() => api.closeRecall(id), 'Recall closed.')}>Close recall</button>}
            </div>
            <ConfirmModal open={confirmActivate} danger title="Activate recall?"
                message="Activating flags affected lots as recalled and serials as quarantined. They cannot ship without an override."
                confirmLabel="Activate" onConfirm={() => { setConfirmActivate(false); act(() => api.activateRecall(id), 'Recall activated.'); }} onCancel={() => setConfirmActivate(false)} />
        </section>
    );
}
