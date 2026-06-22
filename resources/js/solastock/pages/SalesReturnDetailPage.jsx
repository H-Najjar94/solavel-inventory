import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, LedgerPreview, ConfirmPostModal } from '../components/document.jsx';

export default function SalesReturnDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_returns');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['sales-return', id], () => api.salesReturn(id), { fallback: null });
    const r = data?.sales_return;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!r) return <section className="page"><Breadcrumbs items={[{ label: 'Sales Returns', to: '/sales-returns' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function post() {
        try { await api.postSalesReturn(id); toast.push('Return posted — resellable stock brought back in.', 'success'); qc.invalidateQueries({ queryKey: ['sales-return', id] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Returns', to: '/sales-returns' }, { label: r.return_number }]} />
            <header className="page-head"><h1>{r.return_number}</h1><DocumentStatusBadge status={r.status} />{isMock && <span className="badge badge--warn">sample data</span>}
                {r.status === 'draft' && <Link to={`/sales-returns/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}</header>

            <div className="panel"><dl className="kv">
                <dt>Customer</dt><dd>{r.customer_name ?? '—'}</dd>
                <dt>Return date</dt><dd>{r.return_date}</dd>
                <dt>Warehouse</dt><dd>#{r.warehouse_id}</dd>
                <dt>Source shipment</dt><dd>{r.shipment_id ? <Link to={`/shipments/${r.shipment_id}`}>#{r.shipment_id}</Link> : '—'}</dd>
                <dt>Reason</dt><dd>{r.reason ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }]} active={tab} onChange={setTab} />
            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Returned</th><th>Condition</th><th>Unit cost</th></tr></thead>
                <tbody>{(r.lines ?? []).map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.returned_qty}</td><td>{l.condition}</td><td>{l.unit_cost}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}

            <div className="doc-actions">
                {r.status === 'draft' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={() => setConfirmPost(true)}>Post return</button>}
            </div>
            <ConfirmPostModal open={confirmPost} name="sales return"
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
