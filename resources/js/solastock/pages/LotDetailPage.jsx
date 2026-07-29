import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { LedgerPreview, SourceDocumentLink } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityPageText, traceabilityStatusText } from '../i18n/traceabilityPages.js';

export default function LotDetailPage() {
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_lots');
    const [tab, setTab] = useState('summary');

    const { data, isLoading, isError, refetch } = useApiQuery(['lot', id], () => api.lot(id), { fallback: null });

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: text('traceabilityPages.common.lots'), to: '/traceability/lots' }]} />
        <EmptyState title={text('traceabilityPages.common.loadError')}
            action={<button className="btn" onClick={() => refetch()}>{text('traceabilityPages.common.retry')}</button>} />
    </section>;
    if (!data?.lot) return <section className="page"><Breadcrumbs items={[{ label: text('traceabilityPages.common.lots'), to: '/traceability/lots' }, { label: text('traceabilityPages.common.notFound') }]} /><EmptyState title={text('traceabilityPages.common.unavailable')} hint={text('traceabilityPages.common.tenantRequired')} /></section>;

    const { lot, item, quantities, locations = [], sources = [], transfers = [], shipments = [], returns = [], movements = [], expiry_status, is_expired, recall_status } = data;

    async function setStatus(status) {
        try {
            await api.setLotStatus(id, { status });
            toast.push(text('traceabilityPages.lotDetail.statusUpdated', { status: traceabilityStatusText(locale, status) }), 'success');
            qc.invalidateQueries({ queryKey: ['lot', id] });
        }
        catch (e) { toast.push(e.message, 'error'); }
    }

    const SourceList = ({ rows, emptyKey }) => rows.length === 0
        ? <p className="muted">{text(emptyKey)}</p>
        : <ul className="trace-sources">{rows.map((r, i) => <li key={i}><SourceDocumentLink sourceType={r.source_type} sourceId={r.source_id} /> · <bdi>{r.qty}</bdi> · <bdi>{r.at}</bdi></li>)}</ul>;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.lots'), to: '/traceability/lots' }, { label: lot.lot_code }]} />
            <header className="page-head">
                <h1><bdi>{lot.lot_code}</bdi></h1><span className="badge badge--muted">{traceabilityStatusText(locale, lot.status)}</span>
                {is_expired && <span className="badge badge--warn">{text('traceabilityPages.lotDetail.expired')}</span>}
                {recall_status === 'recalled' && <span className="badge badge--danger">{text('traceabilityPages.lotDetail.recalled')}</span>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>{text('traceabilityPages.common.item')}</dt><dd><bdi>{item ? `${item.sku} · ${item.name}` : `#${lot.item_id}`}</bdi></dd>
                <dt>{text('traceabilityPages.lotDetail.expiry')}</dt><dd><bdi>{lot.expiry_date ?? '—'}</bdi> ({traceabilityStatusText(locale, expiry_status)})</dd>
                <dt>{text('traceabilityPages.lotDetail.received')}</dt><dd><bdi>{lot.received_date ?? '—'}</bdi></dd>
                <dt>{text('traceabilityPages.lotDetail.receivedQty')}</dt><dd><bdi>{quantities?.received}</bdi></dd>
                <dt>{text('traceabilityPages.lotDetail.onHand')}</dt><dd><bdi>{quantities?.on_hand}</bdi></dd>
                <dt>{text('traceabilityPages.lotDetail.reserved')}</dt><dd><bdi>{quantities?.reserved}</bdi></dd>
                <dt>{text('traceabilityPages.lotDetail.issued')}</dt><dd><bdi>{quantities?.issued}</bdi></dd>
            </dl></div>

            <Tabs tabs={[
                { key: 'summary', label: text('traceabilityPages.lotDetail.locations') }, { key: 'sources', label: text('traceabilityPages.lotDetail.sources') },
                { key: 'moves', label: text('traceabilityPages.lotDetail.shipmentsReturns') }, { key: 'ledger', label: text('traceabilityPages.common.ledger') },
            ]} active={tab} onChange={setTab} />

            {tab === 'summary' && <div className="panel"><table className="data-table">
                <thead><tr><th>{text('traceabilityPages.lotDetail.warehouse')}</th><th>{text('traceabilityPages.lotDetail.bin')}</th><th>{text('traceabilityPages.lotDetail.onHand')}</th><th>{text('traceabilityPages.lotDetail.reserved')}</th></tr></thead>
                <tbody>{locations.map((b, i) => <tr key={i}><td><bdi>{b.warehouse ?? `#${b.warehouse_id}`}</bdi></td><td><bdi>{b.bin ?? (b.bin_id ? `#${b.bin_id}` : '—')}</bdi></td><td><bdi>{b.on_hand_qty}</bdi></td><td><bdi>{b.reserved_qty}</bdi></td></tr>)}
                {locations.length === 0 && <tr><td colSpan={4} className="muted">{text('traceabilityPages.lotDetail.noLocations')}</td></tr>}</tbody>
            </table></div>}
            {tab === 'sources' && <div className="panel">
                <h3>{text('traceabilityPages.lotDetail.sourceDocuments')}</h3><SourceList rows={sources} emptyKey="traceabilityPages.lotDetail.noSourceDocuments" />
                <h3>{text('traceabilityPages.lotDetail.transfers')}</h3><SourceList rows={transfers} emptyKey="traceabilityPages.lotDetail.noTransfers" />
            </div>}
            {tab === 'moves' && <div className="panel">
                <h3>{text('traceabilityPages.lotDetail.shipments')}</h3><SourceList rows={shipments} emptyKey="traceabilityPages.lotDetail.noShipments" />
                <h3>{text('traceabilityPages.lotDetail.returns')}</h3><SourceList rows={returns} emptyKey="traceabilityPages.lotDetail.noReturns" />
            </div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={movements} /></div>}

            <div className="doc-actions">
                {lot.status !== 'quarantined' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('quarantined')}>{text('traceabilityPages.lotDetail.quarantine')}</button>}
                {lot.status !== 'active' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('active')}>{text('traceabilityPages.lotDetail.markActive')}</button>}
                {lot.status !== 'consumed' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('consumed')}>{text('traceabilityPages.lotDetail.markConsumed')}</button>}
            </div>
        </section>
    );
}
