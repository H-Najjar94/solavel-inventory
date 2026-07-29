import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { LedgerPreview } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityEventText, traceabilityPageText, traceabilityStatusText } from '../i18n/traceabilityPages.js';

export default function SerialDetailPage() {
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_serials');
    const [tab, setTab] = useState('timeline');

    const { data, isLoading, isError, refetch } = useApiQuery(['serial', id], () => api.serial(id), { fallback: null });

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: text('traceabilityPages.common.serials'), to: '/traceability/serials' }]} />
        <EmptyState title={text('traceabilityPages.common.loadError')}
            action={<button className="btn" onClick={() => refetch()}>{text('traceabilityPages.common.retry')}</button>} />
    </section>;
    if (!data?.serial) return <section className="page"><Breadcrumbs items={[{ label: text('traceabilityPages.common.serials'), to: '/traceability/serials' }, { label: text('traceabilityPages.common.notFound') }]} /><EmptyState title={text('traceabilityPages.common.unavailable')} hint={text('traceabilityPages.common.tenantRequired')} /></section>;

    const { serial, item, lifecycle_status, current_location, receipt_source, warranty_until, owner_ref, timeline = [], movements = [] } = data;

    async function setStatus(status) {
        try {
            await api.setSerialStatus(id, { status });
            toast.push(text('traceabilityPages.serialDetail.statusUpdated', { status: traceabilityStatusText(locale, status) }), 'success');
            qc.invalidateQueries({ queryKey: ['serial', id] });
        }
        catch (e) { toast.push(e.message, 'error'); }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability'), to: '/traceability' }, { label: text('traceabilityPages.common.serials'), to: '/traceability/serials' }, { label: serial.serial }]} />
            <header className="page-head"><h1><bdi>{serial.serial}</bdi></h1><span className="badge badge--muted">{traceabilityStatusText(locale, lifecycle_status)}</span></header>

            <div className="panel"><dl className="kv">
                <dt>{text('traceabilityPages.common.item')}</dt><dd><bdi>{item ? `${item.sku} · ${item.name}` : `#${serial.item_id}`}</bdi></dd>
                <dt>{text('traceabilityPages.serialDetail.currentLocation')}</dt><dd>{current_location?.warehouse_id ? <><bdi>{text('traceabilityPages.serialDetail.warehouseRef', { warehouse: current_location.warehouse_id })}</bdi>{current_location.bin_id ? <> / <bdi>{text('traceabilityPages.serialDetail.binRef', { bin: current_location.bin_id })}</bdi></> : null}</> : '—'}</dd>
                <dt>{text('traceabilityPages.serialDetail.receiptSource')}</dt><dd><bdi>{receipt_source ? `${receipt_source.source_type?.split('\\').pop()} #${receipt_source.source_id}` : '—'}</bdi></dd>
                <dt>{text('traceabilityPages.serialDetail.warrantyUntil')}</dt><dd><bdi>{warranty_until ?? text('traceabilityPages.serialDetail.notRecorded')}</bdi></dd>
                <dt>{text('traceabilityPages.serialDetail.owner')}</dt><dd><bdi>{owner_ref ?? text('traceabilityPages.serialDetail.notRecorded')}</bdi></dd>
            </dl></div>

            <Tabs tabs={[{ key: 'timeline', label: text('traceabilityPages.serialDetail.lifecycle') }, { key: 'ledger', label: text('traceabilityPages.common.ledger') }]} active={tab} onChange={setTab} />
            {tab === 'timeline' && <div className="panel">
                {timeline.length === 0 ? <p className="muted">{text('traceabilityPages.serialDetail.noEvents')}</p> : (
                    <ul className="audit-timeline">{timeline.map((e, i) => (
                        <li key={i}><span className="audit-action">{traceabilityEventText(locale, e.event)}</span> <span className="muted"><bdi>{e.at}</bdi></span> · <bdi>{e.source_type} #{e.source_id}</bdi> · <bdi>{text('traceabilityPages.serialDetail.timelineWarehouse', { warehouse: e.warehouse_id })}</bdi></li>
                    ))}</ul>
                )}
            </div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={movements} /></div>}

            <div className="doc-actions">
                {lifecycle_status !== 'quarantined' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('quarantined')}>{text('traceabilityPages.serialDetail.quarantine')}</button>}
                {lifecycle_status !== 'damaged' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('damaged')}>{text('traceabilityPages.serialDetail.markDamaged')}</button>}
                {lifecycle_status !== 'retired' && <button className="btn" disabled={!gate.allowed} title={gate.allowed ? '' : gate.reason} onClick={() => setStatus('retired')}>{text('traceabilityPages.serialDetail.retire')}</button>}
            </div>
        </section>
    );
}
