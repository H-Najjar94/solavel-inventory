import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState, ConfirmModal } from '../components/ui.jsx';
import { DocumentStatusBadge, DocumentActions } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function TransferDetailPage() {
    const { t: tr } = useI18n();
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);

    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['transfer', id], () => api.transfer(id), { fallback: null });
    const t = data?.transfer;
    const ledger = data?.ledger ?? [];

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: tr('transfers.breadcrumb', 'Transfers'), to: '/transfers' }]} />
            <EmptyState title={tr('transfers.detail.loadErrorTitle', 'Transfer could not be loaded')}
                hint={tr('transfers.detail.loadErrorHint', 'Try loading the transfer again.')}
                action={<button className="btn" onClick={() => refetch()}>{tr('transfers.detail.retry', 'Try again')}</button>} />
        </section>
    );
    if (!t) return <section className="page"><Breadcrumbs items={[{ label: tr('transfers.breadcrumb', 'Transfers'), to: '/transfers' }, { label: tr('transfers.detail.notFoundBreadcrumb', 'Not found') }]} /><EmptyState title={tr('transfers.detail.unavailableTitle', 'Transfer unavailable')} hint={tr('transfers.detail.unavailableHint', 'Select an organization to load transfer data.')} /></section>;

    async function post() {
        try { await api.postTransfer(id); toast.push(tr('transfers.detail.posted', 'Transfer posted.'), 'success'); qc.invalidateQueries({ queryKey: ['transfer'] }); }
        catch (e) { toast.push(e.message || tr('transfers.detail.actionFailed', 'The transfer action could not be completed.'), 'error'); }
    }
    async function ship() {
        try { await api.shipTransfer(id); toast.push(tr('transfers.detail.shipped', 'Transfer shipped.'), 'success'); qc.invalidateQueries({ queryKey: ['transfer'] }); }
        catch (e) { toast.push(e.message || tr('transfers.detail.actionFailed', 'The transfer action could not be completed.'), 'error'); }
    }
    async function receive() {
        try { await api.receiveTransfer(id); toast.push(tr('transfers.detail.received', 'Transfer received.'), 'success'); qc.invalidateQueries({ queryKey: ['transfer'] }); }
        catch (e) { toast.push(e.message || tr('transfers.detail.actionFailed', 'The transfer action could not be completed.'), 'error'); }
    }

    function ledgerPreview() {
        if (ledger.length === 0) return <p className="muted">{tr('transfers.detail.ledgerEmpty', 'No ledger entries have been created for this transfer.')}</p>;
        return (
            <table className="data-table">
                <thead><tr>
                    <th>{tr('transfers.detail.ledgerDate', 'Date')}</th>
                    <th>{tr('transfers.detail.ledgerItem', 'Item')}</th>
                    <th>{tr('transfers.detail.ledgerWarehouse', 'Warehouse')}</th>
                    <th>{tr('transfers.detail.ledgerDirection', 'Direction')}</th>
                    <th>{tr('transfers.detail.ledgerQuantity', 'Quantity')}</th>
                    <th>{tr('transfers.detail.ledgerUnitCost', 'Unit cost')}</th>
                    <th>{tr('transfers.detail.ledgerTotal', 'Total')}</th>
                    <th>{tr('transfers.detail.ledgerRunningQuantity', 'Running quantity')}</th>
                </tr></thead>
                <tbody>{ledger.map((row) => (
                    <tr key={row.id}>
                        <td>{row.moved_at}</td>
                        <td>{row.item_name ?? `#${row.item_id}`}</td>
                        <td>{row.warehouse_name ?? `#${row.warehouse_id}`}</td>
                        <td>{row.direction === 'in'
                            ? tr('transfers.detail.directionIn', 'Inbound')
                            : row.direction === 'out'
                                ? tr('transfers.detail.directionOut', 'Outbound')
                                : row.direction}</td>
                        <td>{row.quantity}</td>
                        <td>{row.unit_cost}</td>
                        <td>{row.total_cost}</td>
                        <td>{row.balance_qty_after}</td>
                    </tr>
                ))}</tbody>
            </table>
        );
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: tr('transfers.breadcrumb', 'Transfers'), to: '/transfers' }, { label: t.transfer_number }]} />
            <header className="page-head">
                <h1>{t.transfer_number}</h1><DocumentStatusBadge status={t.status} />
                {isMock && <span className="badge badge--warn">{tr('transfers.sampleData', 'Sample data')}</span>}
                {t.status === 'draft' && <Link to={`/transfers/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: gate.allowed ? 1 : 0.5, pointerEvents: gate.allowed ? 'auto' : 'none' }}>{tr('transfers.detail.edit', 'Edit')}</Link>}
            </header>

            <div className="panel"><dl className="kv">
                <dt>{tr('transfers.detail.date', 'Date')}</dt><dd>{t.transfer_date}</dd>
                <dt>{tr('transfers.detail.fromWarehouse', 'From warehouse')}</dt><dd>{t.from_warehouse_name ?? `#${t.from_warehouse_id}`}</dd>
                <dt>{tr('transfers.detail.toWarehouse', 'To warehouse')}</dt><dd>{t.to_warehouse_name ?? `#${t.to_warehouse_id}`}</dd>
                <dt>{tr('transfers.detail.shippedAt', 'Shipped')}</dt><dd>{t.shipped_at ?? '—'}</dd>
                <dt>{tr('transfers.detail.receivedAt', 'Received')}</dt><dd>{t.received_at ?? '—'}</dd>
                <dt>{tr('transfers.detail.notes', 'Notes')}</dt><dd>{t.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: tr('transfers.detail.linesTab', 'Lines') }, { key: 'ledger', label: tr('transfers.detail.ledgerTab', 'Ledger result') }, { key: 'audit', label: tr('transfers.detail.auditTab', 'Audit') }]} active={tab} onChange={setTab} />

            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>{tr('transfers.detail.item', 'Item')}</th><th>{tr('transfers.detail.sourceBin', 'Source bin')}</th><th>{tr('transfers.detail.destinationBin', 'Destination bin')}</th><th>{tr('transfers.detail.quantity', 'Quantity')}</th></tr></thead>
                <tbody>
                    {(t.lines ?? []).length === 0 && <tr><td colSpan={4} className="muted">{tr('transfers.detail.linesEmpty', 'This transfer has no lines.')}</td></tr>}
                    {(t.lines ?? []).map((l) => <tr key={l.id}><td>{l.item?.name ?? `#${l.item_id}`}{l.item?.sku && <span className="muted"> · {l.item.sku}</span>}</td><td>{l.from_bin_id ? `#${l.from_bin_id}` : '—'}</td><td>{l.to_bin_id ? `#${l.to_bin_id}` : '—'}</td><td>{l.quantity}</td></tr>)}
                </tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel">{ledgerPreview()}</div>}
            {tab === 'audit' && <div className="panel"><EmptyState title={tr('transfers.detail.auditTitle', 'Audit timeline')} hint={tr('transfers.detail.auditHint', 'Transfer posting events are recorded in the inventory audit log.')} /></div>}

            <div className="doc-actions">
                {t.status === 'draft' && <button className="btn" disabled={!gate.allowed} onClick={ship}>{tr('transfers.detail.ship', 'Ship transfer')}</button>}
                {t.status === 'in_transit' && <button className="btn btn--primary" disabled={!gate.allowed} onClick={receive}>{tr('transfers.detail.receive', 'Receive transfer')}</button>}
            </div>
            <DocumentActions status={t.status} canManage={gate.allowed} onPost={() => setConfirmPost(true)} postLabel={tr('transfers.detail.post', 'Post transfer')} />
            <ConfirmModal open={confirmPost}
                title={tr('transfers.detail.confirmPostTitle', 'Post this transfer?')}
                message={tr('transfers.detail.confirmPostMessage', 'Posting writes the stock movements to the inventory ledger and locks this transfer. It cannot be edited afterwards.')}
                confirmLabel={tr('transfers.detail.confirmPost', 'Post transfer')}
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
