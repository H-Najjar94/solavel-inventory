import React, { useMemo, useState } from 'react';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCan, useMeta } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';
import { Field, fieldErrors } from './ui.jsx';
import { SupplierPicker } from './pickers.jsx';
import { TermsModal, TERM_STATUSES, trimDecimal, unitLabel, Num } from './SupplierTermsShared.jsx';
import { t } from '../i18n/index.js';

const SCALES = [3, 4, 5, 6];
let tierSeq = 0;
const tierKey = () => `tier-${++tierSeq}`;
const blankTier = () => ({ key: tierKey(), id: null, minimum_qty: '1', unit_cost: '', effective_from: '', effective_to: '', is_active: true });
const text = (value) => (value === null || value === undefined ? '' : String(value));

function initialForm(relationship, baseUnitId) {
    if (!relationship) {
        return {
            supplier_id: null, status: 'approved', is_preferred: false, supplier_sku: '', supplier_item_name: '', supplier_item_description: '',
            currency_code: '', purchase_unit_id: baseUnitId ?? null, minimum_order_qty: '', order_multiple: '', lead_time_days: '',
            discount_percent: '', price_scale: '', notes: '', tiers: [blankTier()],
        };
    }
    return {
        supplier_id: relationship.supplier_id,
        status: relationship.status ?? 'approved',
        is_preferred: Boolean(relationship.is_preferred),
        supplier_sku: text(relationship.supplier_sku),
        supplier_item_name: text(relationship.supplier_item_name),
        supplier_item_description: text(relationship.supplier_item_description),
        currency_code: text(relationship.currency_code),
        purchase_unit_id: relationship.purchase_unit_id ?? baseUnitId ?? null,
        minimum_order_qty: trimDecimal(relationship.minimum_order_qty),
        order_multiple: trimDecimal(relationship.order_multiple),
        lead_time_days: text(relationship.lead_time_days),
        discount_percent: trimDecimal(relationship.discount_percent),
        price_scale: text(relationship.price_scale),
        notes: text(relationship.notes),
        tiers: (relationship.tiers ?? []).map((tier) => ({
            key: tierKey(), id: tier.id, minimum_qty: trimDecimal(tier.minimum_qty), unit_cost: trimDecimal(tier.unit_cost),
            effective_from: text(tier.effective_from), effective_to: text(tier.effective_to), is_active: tier.is_active !== false,
        })),
    };
}

const isBlankTier = (tier) => !tier.id && !String(tier.minimum_qty).trim() && !String(tier.unit_cost).trim() && !tier.effective_from && !tier.effective_to;
const orNull = (value) => (String(value ?? '').trim() === '' ? null : String(value).trim());

