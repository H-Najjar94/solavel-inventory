import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, EmptyState, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput } from '../components/pickers.jsx';
import { LotSelector, SerialSelector, TraceabilityRequiredBadge, FefoHint } from '../components/traceability.jsx';
import { useItemTracking } from '../hooks/useItemTracking.js';
import { useI18n } from '../i18n/context.jsx';

const emptyLine = () => ({ item_id: null, from_bin_id: null, to_bin_id: null, quantity: '', lot_id: null, serial_ids: [] });

// Inline available-qty display for a line's item at the source warehouse.
function AvailableCell({ itemId, warehouseId }) {
    const { data } = useApiQuery(['avail', itemId, warehouseId],
        () => api.transferAvailable(itemId, warehouseId),
        { fallback: null, enabled: !!itemId && !!warehouseId });
    if (!itemId || !warehouseId) return <span className="muted">—</span>;
    return <span>{data?.available_qty ?? '…'}</span>;
}

export default function TransferFormPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ transfer_number: '', transfer_date: new Date().toISOString().slice(0, 10), from_warehouse_id: null, to_warehouse_id: null, notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['transfer', id], () => api.transfer(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.transfer) {
            const transfer = existing.data.transfer;
            if (transfer.status !== 'draft') { toast.push(t('transfers.form.draftOnly', 'Only draft transfers can be edited.'), 'error'); nav(`/transfers/${id}`); return; }
            setHeader({ transfer_number: transfer.transfer_number, transfer_date: transfer.transfer_date, from_warehouse_id: transfer.from_warehouse_id, to_warehouse_id: transfer.to_warehouse_id, notes: transfer.notes ?? '' });
            setLines((transfer.lines ?? []).map((l) => ({ item_id: l.item_id, from_bin_id: l.from_bin_id, to_bin_id: l.to_bin_id, quantity: l.quantity })));
        }
    }, [isEdit, existing.data, id, nav, t, toast]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const tracking = useItemTracking();
    const sameWh = header.from_warehouse_id && header.from_warehouse_id === header.to_warehouse_id;

    async function save(post = false) {
        if (!gate.allowed) return;
        if (sameWh) { toast.push(t('transfers.form.warehousesDiffer', 'Source and destination warehouses must be different.'), 'error'); return; }
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines
                    .filter((l) => l.item_id && (Number(l.quantity) > 0 || (l.serial_ids ?? []).length > 0))
                    .map((l) => {
                        const serial = tracking.tracksSerial(l.item_id) && (l.serial_ids ?? []).length > 0;
                        return {
                            item_id: l.item_id, from_bin_id: l.from_bin_id, to_bin_id: l.to_bin_id,
                            quantity: serial ? String(l.serial_ids.length) : l.quantity,
                            lot_id: l.lot_id || undefined,
                            serial_id: serial ? l.serial_ids[0] : undefined,
                        };
                    }),
            };
            if (payload.lines.length === 0) { toast.push(t('transfers.form.lineRequired', 'Add at least one transfer line.'), 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateTransfer(id, payload) : await api.createTransfer(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postTransfer(docId); toast.push(t('transfers.form.posted', 'Transfer posted.'), 'success'); }
            else toast.push(isEdit ? t('transfers.form.updated', 'Draft updated.') : t('transfers.form.saved', 'Draft saved.'), 'success');
            qc.invalidateQueries({ queryKey: ['transfers'] });
            nav(`/transfers/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || t('transfers.form.saveFailed', 'The transfer could not be saved.'), 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;
    if (isEdit && existing.isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('transfers.breadcrumb', 'Transfers'), to: '/transfers' }, { label: t('transfers.form.editBreadcrumb', 'Edit draft') }]} />
            <EmptyState title={t('transfers.form.loadErrorTitle', 'Transfer could not be loaded')}
                hint={t('transfers.form.loadErrorHint', 'Try loading the transfer again.')}
                action={<button className="btn" onClick={() => existing.refetch()}>{t('transfers.form.retry', 'Try again')}</button>} />
        </section>
    );

    const columns = [
        { key: 'item', label: t('transfers.form.item', 'Item'), render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'avail', label: t('transfers.form.availableAtSource', 'Available at source'), width: 130, render: (l) => <AvailableCell itemId={l.item_id} warehouseId={header.from_warehouse_id} /> },
        { key: 'fbin', label: t('transfers.form.sourceBin', 'Source bin'), render: (l, i) => <BinPicker warehouseId={header.from_warehouse_id} value={l.from_bin_id} onChange={(v) => setLine(i, { from_bin_id: v })} /> },
        { key: 'tbin', label: t('transfers.form.destinationBin', 'Destination bin'), render: (l, i) => <BinPicker warehouseId={header.to_warehouse_id} value={l.to_bin_id} onChange={(v) => setLine(i, { to_bin_id: v })} /> },
        { key: 'trace', label: t('transfers.form.traceability', 'Lot / serial number'), render: (l, i) => {
            const t = tracking.trackingOf(l.item_id);
            if (!t.tracking_type || t.tracking_type === 'none') return <span className="muted">—</span>;
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} />
                    {tracking.tracksLot(l.item_id) && <>
                        <LotSelector itemId={l.item_id} warehouseId={header.from_warehouse_id} value={l.lot_id} onChange={(v) => setLine(i, { lot_id: v })} />
                        {tracking.tracksExpiry(l.item_id) && <FefoHint itemId={l.item_id} warehouseId={header.from_warehouse_id} quantity={l.quantity} selectedLotId={l.lot_id} onApply={(s) => setLine(i, { lot_id: s.lot_id })} />}
                    </>}
                    {tracking.tracksSerial(l.item_id) && <SerialSelector itemId={l.item_id} warehouseId={header.from_warehouse_id} value={l.serial_ids ?? []} expectedQty={1} onChange={(ids) => setLine(i, { serial_ids: ids, quantity: String(ids.length) })} />}
                </div>
            );
        } },
        { key: 'qty', label: t('transfers.form.quantity', 'Quantity'), width: 110, render: (l, i) => tracking.tracksSerial(l.item_id)
            ? <span className="muted">{(l.serial_ids ?? []).length}</span>
            : <QuantityInput value={l.quantity} onChange={(v) => setLine(i, { quantity: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('transfers.breadcrumb', 'Transfers'), to: '/transfers' }, { label: isEdit ? t('transfers.form.editBreadcrumb', 'Edit draft') : t('transfers.form.newBreadcrumb', 'New') }]} />
            <header className="page-head"><h1>{isEdit ? t('transfers.form.editTitle', 'Edit transfer') : t('transfers.form.newTitle', 'New transfer')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('transfers.form.number', 'Transfer number')} required error={errors.transfer_number}><input className="input" value={header.transfer_number} onChange={(e) => setHeader({ ...header, transfer_number: e.target.value })} /></Field>
                <Field label={t('transfers.form.date', 'Date')} error={errors.transfer_date}><input className="input" type="date" value={header.transfer_date} onChange={(e) => setHeader({ ...header, transfer_date: e.target.value })} /></Field>
                <Field label={t('transfers.form.sourceWarehouse', 'Source warehouse')} required error={errors.from_warehouse_id}><WarehousePicker value={header.from_warehouse_id} onChange={(v) => setHeader({ ...header, from_warehouse_id: v })} placeholder={t('transfers.form.sourcePlaceholder', 'Select source warehouse…')} /></Field>
                <Field label={t('transfers.form.destinationWarehouse', 'Destination warehouse')} required error={errors.to_warehouse_id}><WarehousePicker value={header.to_warehouse_id} onChange={(v) => setHeader({ ...header, to_warehouse_id: v })} placeholder={t('transfers.form.destinationPlaceholder', 'Select destination warehouse…')} /></Field>
                <Field label={t('transfers.form.notes', 'Notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>
            {sameWh && <div className="banner banner--warn">{t('transfers.form.warehousesDiffer', 'Source and destination warehouses must be different.')}</div>}

            <div className="panel">
                <h2>{t('transfers.form.lines', 'Lines')}</h2>
                <DocumentLinesTable columns={columns} lines={lines} addLabel={t('transfers.form.addLine', 'Add line')} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/transfers')}>{t('transfers.form.cancel', 'Cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? t('transfers.form.saving', 'Saving…') : t('transfers.form.saveDraft', 'Save draft')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving || sameWh} onClick={() => save(true)}>{t('transfers.form.saveAndPost', 'Save and post')}</button>
            </div>
        </section>
    );
}
