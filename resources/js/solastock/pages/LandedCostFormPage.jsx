import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { DocumentTotals } from '../components/document.jsx';
import { MoneyInput } from '../components/pickers.jsx';
import { ConfirmedActionButton } from '../components/ConfirmedActionButton';
import LandedCostConnectionPanel from '../components/LandedCostConnectionPanel.jsx';
import { t } from '../i18n/index.js';

const CHARGE_TYPES = ['freight', 'duty', 'insurance', 'other'];
const METHODS = ['value', 'quantity', 'weight'];
const emptyCharge = () => ({ charge_type: 'freight', description: '', amount: '' });

export default function LandedCostFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_adjustments');

    const [header, setHeader] = useState({ landed_cost_date: new Date().toISOString().slice(0, 10), allocation_method: 'value', currency_code: '', exchange_rate: '1', supplier_reference: '', notes: '' });
    const [charges, setCharges] = useState([emptyCharge()]);
    const [selected, setSelected] = useState(() => new Set());
    const [search, setSearch] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const pending = useRef(false);

    const existing = useApiQuery(['landed-cost', id], () => api.landedCost(id), { fallback: null, enabled: isEdit });
    const connection = useApiQuery(['landed-cost-connection'], api.landedCostConnection, { fallback: null });
    const receipts = useApiQuery(['landed-cost-receipts', search], () => api.landedCostReceiptLines({ search }), { fallback: { receipts: [] } });
    const base = connection.data?.base_currency ?? null;
    const connected = connection.data?.mode === 'connected';

    useEffect(() => {
        if (!isEdit && base && !header.currency_code) setHeader((h) => ({ ...h, currency_code: base }));
    }, [base]);

    useEffect(() => {
        const doc = existing.data?.landed_cost;
        if (!isEdit || !doc) return;
        if (doc.status !== 'draft') { toast.push(t('landedCosts.readOnly'), 'error'); nav(`/landed-costs/${id}`); return; }
        setHeader({ landed_cost_date: doc.landed_cost_date, allocation_method: doc.allocation_method, currency_code: doc.currency_code,
            exchange_rate: String(Number(doc.exchange_rate)), supplier_reference: doc.supplier_reference ?? '', notes: doc.notes ?? '' });
        setCharges((doc.charges ?? []).map((c) => ({ charge_type: c.charge_type, description: c.description ?? '', amount: c.amount })));
        setSelected(new Set((doc.lines ?? []).map((l) => Number(l.goods_receipt_line_id))));
    }, [isEdit, existing.data]);

    const receiptList = receipts.data?.receipts ?? [];
    const total = useMemo(() => charges.reduce((s, c) => s + Number(c.amount || 0), 0), [charges]);
    const rate = Number(header.exchange_rate || 0);
    const setCharge = (i, patch) => setCharges((cs) => cs.map((c, idx) => idx === i ? { ...c, ...patch } : c));
    const toggle = (lineId) => setSelected((s) => { const n = new Set(s); n.has(lineId) ? n.delete(lineId) : n.add(lineId); return n; });

    async function save(post = false) {
        if (pending.current || !gate.allowed) return;
        pending.current = true; setSaving(true); setErrors({});
        try {
            const payload = { ...header, currency_code: header.currency_code || null,
                charges: charges.filter((c) => Number(c.amount) > 0),
                receipt_line_ids: [...selected] };
            const res = isEdit ? await api.updateLandedCost(id, payload) : await api.createLandedCost(payload);
            const docId = res?.data?.id ?? id;
            if (post) { await api.postLandedCost(docId); toast.push(t('landedCosts.posted'), 'success'); }
            else toast.push(t('landedCosts.draftSaved'), 'success');
            qc.invalidateQueries({ queryKey: ['landed-costs'] });
            qc.invalidateQueries({ queryKey: ['landed-cost'] });
            nav(`/landed-costs/${docId}`);
        } catch (err) { setErrors(fieldErrors(err)); toast.failure(err, err.message || t('landedCosts.saveFailed')); }
        finally { pending.current = false; setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('landedCosts.title'), to: '/landed-costs' }, { label: t(isEdit ? 'landedCosts.edit' : 'landedCosts.new') }]} />
            <header className="page-head"><h1>{t(isEdit ? 'landedCosts.edit' : 'landedCosts.new')}</h1></header>
            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}
            <LandedCostConnectionPanel status={connection.data} />
            {errors.landed_cost && <div className="banner banner--warn" role="alert">{errors.landed_cost}</div>}

            <div className="form-grid">
                <Field label={t('landedCosts.date')} error={errors.landed_cost_date}><input className="input" type="date" value={header.landed_cost_date} onChange={(e) => setHeader({ ...header, landed_cost_date: e.target.value })} /></Field>
                <Field label={t('landedCosts.reference')} error={errors.supplier_reference}><input className="input" value={header.supplier_reference} placeholder={t('landedCosts.referenceHint')} onChange={(e) => setHeader({ ...header, supplier_reference: e.target.value })} /></Field>
                <Field label={t('landedCosts.method')} required error={errors.allocation_method}>
                    <select className="input" value={header.allocation_method} onChange={(e) => setHeader({ ...header, allocation_method: e.target.value })}>
                        {METHODS.map((m) => <option key={m} value={m}>{t(`landedCosts.method.${m}`)}</option>)}
                    </select>
                </Field>
                <Field label={t('landedCosts.currency')} required error={errors.currency_code}>
                    {connected ? (
                        <select className="input" value={header.currency_code} onChange={(e) => setHeader({ ...header, currency_code: e.target.value, exchange_rate: e.target.value === base ? '1' : header.exchange_rate })}>
                            {[base, ...(connection.data?.enabled_currencies ?? []).filter((c) => c !== base)].filter(Boolean).map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                    ) : (
                        <input className="input" maxLength={3} value={header.currency_code} onChange={(e) => setHeader({ ...header, currency_code: e.target.value.toUpperCase() })} />
                    )}
                </Field>
                <Field label={t('landedCosts.rate')} required error={errors.exchange_rate}>
                    <input className="input input--num" type="number" step="0.000001" min="0" disabled={connected && header.currency_code === base}
                        value={header.exchange_rate} onChange={(e) => setHeader({ ...header, exchange_rate: e.target.value })} />
                    <small className="muted">{connected
                        ? t('landedCosts.rateHint', undefined, { base: base ?? '', currency: header.currency_code })
                        : t('landedCosts.rateHintStandalone')}</small>
                </Field>
                <Field label={t('landedCosts.notes')}><input className="input" value={header.notes} onChange={(e) => setHeader({ ...header, notes: e.target.value })} /></Field>
            </div>

            <div className="panel">
                <h2>{t('landedCosts.charges')}</h2>
                <table className="data-table"><thead><tr><th>{t('landedCosts.chargeType')}</th><th>{t('landedCosts.description')}</th><th>{t('landedCosts.amount')}</th><th></th></tr></thead>
                    <tbody>{charges.map((c, i) => (<tr key={i}>
                        <td><select className="input" value={c.charge_type} onChange={(e) => setCharge(i, { charge_type: e.target.value })}>
                            {CHARGE_TYPES.map((ct) => <option key={ct} value={ct}>{t(`landedCosts.chargeType.${ct}`)}</option>)}</select></td>
                        <td><input className="input" value={c.description} onChange={(e) => setCharge(i, { description: e.target.value })} /></td>
                        <td><MoneyInput value={c.amount} onChange={(v) => setCharge(i, { amount: v })} /></td>
                        <td><button type="button" className="btn" disabled={charges.length === 1} onClick={() => setCharges(charges.filter((_, idx) => idx !== i))}>{t('landedCosts.remove')}</button></td>
                    </tr>))}</tbody></table>
                <button type="button" className="btn" onClick={() => setCharges([...charges, emptyCharge()])}>{t('landedCosts.addCharge')}</button>
                <DocumentTotals rows={[
                    { label: `${t('landedCosts.total')} (${header.currency_code || '—'})`, value: total.toFixed(2) },
                    { label: t('landedCosts.totalBase'), value: rate > 0 ? (total / rate).toFixed(2) : '—' },
                ]} />
            </div>

            <div className="panel">
                <h2>{t('landedCosts.receiptLines')}</h2>
                <p className="muted">{t('landedCosts.selectLines')} · {t('landedCosts.selected', undefined, { count: selected.size })}</p>
                {errors.receipt_line_ids && <p className="field-error">{errors.receipt_line_ids}</p>}
                <input className="input" type="search" placeholder={t('landedCosts.receiptSearch')} value={search} onChange={(e) => setSearch(e.target.value)} />
                {receiptList.length === 0 ? <p className="muted">{t('landedCosts.noReceipts')}</p> : (
                    <table className="data-table"><thead><tr><th></th><th>{t('landedCosts.receipt')}</th><th>{t('landedCosts.item')}</th><th>{t('landedCosts.warehouse')}</th>
                        <th>{t('landedCosts.quantity')}</th><th>{t('landedCosts.value')}</th><th>{t('landedCosts.weight')}</th></tr></thead>
                    <tbody>{receiptList.flatMap((r) => (r.lines ?? []).map((l) => (
                        <tr key={l.id}>
                            <td><input type="checkbox" checked={selected.has(Number(l.id))} onChange={() => toggle(Number(l.id))} aria-label={`${r.grn_number} ${l.item_name ?? ''}`} /></td>
                            <td>{r.grn_number}<span className="muted"> · {r.receipt_date}</span></td>
                            <td>{l.item_name ?? `#${l.item_id}`}{l.item_sku && <span className="muted"> · {l.item_sku}</span>}</td>
                            <td>{r.warehouse_name ?? `#${r.warehouse_id}`}</td>
                            <td>{l.accepted_qty}</td><td>{(Number(l.accepted_qty) * Number(l.unit_cost)).toFixed(2)}</td>
                            <td>{l.weight ? (Number(l.accepted_qty) * Number(l.weight)).toFixed(2) : '—'}</td>
                        </tr>)))}</tbody></table>
                )}
            </div>

            <div className="doc-actions">
                <button className="btn" onClick={() => nav('/landed-costs')}>{t('landedCosts.cancel')}</button>
                <button className="btn" disabled={!gate.allowed || saving} onClick={() => save(false)}>{saving ? t('landedCosts.saving') : t('landedCosts.saveDraft')}</button>
                <ConfirmedActionButton className="btn btn--primary" disabled={!gate.allowed || saving}
                    title={t('document.confirmPostTitle', undefined, { name: t('document.kind.landed cost', 'landed cost') })}
                    message={t('document.confirmPostMessage')} action={t('document.post')} onConfirm={() => save(true)}>{t('landedCosts.savePost')}</ConfirmedActionButton>
            </div>
        </section>
    );
}
