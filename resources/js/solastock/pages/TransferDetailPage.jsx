import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions, ConfirmPostModal, LedgerPreview } from '../components/document.jsx';

export default function TransferDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['transfer', id], () => api.transfer(id), { fallback: null });
    const t = data?.transfer;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!t) return <section className="page"><Breadcrumbs items={[{ label: 'Transfers', to: '/transfers' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    async function post() {
        try { await api.postTransfer(id); toast.push('Transfer posted.', 'success'); qc.invalidateQueries({ queryKey: ['transfer'] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Transfers', to: '/transfers' }, { label: t.transfer_number }]} />
            <header className="page-head">
                <h1>{t.transfer_number}</h1><DocumentStatusBadge status={t.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {t.status === 'draft' && <Link to={`/transfers/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>Edit</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Date</dt><dd>{t.transfer_date}</dd>
                <dt>From warehouse</dt><dd>#{t.from_warehouse_id}</dd>
                <dt>To warehouse</dt><dd>#{t.to_warehouse_id}</dd>
                <dt>Notes</dt><dd>{t.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'ledger', label: 'Ledger result' }, { key: 'audit', label: 'Audit' }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Source bin</th><th>Dest bin</th><th>Quantity</th></tr></thead>
                <tbody>{(t.lines ?? []).map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.from_bin_id ? `#${l.from_bin_id}` : '—'}</td><td>{l.to_bin_id ? `#${l.to_bin_id}` : '—'}</td><td>{l.quantity}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'audit' && <div className="panel"><EmptyState title="Audit timeline" hint="Transfer post events are recorded in inventory_audit_logs." /></div>}

            <DocumentActions status={t.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} postLabel="Post transfer" />
            <ConfirmPostModal open={confirmPost} name="transfer"
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
