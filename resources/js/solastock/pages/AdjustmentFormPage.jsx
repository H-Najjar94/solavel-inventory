import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable, DocumentTotals } from '../components/document.jsx';
import { ItemPicker, BinPicker, QuantityInput, MoneyInput, WarehousePicker } from '../components/pickers.jsx';
import { LotCapture, SerialNumberListInput, LotSelector, SerialSelector, TraceabilityRequiredBadge, FefoHint } from '../components/traceability.jsx';
import { useItemTracking } from '../hooks/useItemTracking.js';

const emptyLine = () => ({ direction: 'increase', item_id: null, bin_id: null, quantity: '', unit_cost: '', lot_id: null, lot_code: '', expiry_date: '', serials: [], serial_ids: [] });

export default function AdjustmentFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ adjustment_number: '', adjustment_date: new Date().toISOString().slice(0, 10), warehouse_id: null, reason_code: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['adjustment', id], () => api.adjustment(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.adjustment) {
            const a = existing.data.adjustment;
            if (a.status !== 'draft') { toast.push('Posted documents are read-only.', 'error'); nav(`/adjustments/${id}`); return; }
            setHeader({ adjustment_number: a.adjustment_number, adjustment_date: a.adjustment_date, warehouse_id: a.warehouse_id, reason_code: a.reason_code ?? '', notes: a.notes ?? '' });
            setLines((a.lines ?? []).map((l) => ({ direction: l.direction, item_id: l.item_id, bin_id: l.bin_id, quantity: l.quantity, unit_cost: l.unit_cost })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const tracking = useItemTracking();

    async function save(post = false) {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines
                    .filter((l) => l.item_id && (Number(l.quantity) > 0 || (l.serials ?? []).length > 0 || (l.serial_ids ?? []).length > 0))
                    .map((l) => {
                        const isInc = l.direction === 'increase';
                        const serialCapture = isInc && tracking.tracksSerial(l.item_id) && (l.serials ?? []).length > 0;
                        const serialSelect = !isInc && tracking.tracksSerial(l.item_id) && (l.serial_ids ?? []).length > 0;
                        return {
                            direction: l.direction, item_id: l.item_id, bin_id: l.bin_id,
                            quantity: serialCapture ? String(l.serials.length) : (serialSelect ? String(l.serial_ids.length) : l.quantity),
                            unit_cost: l.unit_cost,
                            lot_id: isInc ? undefined : (l.lot_id || undefined),
                            lot_code: isInc ? (l.lot_code || undefined) : undefined,
                            expiry_date: isInc ? (l.expiry_date || undefined) : undefined,
                            serials: serialCapture ? l.serials : undefined,
                            // decrease serial selection sends the first selected id per qty-1 line is handled server-side; send single for now
                            serial_id: serialSelect ? l.serial_ids[0] : undefined,
                        };
                    }),
            };
            if (payload.lines.length === 0) { toast.push('Add at least one line.', 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateAdjustment(id, payload) : await api.createAdjustment(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postAdjustment(docId); toast.push('Adjustment posted.', 'success'); }
            else toast.push(isEdit ? 'Draft updated.' : 'Draft saved.', 'success');
            qc.invalidateQueries({ queryKey: ['adjustments'] });
            nav(`/adjustments/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || 'Save failed.', 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'dir', label: 'Type', width: 130, render: (l, i) => (
            <select className="input" value={l.direction} onChange={(e) => setLine(i, { direction: e.target.value })}>
                <option value="increase">Increase</option><option value="decrease">Decrease</option>
            </select>) },
        { key: 'item', label: 'Item', render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'bin', label: 'Bin', render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'qty', label: 'Quantity', width: 110, render: (l, i) => {
            const serial = tracking.tracksSerial(l.item_id);
            if (serial) {
                const n = l.direction === 'increase' ? (l.serials ?? []).length : (l.serial_ids ?? []).length;
                return <span className="muted" title="Quantity = serial count">{n}</span>;
            }
            return <QuantityInput value={l.quantity} onChange={(v) => setLine(i, { quantity: v })} />;
        } },
        { key: 'trace', label: 'Lot / Serial', render: (l, i) => {
            const t = tracking.trackingOf(l.item_id);
            if (!t.tracking_type || t.tracking_type === 'none') return <span className="muted">—</span>;
            const inc = l.direction === 'increase';
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} />
                    {inc ? (
                        <>
                            {tracking.tracksLot(l.item_id) && <LotCapture value={{ lot_code: l.lot_code, expiry_date: l.expiry_date }} requireExpiry={!!t.tracks_expiry}
                                onChange={(v) => setLine(i, { lot_code: v.lot_code, expiry_date: v.expiry_date })} />}
                            {tracking.tracksSerial(l.item_id) && <SerialNumberListInput value={l.serials ?? []} autoFocus={false}
                                onChange={(arr) => setLine(i, { serials: arr, quantity: String(arr.length) })} />}
                        </>
                    ) : (
                        <>
                            {tracking.tracksLot(l.item_id) && <>
                                <LotSelector itemId={l.item_id} warehouseId={header.warehouse_id} value={l.lot_id} onChange={(v) => setLine(i, { lot_id: v })} />
                                {tracking.tracksExpiry(l.item_id) && <FefoHint itemId={l.item_id} warehouseId={header.warehouse_id} quantity={l.quantity} selectedLotId={l.lot_id} onApply={(s) => setLine(i, { lot_id: s.lot_id })} />}
                            </>}
                            {tracking.tracksSerial(l.item_id) && <SerialSelector itemId={l.item_id} warehouseId={header.warehouse_id} value={l.serial_ids ?? []} expectedQty={1} onChange={(ids) => setLine(i, { serial_ids: ids, quantity: String(ids.length) })} />}
                        </>
                    )}
                </div>
            );
        } },
        { key: 'cost', label: 'Unit cost', width: 110, render: (l, i) => <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} disabled={l.direction === 'decrease'} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Adjustments', to: '/adjustments' }, { label: isEdit ? 'Edit draft' : 'New' }]} />
            <header className="page-head"><h1>{isEdit ? 'Edit adjustment' : 'New adjustment'}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label="Document number" required error={errors.adjustment_number}><input className="input" value={header.adjustment_number} onChange={(e) => setHeader({ ...header, adjustment_number: e.target.value })} /></Field>
                <Field label="Date" error={errors.adjustment_date}><input className="input" type="date" value={header.adjustment_date} onChange={(e) => setHeader({ ...header, adjustment_date: e.target.value })} /></Field>
                <Field label="Warehouse" required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label="Reason code"><input className="input" value={header.reason_code} onChange={(e) => setHeader({ ...header, reason_code: e.target.value })} placeholder="e.g. damage, found, cycle_count" /></Field>
                <Field label="Notes"><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>Lines</h2>
                <DocumentLinesTable columns={columns} lines={lines} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <DocumentTotals rows={[
                    { label: 'Increase value', value: lines.filter((l) => l.direction === 'increase').reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0).toFixed(2) },
                ]} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/adjustments')}>Cancel</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? 'Saving…' : 'Save draft'}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => save(true)}>Save & post</button>
            </div>
        </section>
    );
}
