import React, { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, CustomerPicker, QuantityInput, MoneyInput } from '../components/pickers.jsx';
import { useI18n } from '../i18n/context.jsx';

const emptyLine = () => ({ item_id: null, returned_qty: '', unit_cost: '', condition: 'resellable', bin_id: null });

export default function SalesReturnFormPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const isEdit = !!id;
    const [sp] = useSearchParams();
    const shipmentId = sp.get('shipment_id');
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_returns');

    const [header, setHeader] = useState({ return_number: '', shipment_id: shipmentId ? Number(shipmentId) : null, customer_id: null, customer_name: '', warehouse_id: null, return_date: new Date().toISOString().slice(0, 10), reason: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    // Prefill warehouse + lines from the source shipment.
    const ship = useApiQuery(['shipment-for-return', shipmentId], () => api.shipment(shipmentId), { fallback: null, enabled: !!shipmentId && !isEdit });
    useEffect(() => {
        if (!isEdit && shipmentId && ship.data?.shipment) {
            const s = ship.data.shipment;
            setHeader((h) => ({ ...h, warehouse_id: s.warehouse_id }));
            // Preserve the shipped lot/serial identity on the return lines.
            setLines((s.lines ?? []).map((l) => ({ item_id: l.item_id, returned_qty: l.quantity, unit_cost: l.unit_cost ?? '', condition: 'resellable', bin_id: l.bin_id, lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, is_manual: false })));
        }
    }, [isEdit, shipmentId, ship.data]);

    const existing = useApiQuery(['sales-return', id], () => api.salesReturn(id), { fallback: null, enabled: isEdit });
    const sourceDriven = !!shipmentId || !!existing.data?.sales_return?.is_source_reversal;
    useEffect(() => {
        if (isEdit && existing.data?.sales_return) {
            const r = existing.data.sales_return;
            if (r.status !== 'draft') { toast.push(t('returns.messages.onlyDraftEditable', 'Only draft sales returns can be edited.'), 'error'); nav(`/sales-returns/${id}`); return; }
            setHeader({ return_number: r.return_number, shipment_id: r.shipment_id, customer_id: r.customer_id ?? null, customer_name: r.customer_name ?? '', warehouse_id: r.warehouse_id, return_date: r.return_date?.slice(0, 10), reason: r.reason ?? '', notes: r.notes ?? '' });
            setLines((r.lines ?? []).map((l) => ({ item_id: l.item_id, returned_qty: l.returned_qty, unit_cost: l.unit_cost, condition: l.condition, bin_id: l.bin_id, lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, is_manual: !r.shipment_id })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                lines: lines.filter((l) => l.item_id && Number(l.returned_qty) > 0).map((l) => ({ item_id: l.item_id, returned_qty: l.returned_qty, unit_cost: l.unit_cost || 0, condition: l.condition, bin_id: l.bin_id, lot_id: l.lot_id || undefined, serial_id: l.serial_id || undefined, lot_code: l.lot_code || undefined, is_manual: !header.shipment_id })),
            };
            if (payload.lines.length === 0) { toast.push(t('returns.validation.lineRequired', 'Add at least one line with a returned quantity.'), 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updateSalesReturn(id, payload) : await api.createSalesReturn(payload);
            const docId = res?.data?.id ?? id;
            toast.push(isEdit ? t('returns.messages.updated', 'Draft updated.') : t('returns.messages.created', 'Sales return created.'), 'success');
            qc.invalidateQueries({ queryKey: ['sales-returns'] });
            nav(`/sales-returns/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || t('returns.messages.saveFailed', 'The sales return could not be saved.'), 'error'); }
        finally { setSaving(false); }
    }

    if ((isEdit && existing.isLoading) || (shipmentId && !isEdit && ship.isLoading)) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: t('returns.common.item', 'Item'), render: (l, i) => sourceDriven ? <span>#{l.item_id}</span> : <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'qty', label: t('returns.form.returnedQuantity', 'Returned quantity'), width: 110, render: (l, i) => sourceDriven ? <span>{l.returned_qty}</span> : <QuantityInput value={l.returned_qty} onChange={(v) => setLine(i, { returned_qty: v })} /> },
        { key: 'cond', label: t('returns.form.condition', 'Condition'), width: 180, render: (l, i) => (
            sourceDriven ? <span>{t('returns.condition.resellable', 'Resellable')}</span> : <select className="input" value={l.condition} onChange={(e) => setLine(i, { condition: e.target.value })}>
                <option value="resellable">{t('returns.condition.resellable', 'Resellable')}</option>
                        <option value="quarantine">{t('returns.condition.quarantine', 'Quarantine')}</option>
                        <option value="damaged">{t('returns.condition.damaged', 'Damaged (no restock)')}</option>
                        <option value="retired">{t('returns.condition.retired', 'Retired (no restock)')}</option>
            </select>) },
        { key: 'bin', label: t('returns.common.bin', 'Bin'), render: (l, i) => sourceDriven ? <span>{l.bin_id ? `#${l.bin_id}` : '—'}</span> : <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        ...(sourceDriven ? [{ key: 'traceability', label: t('returns.form.traceability', 'Lot / Serial'), render: (l) => (
            <span>{l.lot_id ? t('returns.form.lotReference', 'Lot #:reference', { reference: l.lot_id }) : (l.serial_id ? t('returns.form.serialReference', 'Serial #:reference', { reference: l.serial_id }) : t('returns.form.traceabilityNone', 'Not tracked'))}</span>
        ) }] : []),
        { key: 'cost', label: t('returns.common.unitCost', 'Unit cost'), width: 110, render: (l, i) => sourceDriven ? <span>{l.unit_cost}</span> : <MoneyInput value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('returns.list.title', 'Sales Returns'), to: '/sales-returns' }, { label: isEdit ? t('returns.common.editDraft', 'Edit draft') : t('returns.common.new', 'New') }]} />
            <header className="page-head"><h1>{isEdit ? t('returns.form.editTitle', 'Edit sales return') : t('returns.form.newTitle', 'New sales return')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('returns.form.number', 'Return number')} required error={errors.return_number}><input className="input" value={header.return_number} onChange={(e) => setHeader({ ...header, return_number: e.target.value })} /></Field>
                <Field label={t('returns.common.customer', 'Customer')} error={errors.customer_id}><CustomerPicker value={header.customer_id} onChange={(v) => setHeader({ ...header, customer_id: v })} /></Field>
                <Field label={t('returns.form.customerName', 'Customer name')} error={errors.customer_name}><input className="input" value={header.customer_name} onChange={(e) => setHeader({ ...header, customer_name: e.target.value })} placeholder={t('returns.form.customerNamePlaceholder', 'Optional override')} /></Field>
                <Field label={t('returns.common.warehouse', 'Warehouse')} required error={errors.warehouse_id}>{sourceDriven ? <span>#{header.warehouse_id ?? '—'} ({t('returns.form.fromShipment', 'from shipment')})</span> : <WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} />}</Field>
                <Field label={t('returns.form.returnDate', 'Return date')} error={errors.return_date}><input className="input" type="date" value={header.return_date} onChange={(e) => setHeader({ ...header, return_date: e.target.value })} /></Field>
                <Field label={t('returns.form.sourceShipment', 'Source shipment')}>{header.shipment_id ? `#${header.shipment_id}` : <span className="muted">{t('returns.form.noSourceShipment', 'None')}</span>}</Field>
                <Field label={t('returns.common.reason', 'Reason')} error={errors.reason}><input className="input" value={header.reason} onChange={(e) => setHeader({ ...header, reason: e.target.value })} /></Field>
                <Field label={t('returns.common.notes', 'Notes')} error={errors.notes}><textarea className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t('returns.common.lines', 'Lines')}</h2>
                <DocumentLinesTable columns={columns} lines={lines}
                    onAdd={() => setLines([...lines, emptyLine()])}
                    onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} readOnly={sourceDriven} />
                <p className="muted">{sourceDriven ? t('returns.form.sourceLockedHint', 'Quantities, warehouse, lot or serial identity, and cost are locked to the posted shipment ledger.') : t('returns.form.manualHint', 'Resellable and quarantined units re-enter stock at their shipment unit cost. Damaged and retired units are recorded without being returned to stock.')}</p>
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/sales-returns')}>{t('returns.common.cancel', 'Cancel')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? t('returns.common.saving', 'Saving…') : t('returns.common.saveDraft', 'Save draft')}</button>
            </div>
        </section>
    );
}
