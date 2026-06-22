import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, ConfirmReverseModal, LedgerPreview } from '../components/document.jsx';

export default function OpeningStockDetailPage() {
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_opening_stock');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);
    const [confirmReverse, setConfirmReverse] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['opening', id], () => api.openingStockEntry(id), { fallback: null });
    const entry = data?.entry;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!entry) return <section className="page"><Breadcrumbs items={[{ label: 'Opening Stock', to: '/opening-stock' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function act(fn, label, after) {
        try { await fn(id); toast.push(label, 'success'); qc.invalidateQueries({ queryKey: ['opening'] }); after?.(); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Opening Stock', to: '/opening-stock' }, { label: entry.entry_number }]} />
            <header className="page-head">
                <h1>{entry.entry_number}</h1><DocumentStatusBadge status={entry.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {entry.status === 'draft' && (
                    <Link to={`/opening-stock/${id}/edit`} className="btn"
                        style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}>Edit</Link>
                )}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Opening date</dt><dd>{entry.opening_date}</dd>
                <dt>Warehouse</dt><dd>#{entry.warehouse_id}</dd>
                <dt>Total value</dt><dd>{entry.total_value}</dd>
                <dt>Notes</dt><dd>{entry.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && (
                <div className="panel"><table className="data-table">
                    <thead><tr><th>Item</th><th>Bin</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
                    <tbody>{(entry.lines ?? []).map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.bin_id ? `#${l.bin_id}` : '—'}</td><td>{l.quantity}</td><td>{l.unit_cost}</td><td>{l.total_cost}</td></tr>)}</tbody>
                </table></div>
            )}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="Posted/reversed events are recorded in inventory_audit_logs." /></div>}

            <DocumentActions status={entry.status} canManage={gate.allowed}
                onPost={() => setConfirmPost(true)} onReverse={() => setConfirmReverse(true)} postLabel="Post" />

            <ConfirmPostModal open={confirmPost} name="opening stock"
                onConfirm={() => { setConfirmPost(false); act(api.postOpeningStock, 'Opening stock posted.'); }} onCancel={() => setConfirmPost(false)} />
            <ConfirmReverseModal open={confirmReverse} name="opening stock"
                onConfirm={() => { setConfirmReverse(false); act(api.reverseOpeningStock, 'Opening stock reversed.'); }} onCancel={() => setConfirmReverse(false)} />
        </section>
    );
}
