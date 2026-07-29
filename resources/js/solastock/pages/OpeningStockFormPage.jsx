import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable, DocumentTotals } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput, MoneyInput, UnitPicker } from '../components/pickers.jsx';
import { LotCapture, SerialNumberListInput, TraceabilityRequiredBadge } from '../components/traceability.jsx';
import { useItemTracking } from '../hooks/useItemTracking.js';
import { useI18n } from '../i18n/context.jsx';
import { openingStockT } from '../i18n/openingStock.js';

const emptyLine = () => ({ item_id: null, bin_id: null, quantity: '', entered_unit_id: null, unit_cost: '', lot_code: '', expiry_date: '', serials: [], notes: '' });
const enteredCost = (unitCost, factor) => factor ? String((Number(unitCost || 0) * Number(factor || 1)).toFixed(4)) : unitCost;

export default function OpeningStockFormPage() {
    const { locale } = useI18n();
    const t = (key, params) => openingStockT(locale, key, params);
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_opening_stock');

    const [header, setHeader] = useState({ entry_number: '', opening_date: new Date().toISOString().slice(0, 10), warehouse_id: null, notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['opening', id], () => api.openingStockEntry(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.entry) {
            const e = existing.data.entry;
            if (e.status !== 'draft') { toast.push(t('openingStock.readOnly'), 'error'); nav(`/opening-stock/${id}`); return; }
            setHeader({ entry_number: e.entry_number, opening_date: e.opening_date, warehouse_id: e.warehouse_id, notes: e.notes ?? '' });
            setLines((e.lines ?? []).map((l) => ({ item_id: l.item_id, bin_id: l.bin_id, quantity: l.entered_qty ?? l.quantity, entered_unit_id: l.entered_unit_id ?? null, unit_cost: enteredCost(l.unit_cost, l.unit_conversion_factor), lot_code: '', notes: l.notes ?? '' })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const tracking = useItemTracking();
    const total = lines.reduce((s, l) => s + (Number(l.quantity || 0) * Number(l.unit_cost || 0)), 0);

    async function save(post = false) {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines
                    .filter((l) => l.item_id && (Number(l.quantity) > 0 || (l.serials ?? []).length > 0))
                    .map((l) => {
                        const sl = tracking.tracksSerial(l.item_id) && (l.serials ?? []).length > 0;
                        return {
                            item_id: l.item_id, bin_id: l.bin_id,
                            quantity: sl ? String(l.serials.length) : l.quantity,
                            entered_qty: sl ? undefined : l.quantity,
                            entered_unit_id: sl ? undefined : (l.entered_unit_id || undefined),
                            unit_cost: l.unit_cost,
                            lot_code: l.lot_code || undefined,
                            expiry_date: l.expiry_date || undefined,
                            serials: sl ? l.serials : undefined,
                            notes: l.notes,
                        };
                    }),
            };
            if (payload.lines.length === 0) { toast.push(t('openingStock.addLineRequired'), 'error'); setSaving(false); return; }
            let res = isEdit ? await api.updateOpeningStock(id, payload) : await api.createOpeningStock(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postOpeningStock(docId); toast.push(t('openingStock.posted'), 'success'); }
            else toast.push(t(isEdit ? 'openingStock.draftUpdated' : 'openingStock.draftSaved'), 'success');
            qc.invalidateQueries({ queryKey: ['opening'] });
            nav(`/opening-stock/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || t('openingStock.saveFailed'), 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: t('openingStock.item'), render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'bin', label: t('openingStock.bin'), render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'qty', label: t('openingStock.quantity'), width: 120, render: (l, i) => tracking.tracksSerial(l.item_id)
            ? <span className="muted" title={t('openingStock.serialQuantity')}>{(l.serials ?? []).length}</span>
            : <QuantityInput value={l.quantity} onChange={(v) => setLine(i, { quantity: v })} /> },
        { key: 'unit', label: t('openingStock.unit'), width: 150, render: (l, i) => tracking.tracksSerial(l.item_id)
            ? <span className="muted">{t('openingStock.eachSerial')}</span>
            : <UnitPicker value={l.entered_unit_id} onChange={(v) => setLine(i, { entered_unit_id: v })} /> },
        { key: 'trace', label: t('openingStock.lotSerialExpiry'), render: (l, i) => {
            const trackingInfo = tracking.trackingOf(l.item_id);
            if (!trackingInfo.tracking_type || trackingInfo.tracking_type === 'none') return <span className="muted">—</span>;
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={trackingInfo.tracking_type} tracksExpiry={trackingInfo.tracks_expiry} />
                    {tracking.tracksLot(l.item_id) && <LotCapture value={{ lot_code: l.lot_code, expiry_date: l.expiry_date }} requireExpiry={!!trackingInfo.tracks_expiry}
                        onChange={(v) => setLine(i, { lot_code: v.lot_code, expiry_date: v.expiry_date })} />}
                    {tracking.tracksSerial(l.item_id) && <SerialNumberListInput value={l.serials ?? []} autoFocus={false}
                        onChange={(arr) => setLine(i, { serials: arr, quantity: String(arr.length) })} />}
                </div>
            );
        } },
        { key: 'cost', label: t('openingStock.unitCost'), width: 120, render: (l, i) => <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('openingStock.title'), to: '/opening-stock' }, { label: t(isEdit ? 'openingStock.editDraft' : 'openingStock.new') }]} />
            <header className="page-head"><h1>{t(isEdit ? 'openingStock.editTitle' : 'openingStock.newTitle')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('openingStock.documentNumber')} error={errors.entry_number}><input className="input" placeholder={t('openingStock.documentNumberPlaceholder')} value={header.entry_number} onChange={(e) => setHeader({ ...header, entry_number: e.target.value })} /></Field>
                <Field label={t('openingStock.openingDate')} error={errors.opening_date}><input className="input" type="date" value={header.opening_date} onChange={(e) => setHeader({ ...header, opening_date: e.target.value })} /></Field>
                <Field label={t('openingStock.warehouse')} required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label={t('openingStock.notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t('openingStock.lines')}</h2>
                <DocumentLinesTable columns={columns} lines={lines} onAdd={() => setLines([...lines, emptyLine()])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <DocumentTotals rows={[{ label: t('openingStock.totalValue'), value: total.toFixed(2) }]} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/opening-stock')}>{t('openingStock.cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? t('openingStock.saving') : t('openingStock.saveDraft')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={() => save(true)}>{t('openingStock.savePost')}</button>
            </div>
        </section>
    );
}
