import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { LedgerPreview, SourceDocumentLink } from '../components/document.jsx';
import { LotStatusBadge } from '../components/traceability.jsx';

export default function LotDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_lots');
    const [tab, setTab] = useState('summary');

    const { data, isLoading } = useApiQuery(['lot', id], () => api.lot(id), { fallback: null });

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!data?.lot) return <section className="page"><Breadcrumbs items={[{ label: 'Lots', to: '/traceability/lots' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    const { lot, item, quantities, locations = [], sources = [], transfers = [], shipments = [], returns = [], movements = [], expiry_status, is_expired, recall_status } = data;

    async function setStatus(status) {
        try { await api.setLotStatus(id, { status }); toast.push(`Lot marked ${status}.`, 'success'); qc.invalidateQueries({ queryKey: ['lot', id] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    const SourceList = ({ rows, label }) => rows.length === 0
        ? <p className="muted">No {label}.</p>
        : <ul className="trace-sources">{rows.map((r, i) => <li key={i}><SourceDocumentLink sourceType={r.source_type} sourceId={r.source_id} /> · {r.qty} · {r.at}</li>)}</ul>;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Traceability', to: '/traceability' }, { label: 'Lots', to: '/traceability/lots' }, { label: lot.lot_code }]} />
            <header className="page-head">
                <h1>{lot.lot_code}</h1><LotStatusBadge status={lot.status} />
                {is_expired && <span className="badge badge--warn">expired</span>}
                {recall_status === 'recalled' && <span className="badge badge--danger">recalled</span>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>Item</dt><dd>{item ? `${item.sku} · ${item.name}` : `#${lot.item_id}`}</dd>
                <dt>Expiry</dt><dd>{lot.expiry_date ?? '—'} ({expiry_status})</dd>
                <dt>Received</dt><dd>{lot.received_date ?? '—'}</dd>
                <dt>Received qty</dt><dd>{quantities?.received}</dd>
                <dt>On hand</dt><dd>{quantities?.on_hand}</dd>
                <dt>Reserved</dt><dd>{quantities?.reserved}</dd>
                <dt>Issued / shipped</dt><dd>{quantities?.issued}</dd>
            </dl></div>

            <Tabs tabs={[
                { key: 'summary', label: 'Locations' }, { key: 'sources', label: 'Sources' },
                { key: 'moves', label: 'Shipments / returns' }, { key: 'ledger', label: 'Ledger' },
            ]} active={tab} onChange={setTab} />

            {tab === 'summary' && <div className="panel"><table className="data-table">
                <thead><tr><th>Warehouse</th><th>Bin</th><th>On hand</th><th>Reserved</th></tr></thead>
                <tbody>{locations.map((b, i) => <tr key={i}><td>{b.warehouse ?? `#${b.warehouse_id}`}</td><td>{b.bin ?? (b.bin_id ? `#${b.bin_id}` : '—')}</td><td>{b.on_hand_qty}</td><td>{b.reserved_qty}</td></tr>)}
                {locations.length === 0 && <tr><td colSpan={4} className="muted">No on-hand locations.</td></tr>}</tbody>
            </table></div>}
            {tab === 'sources' && <div className="panel">
                <h3>Source documents (GRN / opening / adjustment)</h3><SourceList rows={sources} label="source documents" />
                <h3>Transfers</h3><SourceList rows={transfers} label="transfers" />
            </div>}
            {tab === 'moves' && <div className="panel">
                <h3>Shipments</h3><SourceList rows={shipments} label="shipments" />
                <h3>Returns</h3><SourceList rows={returns} label="returns" />
            </div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={movements} /></div>}

            <div className="doc-actions">
                {lot.status !== 'quarantined' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('quarantined')}>Quarantine</button>}
                {lot.status !== 'active' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('active')}>Mark active</button>}
                {lot.status !== 'consumed' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('consumed')}>Mark consumed</button>}
            </div>
        </section>
    );
}
