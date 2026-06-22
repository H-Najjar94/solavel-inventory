import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, LedgerPreview } from '../components/document.jsx';

export default function CountDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['count', id], () => api.count(id), { fallback: null });
    const c = data?.count;
    const adjustment = data?.adjustment;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!c) return <section className="page"><Breadcrumbs items={[{ label: 'Stock Counts', to: '/counts' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function post() {
        try { await api.postCount(id); toast.push('Count posted — variance adjustment created.', 'success'); qc.invalidateQueries({ queryKey: ['count'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Stock Counts', to: '/counts' }, { label: c.count_number }]} />
            <header className="page-head">
                <h1>{c.count_number}</h1><DocumentStatusBadge status={c.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {c.status === 'draft' && <Link to={`/counts/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Type</dt><dd>{c.count_type}</dd>
                <dt>Warehouse</dt><dd>#{c.warehouse_id}</dd>
                <dt>Generated adjustment</dt><dd>{adjustment ? <Link to={`/adjustments/${adjustment.id}`}>{adjustment.adjustment_number}</Link> : '—'}</dd>
                <dt>Notes</dt><dd>{c.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Bin</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead>
                <tbody>{(c.lines ?? []).map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.system_qty}</td><td>{l.counted_qty ?? '—'}</td><td>{l.variance_qty}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="Count post events are recorded in inventory_audit_logs." /></div>}

            <DocumentActions status={c.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} postLabel="Post variance" />
            <ConfirmPostModal open={confirmPost} name="count variance"
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
