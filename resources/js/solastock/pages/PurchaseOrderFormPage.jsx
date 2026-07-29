import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentLinesTable, DocumentTotals } from '../components/document.jsx';
import { ItemPicker, SupplierPicker, WarehousePicker, QuantityInput, MoneyInput, UnitPicker } from '../components/pickers.jsx';
import { useI18n } from '../i18n/context.jsx';

const emptyLine = () => ({ item_id: null, ordered_qty: '', entered_unit_id: null, unit_price: '', tax_code: '', notes: '' });
const enteredCost = (unitCost, factor) => factor ? String((Number(unitCost || 0) * Number(factor || 1)).toFixed(4)) : unitCost;

export default function PurchaseOrderFormPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ po_number: '', supplier_id: null, warehouse_id: null, order_date: new Date().toISOString().slice(0, 10), expected_date: '', notes: '' });
    const [lines, setLines] = useState([emptyLine()]);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['po', id], () => api.purchaseOrder(id), { fallback: null, enabled: isEdit });
    const settings = useApiQuery(['settings'], api.settings, { fallback: { settings: { taxes: [] } } });
    const taxOptions = (settings.data?.settings?.taxes ?? []).filter((tax) => tax.active && tax.purchase);
    useEffect(() => {
        const code = settings.data?.settings?.default_purchase_tax_code;
        if (!isEdit && code) setLines((current) => current.map((line) => line.tax_code ? line : { ...line, tax_code: code }));
    }, [isEdit, settings.data?.settings?.default_purchase_tax_code]);
    useEffect(() => {
        if (isEdit && existing.data?.purchase_order) {
            const po = existing.data.purchase_order;
            if (po.status !== 'draft') { toast.push(t('receiving.po.messages.onlyDraftEditable', 'Only draft purchase orders can be edited.'), 'error'); nav(`/purchase-orders/${id}`); return; }
            setHeader({ po_number: po.po_number, supplier_id: po.supplier_id, warehouse_id: po.warehouse_id, order_date: po.order_date, expected_date: po.expected_date ?? '', notes: po.notes ?? '' });
            setLines((existing.data.lines ?? po.lines ?? []).map((l) => ({
                item_id: l.item_id,
                ordered_qty: l.entered_qty ?? l.ordered_qty,
                entered_unit_id: l.entered_unit_id ?? null,
                unit_price: enteredCost(l.unit_price, l.unit_conversion_factor),
                tax_code: l.tax_code ?? '',
                notes: l.notes ?? '',
            })));
        }
    }, [isEdit, existing.data]);

    const setLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));
    const totals = lines.reduce((sum, line) => {
        const net = Number(line.ordered_qty || 0) * Number(line.unit_price || 0);
        const tax = taxOptions.find((option) => option.code === line.tax_code);
        const rate = tax && tax.treatment === 'standard' ? Number(tax.rate || 0) : 0;
        return { net: sum.net + net, tax: sum.tax + (net * rate / 100) };
    }, { net: 0, tax: 0 });

    async function save() {
        if (!gate.allowed) return;
        setSaving(true); setErrors({});
        try {
            const payload = {
                ...header,
                expected_date: header.expected_date || null,
                lines: lines.filter((l) => l.item_id && Number(l.ordered_qty) > 0).map((l) => ({
                    ...l,
                    entered_qty: l.ordered_qty,
                    entered_unit_id: l.entered_unit_id || undefined,
                })),
            };
            if (payload.lines.length === 0) { toast.push(t('receiving.po.validation.lineRequired', 'Add at least one line.'), 'error'); setSaving(false); return; }
            const res = isEdit ? await api.updatePurchaseOrder(id, payload) : await api.createPurchaseOrder(payload);
            toast.push(isEdit ? t('receiving.po.messages.updated', 'Purchase order updated.') : t('receiving.po.messages.created', 'Purchase order created.'), 'success');
            qc.invalidateQueries({ queryKey: ['pos'] });
            nav(`/purchase-orders/${res?.data?.id ?? id}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.push(err.message || t('receiving.common.saveFailed', 'Save failed.'), 'error'); }
        finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const columns = [
        { key: 'item', label: t('receiving.common.item', 'Item'), render: (l, i) => <ItemPicker value={l.item_id} onChange={(v) => setLine(i, { item_id: v })} /> },
        { key: 'qty', label: t('receiving.common.quantity', 'Quantity'), width: 120, render: (l, i) => <QuantityInput value={l.ordered_qty} onChange={(v) => setLine(i, { ordered_qty: v })} /> },
        { key: 'unit', label: t('receiving.common.unit', 'Unit'), width: 150, render: (l, i) => <UnitPicker value={l.entered_unit_id} onChange={(v) => setLine(i, { entered_unit_id: v })} /> },
        { key: 'price', label: t('receiving.common.unitCost', 'Unit cost'), width: 120, render: (l, i) => <MoneyInput value={l.unit_price} onChange={(v) => setLine(i, { unit_price: v })} /> },
        { key: 'tax', label: t('receiving.common.tax', 'Tax'), width: 150, render: (l, i) => <select className="input" aria-label={t('receiving.po.form.taxAria', 'Purchase tax for line :line', { line: i + 1 })} value={l.tax_code} onChange={(e) => setLine(i, { tax_code: e.target.value })}><option value="">{t('receiving.po.form.noTax', 'No tax')}</option>{taxOptions.map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.treatment === 'standard' ? `${tax.rate}%` : t(`receiving.taxTreatment.${tax.treatment}`, tax.treatment)}</option>)}</select> },
        { key: 'line_total', label: t('receiving.common.lineTotal', 'Line total'), width: 110, render: (l) => { const net = Number(l.ordered_qty || 0) * Number(l.unit_price || 0); const tax = taxOptions.find((option) => option.code === l.tax_code); const rate = tax?.treatment === 'standard' ? Number(tax.rate || 0) : 0; return <span>{(net * (1 + rate / 100)).toFixed(2)}</span>; } },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('receiving.po.list.title', 'Purchase Orders'), to: '/purchase-orders' }, { label: isEdit ? t('receiving.common.editDraft', 'Edit draft') : t('receiving.common.new', 'New') }]} />
            <header className="page-head"><h1>{isEdit ? t('receiving.po.form.editTitle', 'Edit purchase order') : t('receiving.po.form.newTitle', 'New purchase order')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            <div className="form-grid">
                <Field label={t('receiving.po.fields.number', 'PO number')} error={errors.po_number}><input className="input" placeholder={t('receiving.po.form.numberPlaceholder', 'Auto-generated if left blank')} value={header.po_number} onChange={(e) => setHeader({ ...header, po_number: e.target.value })} /></Field>
                <Field label={t('receiving.common.supplier', 'Supplier')} error={errors.supplier_id}><SupplierPicker value={header.supplier_id} onChange={(v) => setHeader({ ...header, supplier_id: v })} /></Field>
                <Field label={t('receiving.common.warehouse', 'Warehouse')} required error={errors.warehouse_id}><WarehousePicker value={header.warehouse_id} onChange={(v) => setHeader({ ...header, warehouse_id: v })} /></Field>
                <Field label={t('receiving.po.fields.orderDate', 'Order date')} error={errors.order_date}><input className="input" type="date" value={header.order_date} onChange={(e) => setHeader({ ...header, order_date: e.target.value })} /></Field>
                <Field label={t('receiving.po.fields.expectedDate', 'Expected date')} error={errors.expected_date}><input className="input" type="date" value={header.expected_date} onChange={(e) => setHeader({ ...header, expected_date: e.target.value })} /></Field>
                <Field label={t('receiving.common.notes', 'Notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t('receiving.common.lines', 'Lines')}</h2>
                <DocumentLinesTable columns={columns} lines={lines} onAdd={() => setLines([...lines, { ...emptyLine(), tax_code: settings.data?.settings?.default_purchase_tax_code ?? '' }])} onRemove={(i) => setLines(lines.filter((_, idx) => idx !== i))} />
                <DocumentTotals rows={[{ label: t('receiving.common.net', 'Net'), value: totals.net.toFixed(2) }, { label: t('receiving.common.tax', 'Tax'), value: totals.tax.toFixed(2) }, { label: t('receiving.common.total', 'Total'), value: (totals.net + totals.tax).toFixed(2) }]} />
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/purchase-orders')}>{t('receiving.common.cancel', 'Cancel')}</button>
                <button className="btn btn--primary" disabled={!gate.allowed || saving} onClick={save}>{saving ? t('receiving.common.saving', 'Saving…') : (isEdit ? t('receiving.common.saveChanges', 'Save changes') : t('receiving.po.actions.create', 'Create PO'))}</button>
            </div>
        </section>
    );
}
