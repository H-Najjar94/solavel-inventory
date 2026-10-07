import {text as feedbackText} from '../../shared/feedback/messages';
import {ConfirmedActionButton} from '../components/ConfirmedActionButton';
import {t as documentText} from '../i18n/index';
import React, { useRef, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable } from '../components/document.jsx';
import { ItemPicker, WarehousePicker, BinPicker, QuantityInput, MoneyInput, UnitPicker, SupplierPicker } from '../components/pickers.jsx';
import { LotCapture, SerialNumberListInput, TraceabilityRequiredBadge } from '../components/traceability.jsx';
import { useI18n } from '../i18n/context.jsx';

const emptyLine = () => ({ item_id: null, received_qty: '', accepted_qty: '', entered_unit_id: null, unit_cost: '', bin_id: null, purchase_order_line_id: null, ordered_qty: null, remaining_qty: null, lot_code: '', expiry_date: '', serials: [] });
const enteredCost = (unitCost, factor) => factor ? String((Number(unitCost || 0) * Number(factor || 1)).toFixed(4)) : unitCost;

export default function GoodsReceiptFormPage() {
    const { t,locale } = useI18n();const ar=locale==='ar';
    const { id, poId, requestId } = useParams();
    const fromRequest = !!requestId;
    const isEdit = !!id;
    const fromPo = !!poId;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.receive_goods');
    const valuationGate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ grn_number: '', purchase_order_id: poId ? Number(poId) : null, supplier_id: null, warehouse_id: null, receipt_date: new Date().toISOString().slice(0, 10), notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [blindReceiving, setBlindReceiving] = useState(false);
    const [errors, setErrors] = useState({});
    const savedDraftId = useRef(id ?? null);
    const [saving, setSaving] = useState(false);
    const savePending=useRef(false);
    const [statusCheck,setStatusCheck]=useState(null);

    // Prefill from PO
    const poDraft = useApiQuery(['grn-from-po', poId, blindReceiving], () => api.grnDraftFromPo(poId, { blind: blindReceiving ? 1 : 0 }), { fallback: null, enabled: fromPo });
    const requestDraft = useApiQuery(['grn-from-request',requestId],()=>api.receivingRequest(requestId),{fallback:null,enabled:fromRequest});
    useEffect(()=>{if(fromRequest&&requestDraft.data){const r=requestDraft.data;setHeader(h=>({...h,receiving_request_id:r.id,supplier_id:r.supplier_id,warehouse_id:r.warehouse_id??null}));setLines(r.lines.filter(l=>Number(l.remaining_qty)>0).map(l=>({...emptyLine(),item_id:l.item_id,receiving_request_line_id:l.id,entered_unit_id:l.entered_unit_id,received_qty:l.remaining_qty,accepted_qty:l.remaining_qty,unit_cost:l.unit_cost})));}},[fromRequest,requestDraft.data]);
    const sourcePo = useApiQuery(['po', poId], () => api.purchaseOrder(poId), { fallback: null, enabled: fromPo });
    useEffect(() => {
        if (fromPo && poDraft.data) {
            const po = poDraft.data.purchase_order;
            setHeader((h) => ({ ...h, purchase_order_id: po.id, supplier_id: po.supplier_id, warehouse_id: po.warehouse_id }));
            setLines((poDraft.data.lines ?? []).map((l) => ({
                item_id: l.item_id, receiving_request_line_id: l.receiving_request_line_id, purchase_order_line_id: l.purchase_order_line_id,
                ordered_qty: l.ordered_qty ?? null, remaining_qty: l.remaining_qty ?? null,
                received_qty: l.entered_qty ?? l.received_qty,
                accepted_qty: l.entered_qty ?? l.received_qty,
                entered_unit_id: l.entered_unit_id ?? null,
                unit_cost: enteredCost(l.unit_cost, l.unit_conversion_factor), bin_id: null,
            })));
        }
    }, [fromPo, poDraft.data]);

    // Prefill from existing draft
    const existing = useApiQuery(['grn', id], () => api.goodsReceipt(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.grn) {
            const g = existing.data.grn;
            if (g.status !== 'draft') { toast.push(t('receiving.grn.messages.onlyDraftEditable', 'Only draft goods receipts can be edited.'), 'error'); nav(`/goods-receipts/${id}`); return; }
            setHeader({ grn_number: g.grn_number, purchase_order_id: g.purchase_order_id, supplier_id: g.supplier_id, warehouse_id: g.warehouse_id, receipt_date: g.receipt_date, notes: g.notes ?? '', receiving_request_id:g.receiving_request_id });
            setBlindReceiving(!!g.blind_receiving);
            setLines((g.lines ?? []).map((l) => ({
                item_id: l.item_id, receiving_request_line_id: l.receiving_request_line_id, purchase_order_line_id: l.purchase_order_line_id,
                received_qty: l.entered_qty ?? l.received_qty, accepted_qty: l.entered_qty ?? l.accepted_qty,
                rejected_qty: l.rejected_qty ?? '', disposition: l.disposition ?? 'restock',
                entered_unit_id: l.entered_unit_id ?? null, unit_cost: enteredCost(l.unit_cost, l.unit_conversion_factor),
                bin_id: l.bin_id, ordered_qty: null, remaining_qty: null,
                // Draft lots/serials already exist: keep them and resend by id.
                kept: l.lot_id || l.serial_id ? { lot_id: l.lot_id ?? null, serial_id: l.serial_id ?? null, lot_code: l.lot_code ?? null, expiry_date: l.lot_expiry_date ?? l.expiry_date ?? null, serial: l.serial ?? null } : null,
                lot_code: '', expiry_date: '', serials: [],
            })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

    // Item tracking lookup so capture columns appear only for tracked items.
    const itemsList = useApiQuery(['items-picker'], () => api.items({ per_page: 200, is_active: true }), { fallback: [] });
    const itemsArr = Array.isArray(itemsList.data) ? itemsList.data : (itemsList.data?.data ?? []);
    const trackingOf = (id) => itemsArr.find((it) => it.id === id) ?? {};

    async function save(post = false) {
        if(savePending.current||statusCheck)return;
        let savedDocument;
        if (!gate.allowed) return;
        savePending.current=true;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                blind_receiving: blindReceiving,
                lines: lines.filter((l) => l.item_id && (Number(l.received_qty) > 0 || (l.serials ?? []).length > 0)).map((l) => {
                    const t = trackingOf(l.item_id);
                    const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
                    return {
                        item_id: l.item_id, receiving_request_line_id: l.receiving_request_line_id, purchase_order_line_id: l.purchase_order_line_id,
                        received_qty: l.received_qty, accepted_qty: l.accepted_qty || l.received_qty,
                        rejected_qty: l.rejected_qty || '0',
                        inspection_status: l.disposition === 'quarantine' ? 'quarantine' : (Number(l.rejected_qty || 0) > 0 ? 'rejected' : 'accepted'),
                        disposition: l.disposition || 'restock',
                        quarantine_qty: l.disposition === 'quarantine' ? (l.accepted_qty || l.received_qty) : '0',
                        entered_qty: l.received_qty,
                        entered_unit_id: l.entered_unit_id || undefined,
                        unit_cost: l.unit_cost, bin_id: l.bin_id,
                        lot_code: l.lot_code || undefined,
                        expiry_date: l.expiry_date || undefined,
                        serials: tracksSerial && (l.serials ?? []).length > 0 ? l.serials : undefined,
                        ...(l.kept ? { lot_code: undefined, serials: undefined, lot_id: l.kept.lot_id || undefined, serial_id: l.kept.serial_id || undefined, expiry_date: l.kept.expiry_date || undefined } : {}),
                    };
                }),
            };
            if (payload.lines.length === 0) { setErrors({lines:t('receiving.grn.validation.lineRequired', 'Add at least one line with a received quantity.')}); setSaving(false); return; }
            const res = savedDraftId.current ? await api.updateGoodsReceipt(savedDraftId.current, payload) : await api.createGoodsReceipt(payload);
            const docId = res?.data?.id ?? savedDraftId.current;
            savedDraftId.current = docId;
            savedDocument=docId;
            if (post) { await api.postGoodsReceipt(docId); toast.push(t('receiving.grn.messages.posted', 'Goods receipt posted. Stock has been received.'), 'success'); }
            else toast.push(isEdit ? t('receiving.grn.messages.draftUpdated', 'Draft updated.') : t('receiving.grn.messages.draftSaved', 'Draft saved.'), 'success');
            qc.invalidateQueries({ queryKey: ['receiving-requests'] }); qc.invalidateQueries({ queryKey: ['grns'] }); qc.invalidateQueries({ queryKey: ['po'] });
            nav(`/goods-receipts/${docId}`);
        } catch (err) { if(err.outcomeUnknown||(savedDocument && err.status >= 500))setStatusCheck({id:savedDocument,unknown:!!err.outcomeUnknown}); setErrors(fieldErrors(err)); toast.failure(err, err.message || t('receiving.common.saveFailed', 'Save failed.')); }
        finally { savePending.current=false; setSaving(false); }
    }

    if ((fromPo && poDraft.isLoading) || (isEdit && existing.isLoading) || (fromRequest && requestDraft.isLoading)) return <section className="page"><Skeleton /></section>;

    // Receiving without an approved PO is a valuation/adjustment capability.
    // Operators may receive against approved POs in their assigned warehouses.
    const approvedPoRequired = gate.allowed && !valuationGate.allowed && !fromPo && !(fromRequest&&requestDraft.data?.approved)
        && (!isEdit || (existing.data?.grn && !existing.data.grn.purchase_order_id));
    if (approvedPoRequired) return <section className="page">
        <Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts'), to: '/goods-receipts' }, { label: t('receiving.grn.form.newTitle', 'New goods receipt') }]} />
        <header className="page-head"><h1>{t('receiving.grn.form.newTitle', 'New goods receipt')}</h1></header>
        <div className="banner banner--warn">{t('receiving.grn.form.approvedPoRequired', 'Receive goods from an approved purchase order for an assigned warehouse.')}</div>
        <Link className="btn btn--primary" to="/purchase-orders">{t('receiving.grn.form.openPurchaseOrders', 'Open purchase orders')}</Link>
    </section>;

    const sourcePoNumber = poDraft.data?.purchase_order?.po_number
        ?? sourcePo.data?.purchase_order?.po_number
        ?? null;
    const sourcePoLabel = sourcePoNumber ?? (header.purchase_order_id ? t('receiving.grn.form.selectedPurchaseOrder', 'Selected purchase order') : null);

    const columns = [
        { key: 'item', label: t('receiving.common.item', 'Item'), render: (l, i) => <ItemPicker stockOnly value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} disabled={fromPo || fromRequest || isEdit} /> },
        ...(fromPo && !blindReceiving ? [
            { key: 'ord', label: t('receiving.po.fields.ordered', 'Ordered'), width: 90, render: (l) => <span>{l.ordered_qty}</span> },
            { key: 'rem', label: t('receiving.common.remaining', 'Remaining'), width: 90, render: (l) => <span>{l.remaining_qty}</span> },
        ] : []),
        { key: 'recv', label: t('receiving.grn.fields.received', 'Received'), width: 110, render: (l, i) => {
            const t = trackingOf(l.item_id);
            const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
            return tracksSerial && !l.kept
                ? <span className="muted" title={t('receiving.grn.form.serialCountHint', 'Quantity equals the number of serial numbers')}>{(l.serials ?? []).length}</span>
                : <QuantityInput value={l.received_qty} onChange={(v) => setLine(i, { received_qty: v, accepted_qty: v })} />;
        } },
        { key: 'accepted', label: t('receiving.grn.fields.accepted', 'Accepted'), width: 110, render: (l, i) => <QuantityInput value={l.accepted_qty} onChange={(v) => setLine(i, { accepted_qty: v })} /> },
        { key: 'rejected', label: t('receiving.grn.fields.rejected', 'Rejected'), width: 110, render: (l, i) => <QuantityInput value={l.rejected_qty ?? ''} onChange={(v) => setLine(i, { rejected_qty: v })} /> },
        { key: 'disposition', label: t('receiving.grn.fields.disposition', 'Disposition'), width: 140, render: (l, i) => <select className="input" value={l.disposition ?? 'restock'} onChange={(e) => setLine(i, { disposition: e.target.value })}>
            <option value="restock">{t('receiving.disposition.restock', 'Restock')}</option>
            <option value="quarantine">{t('receiving.disposition.quarantine', 'Quarantine')}</option>
            <option value="reject">{t('receiving.disposition.reject', 'Reject')}</option>
        </select> },
        { key: 'unit', label: t('receiving.common.unit', 'Unit'), width: 150, render: (l, i) => <UnitPicker value={l.entered_unit_id} onChange={(v) => setLine(i, { entered_unit_id: v })} /> },
        { key: 'trace', label: t('receiving.grn.fields.traceability', 'Lot / Serial / Expiry'), render: (l, i) => {
            const t = trackingOf(l.item_id);
            if (!t.tracking_type || t.tracking_type === 'none') return <span className="muted">—</span>;
            const tracksLot = t.tracking_type === 'lot' || t.tracking_type === 'lot_serial';
            const tracksSerial = t.tracking_type === 'serial' || t.tracking_type === 'lot_serial';
            return (
                <div className="trace-cell">
                    <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} />
                    {l.kept && <span className="muted">{[l.kept.lot_code && <bdi key="lot">{l.kept.lot_code}{l.kept.expiry_date ? ` · ${l.kept.expiry_date}` : ''}</bdi>, l.kept.serial && <bdi key="serial">{l.kept.serial}</bdi>].filter(Boolean).reduce((all, part) => all.length ? [...all, ' · ', part] : [part], [])}</span>}
                    {!l.kept && tracksLot && <LotCapture value={{ lot_code: l.lot_code, expiry_date: l.expiry_date }}
                        requireExpiry={!!t.tracks_expiry}
                        onChange={(v) => setLine(i, { lot_code: v.lot_code, expiry_date: v.expiry_date })} />}
                    {!l.kept && tracksSerial && <SerialNumberListInput value={l.serials ?? []} autoFocus={false}
                        onChange={(arr) => setLine(i, { serials: arr, received_qty: String(arr.length), accepted_qty: String(arr.length) })} />}
                </div>
            );
        } },
        { key: 'bin', label: t('receiving.common.bin', 'Bin'), render: (l, i) => <BinPicker warehouseId={header.warehouse_id} value={l.bin_id} onChange={(v) => setLine(i, { bin_id: v })} /> },
        { key: 'cost', label: t('receiving.common.unitCost', 'Unit cost'), width: 110, render: (l, i) => <MoneyInput disabled={!valuationGate.allowed} value={l.unit_cost} onChange={(v) => setLine(i, { unit_cost: v })} /> },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.grn.list.title', 'Goods Receipts'), to: '/goods-receipts' }, { label: fromPo ? t('receiving.grn.form.fromPurchaseOrder', 'From :purchaseOrder', { purchaseOrder: sourcePoLabel ?? t('receiving.po.singular', 'purchase order') }) : (isEdit ? t('receiving.common.editDraft', 'Edit draft') : t('receiving.common.new', 'New')) }]} />
            <header className="page-head"><h1>{isEdit ? t('receiving.grn.form.editTitle', 'Edit goods receipt') : t('receiving.grn.form.newTitle', 'New goods receipt')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            {errors.currency && <div className="banner banner--warn" role="alert">{errors.currency}</div>}
            {!header.supplier_id&&<div className="banner banner--warn">{ar?'لم تحدد مورداً. يمكن تسجيل الاستلام، لكن لا يمكن إنشاء فاتورة مورد تلقائياً في SolaCount دون مورد فعلي مربوط. اختر المورد الصحيح قبل تأكيد الاستلام.':'No supplier selected. You can record receiving, but SolaCount cannot automatically create a supplier bill without a real mapped supplier. Select the correct supplier before confirming receipt.'}</div>}
            <div className="form-grid">
                <Field label={t('receiving.common.supplier','Supplier')} error={errors.supplier_id}><SupplierPicker activeOnly value={header.supplier_id} onChange={v=>setHeader({...header,supplier_id:v})} disabled={fromPo||fromRequest}/></Field>
                <Field label={t('receiving.grn.fields.number', 'GRN number')} required error={errors.grn_number}><input className="input" value={header.grn_number} onChange={(e) => setHeader({ ...header, grn_number: e.target.value })} /></Field>
                <Field label={t('receiving.common.warehouse', 'Warehouse')} required error={errors.warehouse_id}><WarehousePicker autoSelectDefault={!isEdit && !fromPo && (!fromRequest || (!!requestDraft.data && header.receiving_request_id === requestDraft.data.id && !requestDraft.data.approved))} defaultContext={fromRequest ? `request-${requestId}` : 'new-grn'} value={header.warehouse_id} onChange={(v) => setHeader(current => ({ ...current, warehouse_id: v }))} disabled={fromPo || (fromRequest&&requestDraft.data?.approved&&!valuationGate.allowed)} /></Field>
                <Field label={t('receiving.grn.fields.receivedDate', 'Received date')} error={errors.receipt_date}><input className="input" type="date" value={header.receipt_date} onChange={(e) => setHeader({ ...header, receipt_date: e.target.value })} /></Field>
                <Field label={t('receiving.grn.fields.sourcePo', 'Source PO')} error={errors.purchase_order_id}>{sourcePoLabel ?? <span className="muted">{t('receiving.grn.form.adHocReceipt', 'None (ad-hoc receipt)')}</span>}</Field>
                <Field label={t('receiving.common.notes', 'Notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>
            {fromPo && <label className="checkline">
                <input type="checkbox" checked={blindReceiving} onChange={(e) => setBlindReceiving(e.target.checked)} />
                {t('receiving.grn.form.blindReceiving', 'Blind receiving')}
            </label>}

            <div className="panel">
                <h2>{t('receiving.common.lines', 'Lines')}</h2>
                <DocumentLinesTable validationErrors={errors} columns={columns} lines={lines}
                    onAdd={fromPo ? undefined : () => setLines([...lines, emptyLine()])}
                    onRemove={fromPo ? () => {} : (i) => setLines(lines.filter((_, idx) => idx !== i))}
                    readOnly={false} />
                {fromPo && <p className="muted">{t('receiving.grn.form.poLinesHint', "Lines use the purchase order's remaining quantities. Adjust received amounts for partial receipts.")}</p>}
            </div>

            {statusCheck&&<div className="alert alert--warning" role="status"><p>{feedbackText(statusCheck.unknown?"unknown":"failed")}</p><button type="button" className="btn" onClick={()=>nav(statusCheck.id?`/goods-receipts/${statusCheck.id}`:"/goods-receipts")}>{feedbackText("reload")}</button></div>}
            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/goods-receipts')}>{t('receiving.common.cancel', 'Cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving || !!statusCheck} onClick={() => save(false)}>{saving ? t('receiving.common.saving', 'Saving…') : t('receiving.common.saveDraft', 'Save draft')}</button>
                <ConfirmedActionButton className="btn btn--primary" disabled={!gate.allowed || saving || !!statusCheck} title={documentText("document.confirmPostTitle",undefined,{name:documentText("document.kind.goods receipt","goods receipt")})} message={documentText("document.confirmPostMessage")} action={documentText("document.post")} onConfirm={()=>save(true)}>{t('receiving.grn.actions.saveAndPost', 'Save & post')}</ConfirmedActionButton>
            </div>
        </section>
    );
}