/** Add / edit one supplier's terms for an item, including the full price-tier set. */
export default function ItemSupplierModal({ itemId, baseUnit, relationship, linkedSupplierIds = [], onClose, onSaved }) {
    const isEdit = Boolean(relationship);
    const toast = useToast();
    const meta = useMeta();
    const can = useCan();
    const baseUnitId = baseUnit?.id ?? null;
    const [form, setForm] = useState(() => initialForm(relationship, baseUnitId));
    const [errors, setErrors] = useState({});
    const [problems, setProblems] = useState([]);
    const [saving, setSaving] = useState(false);
    const set = (patch) => setForm((current) => ({ ...current, ...patch }));
    const setTier = (index, patch) => setForm((current) => ({ ...current, tiers: current.tiers.map((tier, i) => (i === index ? { ...tier, ...patch } : tier)) }));

    // Purchase units: the base unit plus units with a conversion to it (global or for this item).
    const settings = useApiQuery(['settings'], api.settings, { fallback: null, enabled: can('inventory.view_settings') });
    const conversions = settings.data?.unit_conversions;
    const units = useMemo(() => {
        const all = meta.lookups?.units ?? [];
        if (!Array.isArray(conversions) || !baseUnitId) return all;
        const convertible = new Set(conversions
            .filter((c) => Number(c.to_unit_id) === Number(baseUnitId) && (!c.item_id || Number(c.item_id) === Number(itemId)))
            .map((c) => Number(c.from_unit_id)));
        return all.filter((u) => Number(u.id) === Number(baseUnitId) || convertible.has(Number(u.id)) || Number(u.id) === Number(form.purchase_unit_id));
    }, [meta.lookups?.units, conversions, baseUnitId, itemId, form.purchase_unit_id]);
    const unitsFiltered = Array.isArray(conversions);

    const currencies = useMemo(() => {
        const codes = new Set();
        (meta.partner_currencies ?? []).forEach((c) => c?.code && codes.add(c.code));
        (meta.document_currency?.enabled ?? []).forEach((code) => code && codes.add(code));
        if (form.currency_code) codes.add(form.currency_code);
        return [...codes];
    }, [meta.partner_currencies, meta.document_currency, form.currency_code]);
    const baseCurrency = meta.document_currency?.base;

    const scale = form.price_scale ? Number(form.price_scale) : null;
    const priceStep = scale ? (1 / 10 ** scale).toFixed(scale) : 'any';
    const purchaseUnit = units.find((u) => Number(u.id) === Number(form.purchase_unit_id)) ?? (Number(form.purchase_unit_id) === Number(baseUnitId) ? baseUnit : null);

    async function save() {
        setSaving(true); setErrors({}); setProblems([]);
        const sent = form.tiers.map((tier, index) => ({ tier, index })).filter(({ tier }) => !isBlankTier(tier));
        const body = {
            status: form.status,
            is_preferred: form.status === 'approved' ? Boolean(form.is_preferred) : false,
            supplier_sku: orNull(form.supplier_sku),
            supplier_item_name: orNull(form.supplier_item_name),
            supplier_item_description: orNull(form.supplier_item_description),
            currency_code: orNull(form.currency_code),
            purchase_unit_id: form.purchase_unit_id || null,
            minimum_order_qty: orNull(form.minimum_order_qty),
            order_multiple: orNull(form.order_multiple),
            lead_time_days: orNull(form.lead_time_days),
            discount_percent: orNull(form.discount_percent),
            price_scale: form.price_scale ? Number(form.price_scale) : null,
            notes: orNull(form.notes),
            tiers: sent.map(({ tier }) => ({
                ...(tier.id ? { id: tier.id } : {}),
                minimum_qty: String(tier.minimum_qty).trim(),
                unit_cost: String(tier.unit_cost).trim(),
                effective_from: tier.effective_from || null,
                effective_to: tier.effective_to || null,
                is_active: Boolean(tier.is_active),
            })),
        };
        if (!isEdit) body.supplier_id = form.supplier_id;
        if (!isEdit && !form.supplier_id) {
            setErrors({ supplier_id: t('supplierTerms.form.supplierRequired') });
            setSaving(false);
            return;
        }
        try {
            const res = isEdit ? await api.updateItemSupplier(itemId, relationship.id, body) : await api.createItemSupplier(itemId, body);
            toast.push(t(isEdit ? 'supplierTerms.form.updated' : 'supplierTerms.form.created'), 'success');
            onSaved?.(res?.data ?? null);
        } catch (err) {
            if (err?.status === 422) {
                // Tier errors come back by the index sent; map them to the row on screen.
                const raw = fieldErrors(err);
                const mapped = {};
                Object.entries(raw).forEach(([key, message]) => {
                    const match = /^tiers\.(\d+)\.(.+)$/.exec(key);
                    mapped[match && sent[Number(match[1])] ? `tiers.${sent[Number(match[1])].index}.${match[2]}` : key] = message;
                });
                setErrors(mapped);
                const list = Object.values(mapped);
                setProblems(list.length ? list : [err.message]);
            } else if ([402, 403].includes(err?.status)) {
                setProblems([t('supplierTerms.access.saveDenied')]);
            } else {
                toast.failure(err, t('supplierTerms.form.saveFailed'));
            }
        } finally {
            setSaving(false);
        }
    }

    const supplierName = relationship?.supplier?.name;
    const title = isEdit ? t('supplierTerms.form.editTitle', undefined, { supplier: supplierName ?? '' }) : t('supplierTerms.form.addTitle');
    const duplicate = !isEdit && form.supplier_id && linkedSupplierIds.map(Number).includes(Number(form.supplier_id));

    return (
        <TermsModal wide title={title} subtitle={t('supplierTerms.form.subtitle')} onClose={onClose} busy={saving}
            actions={<>
                <button type="button" className="btn" onClick={onClose} disabled={saving}>{t('supplierTerms.common.cancel')}</button>
                <button type="button" className="btn btn--primary" onClick={save} disabled={saving || duplicate}>{saving ? t('supplierTerms.common.saving') : t('supplierTerms.common.save')}</button>
            </>}>
            {problems.length > 0 && <div className="banner banner--danger" role="alert" data-feedback-validation tabIndex={-1}>
                <strong>{t('supplierTerms.form.fixErrors')}</strong>
                <ul className="st-error-list">{problems.map((message, i) => <li key={i}>{message}</li>)}</ul>
            </div>}

            <h4 className="st-section-title">{t('supplierTerms.form.sectionSupplier')}</h4>
            <div className="fg2 st-form-grid">
                {isEdit
                    ? <Field label={t('supplierTerms.common.supplier')}><span className="input is-disabled" aria-readonly="true">{supplierName ?? `#${relationship.supplier_id}`}</span></Field>
                    : <Field label={t('supplierTerms.common.supplier')} required error={errors.supplier_id || (duplicate ? t('supplierTerms.form.duplicateSupplier') : undefined)}>
                        <SupplierPicker value={form.supplier_id} onChange={(v) => set({ supplier_id: v })} />
                    </Field>}
                <Field label={t('supplierTerms.common.status')} error={errors.status}>
                    <select className="input" value={form.status} onChange={(e) => set({ status: e.target.value, is_preferred: e.target.value === 'approved' ? form.is_preferred : false })}>
                        {TERM_STATUSES.map((status) => <option key={status} value={status}>{t(`supplierTerms.status.${status}`)}</option>)}
                    </select>
                    <span className="field-hint">{t(`supplierTerms.status.${form.status}Hint`)}</span>
                </Field>
                <Field label={t('supplierTerms.form.supplierSku')} error={errors.supplier_sku}>
                    <input className="input" dir="ltr" maxLength={100} value={form.supplier_sku} onChange={(e) => set({ supplier_sku: e.target.value })} />
                </Field>
                <Field label={t('supplierTerms.form.supplierItemName')} error={errors.supplier_item_name}>
                    <input className="input" dir="auto" maxLength={255} value={form.supplier_item_name} onChange={(e) => set({ supplier_item_name: e.target.value })} />
                </Field>
            </div>
            <Field label={t('supplierTerms.form.supplierItemDescription')} error={errors.supplier_item_description}>
                <textarea className="input" dir="auto" rows={2} maxLength={2000} value={form.supplier_item_description} onChange={(e) => set({ supplier_item_description: e.target.value })} />
            </Field>
            <label className="check-inline st-check">
                <input type="checkbox" checked={form.is_preferred} disabled={form.status !== 'approved'} onChange={(e) => set({ is_preferred: e.target.checked })} />
                {t('supplierTerms.form.isPreferred')}
            </label>
            {errors.is_preferred && <span className="field-error" role="alert">{errors.is_preferred}</span>}

            <h4 className="st-section-title">{t('supplierTerms.form.sectionTerms')}</h4>
            <div className="fg2 st-form-grid">
                <Field label={t('supplierTerms.common.currency')} error={errors.currency_code}>
                    {currencies.length > 0
                        ? <select className="input" dir="ltr" value={form.currency_code} onChange={(e) => set({ currency_code: e.target.value })}>
                            <option value="">{baseCurrency ? t('supplierTerms.form.currencyBase', undefined, { code: baseCurrency }) : t('supplierTerms.form.currencyDefault')}</option>
                            {currencies.map((code) => <option key={code} value={code}>{code}</option>)}
                        </select>
                        : <input className="input" dir="ltr" maxLength={3} placeholder={t('supplierTerms.form.currencyDefault')} value={form.currency_code}
                            onChange={(e) => set({ currency_code: e.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3) })} />}
                </Field>
                <Field label={t('supplierTerms.form.purchaseUnit')} error={errors.purchase_unit_id}>
                    <select className="input" value={form.purchase_unit_id ?? ''} onChange={(e) => set({ purchase_unit_id: e.target.value ? Number(e.target.value) : null })}>
                        {!baseUnitId && <option value="">{t('supplierTerms.form.baseUnit')}</option>}
                        {units.map((u) => <option key={u.id} value={u.id}>{`${u.code} · ${u.name}`}{Number(u.id) === Number(baseUnitId) ? ` (${t('supplierTerms.form.baseUnit')})` : ''}</option>)}
                    </select>
                    <span className="field-hint">{t(unitsFiltered ? 'supplierTerms.form.purchaseUnitHint' : 'supplierTerms.form.purchaseUnitHintUnfiltered', undefined, { unit: unitLabel(baseUnit) || '—' })}</span>
                </Field>
                <Field label={t('supplierTerms.form.minimumOrderQty')} error={errors.minimum_order_qty}>
                    <input className="input input--num" type="number" inputMode="decimal" step="any" min="0" dir="ltr" value={form.minimum_order_qty} onChange={(e) => set({ minimum_order_qty: e.target.value })} />
                    <span className="field-hint">{t('supplierTerms.form.inPurchaseUnit', undefined, { unit: unitLabel(purchaseUnit) || '—' })}</span>
                </Field>
                <Field label={t('supplierTerms.form.orderMultiple')} error={errors.order_multiple}>
                    <input className="input input--num" type="number" inputMode="decimal" step="any" min="0" dir="ltr" value={form.order_multiple} onChange={(e) => set({ order_multiple: e.target.value })} />
                    <span className="field-hint">{t('supplierTerms.form.orderMultipleHint')}</span>
                </Field>
                <Field label={t('supplierTerms.form.leadTimeDays')} error={errors.lead_time_days}>
                    <input className="input input--num" type="number" inputMode="numeric" step="1" min="0" max="3650" dir="ltr" value={form.lead_time_days} onChange={(e) => set({ lead_time_days: e.target.value })} />
                </Field>
                <Field label={t('supplierTerms.form.discountPercent')} error={errors.discount_percent}>
                    <input className="input input--num" type="number" inputMode="decimal" step="any" min="0" max="100" dir="ltr" value={form.discount_percent} onChange={(e) => set({ discount_percent: e.target.value })} />
                </Field>
                <Field label={t('supplierTerms.form.priceScale')} error={errors.price_scale}>
                    <select className="input" value={form.price_scale} onChange={(e) => set({ price_scale: e.target.value })}>
                        <option value="">{t('supplierTerms.form.priceScaleDefault')}</option>
                        {SCALES.map((s) => <option key={s} value={String(s)}>{t('supplierTerms.form.priceScaleOption', undefined, { scale: s })}</option>)}
                    </select>
                    <span className="field-hint">{t('supplierTerms.form.priceScaleHint')}</span>
                </Field>
            </div>
            <Field label={t('supplierTerms.form.notes')} error={errors.notes}>
                <textarea className="input" dir="auto" rows={2} maxLength={2000} value={form.notes} onChange={(e) => set({ notes: e.target.value })} />
            </Field>

            <div className="st-section-head">
                <h4 className="st-section-title">{t('supplierTerms.tiers.title')}</h4>
                <button type="button" className="btn btn--sm" onClick={() => set({ tiers: [...form.tiers, blankTier()] })} disabled={form.tiers.length >= 50}>+ {t('supplierTerms.tiers.add')}</button>
            </div>
            <p className="field-hint">{t('supplierTerms.tiers.hint', undefined, { unit: unitLabel(purchaseUnit) || '—', currency: form.currency_code || baseCurrency || '—' })}</p>
            {errors.tiers && <span className="field-error" role="alert">{errors.tiers}</span>}
            {form.tiers.length === 0 ? <p className="muted">{t('supplierTerms.tiers.none')}</p> : (
                <div className="tbl-wrap st-tiers"><table className="data-table">
                    <thead><tr>
                        <th>{t('supplierTerms.tiers.minimumQty')}</th><th>{t('supplierTerms.tiers.unitCost')}</th>
                        <th>{t('supplierTerms.tiers.validFrom')}</th><th>{t('supplierTerms.tiers.validTo')}</th>
                        <th>{t('supplierTerms.tiers.active')}</th><th className="col-action" />
                    </tr></thead>
                    <tbody>{form.tiers.map((tier, index) => {
                        const err = (field) => errors[`tiers.${index}.${field}`];
                        return (
                            <tr key={tier.key} className={['minimum_qty', 'unit_cost', 'effective_from', 'effective_to', 'id'].some(err) ? 'row--error' : ''}>
                                <td>
                                    <input className="input input--num" type="number" inputMode="decimal" step="any" min="0" dir="ltr" aria-label={t('supplierTerms.tiers.minimumQtyAria', undefined, { row: index + 1 })}
                                        value={tier.minimum_qty} onChange={(e) => setTier(index, { minimum_qty: e.target.value })} aria-invalid={err('minimum_qty') ? 'true' : undefined} />
                                    {err('minimum_qty') && <span className="field-error">{err('minimum_qty')}</span>}
                                    {err('id') && <span className="field-error">{err('id')}</span>}
                                </td>
                                <td>
                                    <input className="input input--num" type="number" inputMode="decimal" step={priceStep} min="0" dir="ltr" aria-label={t('supplierTerms.tiers.unitCostAria', undefined, { row: index + 1 })}
                                        value={tier.unit_cost} onChange={(e) => setTier(index, { unit_cost: e.target.value })} aria-invalid={err('unit_cost') ? 'true' : undefined} />
                                    {err('unit_cost') && <span className="field-error">{err('unit_cost')}</span>}
                                </td>
                                <td>
                                    <input className="input" type="date" aria-label={t('supplierTerms.tiers.validFromAria', undefined, { row: index + 1 })}
                                        value={tier.effective_from} onChange={(e) => setTier(index, { effective_from: e.target.value })} />
                                    {err('effective_from') && <span className="field-error">{err('effective_from')}</span>}
                                </td>
                                <td>
                                    <input className="input" type="date" aria-label={t('supplierTerms.tiers.validToAria', undefined, { row: index + 1 })}
                                        value={tier.effective_to} onChange={(e) => setTier(index, { effective_to: e.target.value })} />
                                    {err('effective_to') && <span className="field-error">{err('effective_to')}</span>}
                                </td>
                                <td><input type="checkbox" checked={tier.is_active} aria-label={t('supplierTerms.tiers.activeAria', undefined, { row: index + 1 })} onChange={(e) => setTier(index, { is_active: e.target.checked })} /></td>
                                <td className="col-action"><button type="button" className="btn btn--sm btn--icon btn--danger-ghost" aria-label={t('supplierTerms.tiers.remove', undefined, { row: index + 1 })}
                                    onClick={() => set({ tiers: form.tiers.filter((_, i) => i !== index) })}>×</button></td>
                            </tr>
                        );
                    })}</tbody>
                </table></div>
            )}
            {scale && <p className="field-hint">{t('supplierTerms.tiers.scaleHint', undefined, { scale })} <Num>{priceStep}</Num></p>}
        </TermsModal>
    );
}
