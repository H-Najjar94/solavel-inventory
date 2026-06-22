import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { LedgerPreview } from '../components/document.jsx';
import { SerialStatusBadge } from '../components/traceability.jsx';

export default function SerialDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_serials');
    const [tab, setTab] = useState('timeline');

    const { data, isLoading } = useApiQuery(['serial', id], () => api.serial(id), { fallback: null });

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!data?.serial) return <section className="page"><Breadcrumbs items={[{ label: 'Serials', to: '/traceability/serials' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    const { serial, item, lifecycle_status, current_location, receipt_source, warranty_until, owner_ref, timeline = [], movements = [] } = data;

    async function setStatus(status) {
        try { await api.setSerialStatus(id, { status }); toast.push(`Serial marked ${status}.`, 'success'); qc.invalidateQueries({ queryKey: ['serial', id] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Traceability', to: '/traceability' }, { label: 'Serials', to: '/traceability/serials' }, { label: serial.serial }]} />
            <header className="page-head"><h1>{serial.serial}</h1><SerialStatusBadge status={lifecycle_status} /></header>

            <div className="panel"><dl className="kv">
                <dt>Item</dt><dd>{item ? `${item.sku} · ${item.name}` : `#${serial.item_id}`}</dd>
                <dt>Current location</dt><dd>{current_location?.warehouse_id ? `WH #${current_location.warehouse_id}${current_location.bin_id ? ` / bin #${current_location.bin_id}` : ''}` : '—'}</dd>
                <dt>Receipt source</dt><dd>{receipt_source ? `${receipt_source.source_type?.split('\\').pop()} #${receipt_source.source_id}` : '—'}</dd>
                <dt>Warranty until</dt><dd>{warranty_until ?? '— (placeholder)'}</dd>
                <dt>Owner</dt><dd>{owner_ref ?? '— (placeholder)'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'timeline', label: 'Lifecycle timeline' }, { key: 'ledger', label: 'Ledger' }]} active={tab} onChange={setTab} />
            {tab === 'timeline' && <div className="panel">
                {timeline.length === 0 ? <p className="muted">No lifecycle events yet.</p> : (
                    <ul className="audit-timeline">{timeline.map((e, i) => (
                        <li key={i}><span className="audit-action">{e.event}</span> <span className="muted">{e.at}</span> · {e.source_type} #{e.source_id} · WH #{e.warehouse_id}</li>
                    ))}</ul>
                )}
            </div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={movements} /></div>}

            <div className="doc-actions">
                {lifecycle_status !== 'quarantined' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('quarantined')}>Quarantine</button>}
                {lifecycle_status !== 'damaged' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('damaged')}>Mark damaged</button>}
                {lifecycle_status !== 'retired' && <button className="btn" disabled={!gate.allowed} onClick={() => setStatus('retired')}>Retire</button>}
            </div>
        </section>
    );
}
