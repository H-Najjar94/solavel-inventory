import {text as feedbackText} from '../../shared/feedback/messages';
import {ConfirmedActionButton} from '../components/ConfirmedActionButton';
import {t as documentText} from '../i18n/index';
import React, { useRef, useEffect, useState } from 'react';
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
import { t } from '../i18n/index.js';

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
    const savePending=useRef(false);
    const [statusCheck,setStatusCheck]=useState(null);

    const existing = useApiQuery(['adjustment', id], () => api.adjustment(id), { fallback: null, enabled: isEdit });
    const meta = useApiQuery(['meta'], api.meta, { fallback: { settings: {} } });
    useEffect(() => {
        if (isEdit && existing.data?.adjustment) {
            const a = existing.data.adjustment;
            if (a.status !== 'draft') { toast.push(t('adjustment.readOnly'), 'error'); nav(`/adjustments/${id}`); return; }
            setHeader({ adjustment_number: a.adjustment_number, adjustment_date: a.adjustment_date, warehouse_id: a.warehouse_id, reason_code: a.reason_code ?? '', notes: a.notes ?? '' });
            // Keep draft traceability: decreases reselect their lot/serial; captured
            // increase lots/serials already exist, so they are kept and resent by id.
            setLines((a.lines ?? []).map((l) => {
                const inc = l.direction === 'increase';
                const kept = inc && (l.lot_id || l.serial_id) ? { lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, lot_code: l.lot_code ?? null, expiry_date: l.lot_expiry_date ?? null, serial: l.serial ?? null } : null;
                return { ...emptyLine(), direction: l.direction, item_id: l.item_id, bin_id: l.bin_id, quantity: l.quantity, unit_cost: l.unit_cost, kept,
                    lot_id: inc ? null : (l.lot_id ?? null), serial_ids: !inc && l.serial_id ? [l.serial_id] : [] };
            }));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const tracking = useItemTracking();
    const reasonCodes = (meta.data?.settings?.adjustment_reason_codes ?? []).filter((code) => code.active !== false);

    async function save(post = false) {
        if(savePending.current||statusCheck)return;
        let savedDocument;
        if (!gate.allowed) return;
        savePending.current=true;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines
                    .filter((l) => l.item_id && (Number(l.quantity) > 0 || (l.serials ?? []).length > 0 || (l.serial_ids ?? []).length > 0))
                    .map((l) => {
                        const isInc = l.direction === 'increase';
                        if (isInc && l.kept) {
                            return { direction: l.direction, item_id: l.item_id, bin_id: l.bin_id, quantity: l.quantity, unit_cost: l.unit_cost,
                                lot_id: l.kept.lot_id || undefined, serial_id: l.kept.serial_id || undefined };
                        }
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
            if (payload.lines.length === 0) { setErrors({lines:t('adjustment.addLineRequired')}); setSaving(false); return; }
            const res = isEdit ? await api.updateAdjustment(id, payload) : await api.createAdjustment(payload);
            const docId = res?.data?.id ?? id;
            savedDocument=docId;
            if (post) { await api.postAdjustment(docId); toast.push(t('adjustment.posted'), 'success'); }
            else toast.push(t(isEdit ? 'adjustment.draftUpdated' : 'adjustment.draftSaved'), 'success');
            qc.invalidateQueries({ queryKey: ['adjustments'] });
            nav(`/adjustments/${docId}`);
        } catch (err) { if(err.outcomeUnknown||savedDocument)setStatusCheck({id:savedDocument,unknown:!!err.outcomeUnknown}); setErrors(fieldErrors(err)); toast.failure(err, err.message || t('adjustment.saveFailed')); }
        finally { savePending.current=false; setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'dir', label: t('adjustment.type'), width: 130, render: (l, i) => (
            <select className="input" value={l.direction} onChange={(e) => setLine(i, { direction: e.target.value, kept: null })}>
                <option value="increase">{t('adjustment.direction.increase')}</option><option value="decrease">{t('adjustment.direction.decrease')}</option>
            </select>) },
        { key: 'item', label: t('adjustment.item'), render: (l, i) => <ItemPicker stockOnly value={l.item_id} onChange={(v) => setLine(i, { item_id: v, kept: null })} /> },
        { key: 'bin', label: t('adjustment.bin'), render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'qty', label: t('adjustment.quantity'), width: 110, render: (l, i) => {
            const serial = tracking.tracksSerial(l.item_id);
            if (serial && !l.kept) {
                const n = l.direction === 'increase' ? (l.serials ?? []).length : (l.serial_ids ?? []).length;
                return <span className="muted" title={t('adjustment.serialQuantity')}>{n}</span>;
            }
            return <QuantityInput value={l.quantity} onChange={(v) => setLine(i, { quantity: v })} />;
        } },
        { key: 'trace', label: t('adjustment.lotSerial'), render: (l, i) => {
            const t = tracking.trackingOf(l.item_id);
            if (!t.tracking_type || t.tracking_type === 'none') return <span className="muted">—</span>;
            const inc = l.direction === 'increase';
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} />
                    {inc && l.kept ? (
                        <span className="muted">{[l.kept.lot_code && <bdi key="lot">{l.kept.lot_code}{l.kept.expiry_date ? ` · ${l.kept.expiry_date}` : ''}</bdi>, l.kept.serial && <bdi key="serial">{l.kept.serial}</bdi>].filter(Boolean).reduce((all, part) => all.length ? [...all, ' · ', part] : [part], [])}</span>
                    ) : inc ? (
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
        { key: 'cost', label: t('adjustment.unitCost'), width: 110, render: (l, i) => <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} disabled={l.direction === 'decrease'} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('adjustments'), to: '/adjustments' }, { label: t(isEdit ? 'adjustment.editDraft' : 'adjustment.new') }]} />
            <header className="page-head"><h1>{t(isEdit ? 'adjustment.editTitle' : 'adjustment.newTitle')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('adjustment.documentNumber')} error={errors.adjustment_number}><input className="input" readOnly aria-readonly="true" placeholder={t('adjustment.documentNumberAuto')} value={header.adjustment_number} /></Field>
                <Field label={t('adjustment.date')} error={errors.adjustment_date}><input className="input" type="date" value={header.adjustment_date} onChange={(e) => setHeader({ ...header, adjustment_date: e.target.value })} /></Field>
                <Field label={t('adjustment.warehouse')} required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label={t('adjustment.reasonCode')} error={errors.reason_code}>
                    {reasonCodes.length > 0 ? (
                        <select className="input" value={header.reason_code} onChange={(e) => setHeader({ ...header, reason_code: e.target.value })} required>
                            <option value="">{t('adjustment.selectReason')}</option>
                            {reasonCodes.map((code) => <option key={code.code} value={code.code}>{code.label ?? code.code}</option>)}
                        </select>
                    ) : (
                        <input className="input" value={header.reason_code} onChange={(e) => setHeader({ ...header, reason_code: e.target.value })} placeholder={t('adjustment.reasonPlaceholder')} />
                    )}
                </Field>
                <Field label={t('adjustment.notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t('adjustment.lines')}</h2>
                <DocumentLinesTable validationErrors={errors} columns={columns} lines={lines} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <DocumentTotals rows={[
                    { label: t('adjustment.increaseValue'), value: lines.filter((l) => l.direction === 'increase').reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_cost || 0), 0).toFixed(2) },
                ]} />
            </div>

            {statusCheck&&<div className="alert alert--warning" role="status"><p>{feedbackText(statusCheck.unknown?"unknown":"failed")}</p><button type="button" className="btn" onClick={()=>nav(statusCheck.id?`/adjustments/${statusCheck.id}`:"/adjustments")}>{feedbackText("reload")}</button></div>}
            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/adjustments')}>{t('adjustment.cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving || !!statusCheck} onClick={() => save(false)}>{saving ? t('adjustment.saving') : t('adjustment.saveDraft')}</button>
                <ConfirmedActionButton className="btn btn--primary" disabled={!gate.allowed || saving || !!statusCheck} title={documentText("document.confirmPostTitle",undefined,{name:documentText("document.kind.adjustment","adjustment")})} message={documentText("document.confirmPostMessage")} action={documentText("document.post")} onConfirm={()=>save(true)}>{t('adjustment.savePost')}</ConfirmedActionButton>
            </div>
        </section>
    );
}
