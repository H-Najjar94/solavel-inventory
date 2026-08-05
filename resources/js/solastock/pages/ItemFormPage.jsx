import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useMeta } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, QuickCreateSelect, Skeleton, StatusBadge, fieldErrors } from '../components/ui.jsx';
import { t } from '../i18n/index.js';

const EMPTY = {
    name: '', sku: '', barcode: '', item_type: 'inventory', description: '',
    category_id: null, brand_id: null, base_unit_id: null, preferred_supplier_id: null,
    purchase_price: '', sales_price: '', costing_method: 'average',
    reorder_point: '', reorder_qty: '', min_stock: '', max_stock: '', safety_stock: '',
    weight: '', length: '', width: '', height: '', is_active: true,
    track_lot: false, track_serial: false, track_expiry: false, notes: '',
    // opening stock (create-only convenience; posted after the item is created)
    opening_warehouse_id: null, opening_qty: '', opening_cost: '',
};

// Section card wrapper.
function Section({ title, sub, children }) {
    return (
        <div className="card form-section">
            <div className="card-head"><h3>{title}</h3>{sub && <span className="sub">{sub}</span>}</div>
            <div className="card-body">{children}</div>
        </div>
    );
}

export default function ItemFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate();
    const toast = useToast();
    const qc = useQueryClient();
    const meta = useMeta();
    const gate = useCanCreate('inventory.manage_items');

    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const lookups = meta.lookups ?? { categories: [], brands: [], units: [] };
    const { data: suppliersData } = useApiQuery(['suppliers-lookup'], () => api.suppliers({ per_page: 100 }), { fallback: [] });
    const suppliers = Array.isArray(suppliersData) ? suppliersData : (suppliersData?.data ?? []);
    const { data: whData } = useApiQuery(['warehouses-lookup'], () => api.warehouses({ per_page: 100 }), { fallback: [] });
    const warehouses = Array.isArray(whData) ? whData : (whData?.data ?? []);

    const existing = useApiQuery(['item', id], () => api.item(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.item) {
            const it = existing.data.item;
            setForm({
                ...EMPTY, ...it,
                track_lot: ['lot', 'lot_serial'].includes(it.tracking_type),
                track_serial: ['serial', 'lot_serial'].includes(it.tracking_type),
            });
        }
    }, [isEdit, existing.data]);

    function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }

    const isService = form.item_type === 'service';
    const isInventory = form.item_type === 'inventory';
    const unitName = useMemo(() => lookups.units?.find((u) => u.id === form.base_unit_id)?.code ?? '—', [lookups, form.base_unit_id]);

    // Setup checklist signals.
    const checks = {
        basic: !!form.name && !!form.sku,
        inventory: !!form.item_type && (isService || !!form.costing_method),
        pricing: form.purchase_price !== '' || form.sales_price !== '' || true, // optional → always ok
        ready: !!form.name && !!form.sku && !!form.category_id && (!isInventory || !!form.base_unit_id),
    };

    async function quickCreate(kind, name) {
        try {
            const fn = { category: api.createCategory, brand: api.createBrand, unit: api.createUnit, supplier: api.createSupplier }[kind];
            const res = await fn(name);
            await qc.invalidateQueries({ queryKey: ['meta'] });
            await qc.invalidateQueries({ queryKey: ['suppliers-lookup'] });
            return res?.data ?? res;
        } catch (e) { toast.push(e.message, 'error'); return null; }
    }

    // After an item is created, optionally seed opening stock (a real stock write
    // via the existing opening-stock endpoints). Skipped unless a qty is entered.
    async function maybePostOpeningStock(itemId) {
        const qty = String(form.opening_qty ?? '').trim();
        if (!qty || Number(qty) <= 0 || !form.opening_warehouse_id) return;
        try {
            const draft = await api.createOpeningStock({
                entry_number: `OPEN-${form.sku}`,
                warehouse_id: form.opening_warehouse_id,
                lines: [{ item_id: itemId, quantity: qty, unit_cost: String(form.opening_cost || '0') }],
            });
            const draftId = draft?.data?.id;
            if (draftId) await api.postOpeningStock(draftId);
        } catch (e) {
            // Item already created — surface a soft warning, don't lose the item.
            toast.push(t('items.openingStockFailed', undefined, { message: e.message }), 'error');
        }
    }

    async function submit(e) {
        e.preventDefault();
        if (!gate.allowed || saving) return;
        setSaving(true); setErrors({});
        try {
            const payload = { ...form };
            // opening-stock fields are not item columns — strip before saving.
            delete payload.opening_warehouse_id; delete payload.opening_qty; delete payload.opening_cost;
            if (isService) { payload.track_lot = false; payload.track_serial = false; payload.track_expiry = false; }

            const res = isEdit ? await api.updateItem(id, payload) : await api.createItem(payload);
            const newId = res?.data?.id ?? id;
            if (!isEdit) await maybePostOpeningStock(newId);

            toast.push(t(isEdit ? 'items.updatedSuccess' : 'items.createdSuccess'), 'success');
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['item', String(newId)] });
            nav(`/items/${newId}`);
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.push(err.message || t('items.saveFailed'), 'error');
        } finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const errorList = Object.entries(errors);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('items'), to: '/items' }, { label: t(isEdit ? 'items.edit' : 'items.new') }]} />
            <header className="page-head">
                <h1>{t(isEdit ? 'items.edit' : 'items.new')}</h1>
                <StatusBadge active={form.is_active} />
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button type="button" className="btn" onClick={() => nav('/items')} disabled={saving}>{t('items.cancel')}</button>
                    <button type="submit" form="item-form" className="btn btn--primary" disabled={!gate.allowed || saving}>
                        {saving ? t('items.saving') : t(isEdit ? 'items.saveChanges' : 'items.create')}
                    </button>
                </div>
            </header>

            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            {errorList.length > 0 && (
                <div className="form-error-summary">
                    <strong>{t('items.fixErrors')}</strong>
                    <ul>{errorList.map(([f, m]) => <li key={f}>{m}</li>)}</ul>
                </div>
            )}

            <form id="item-form" className="item-form-layout" onSubmit={submit}>
                <div>
                    <Section title={t('items.basicInfo')} sub={t('items.basicInfoHint')}>
                        <div className="fg2">
                            <Field label={t('items.itemName')} required error={errors.name}>
                                <input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder={t('items.itemNamePlaceholder')} />
                            </Field>
                            <Field label={t('sku')} required error={errors.sku}>
                                <input className="input" value={form.sku} onChange={(e) => set('sku', e.target.value)} placeholder={t('items.skuPlaceholder')} />
                            </Field>
                            <Field label={t('barcode')} error={errors.barcode}>
                                <input className="input" value={form.barcode ?? ''} onChange={(e) => set('barcode', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <QuickCreateSelect label={t('unit')} value={form.base_unit_id} onChange={(v) => set('base_unit_id', v)} required={isInventory}
                                options={lookups.units} onCreate={(n) => quickCreate('unit', n)} error={errors.base_unit_id} />
                            <QuickCreateSelect label={t('category')} value={form.category_id} onChange={(v) => set('category_id', v)} required
                                options={lookups.categories} onCreate={(n) => quickCreate('category', n)} error={errors.category_id} />
                            <QuickCreateSelect label={t('brand', 'Brand')} value={form.brand_id} onChange={(v) => set('brand_id', v)}
                                options={lookups.brands} onCreate={(n) => quickCreate('brand', n)} error={errors.brand_id} />
                            <div className="span2">
                                <Field label={t('description')} error={errors.description}>
                                    <textarea className="input" rows="2" value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} placeholder={t('items.shortDescription')} />
                                </Field>
                            </div>
                            <Field label={t('items.status')}>
                                <label className="check-inline"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> {t('items.active')}</label>
                            </Field>
                        </div>
                    </Section>

                    <Section title={t('items.inventorySetup')} sub={t('items.inventorySetupHint')}>
                        <div className="fg2">
                            <Field label={t('items.itemType')} error={errors.item_type}>
                                <select className="input" value={form.item_type} onChange={(e) => set('item_type', e.target.value)}>
                                    <option value="inventory">{t('items.inventoryTracksStock')}</option>
                                    <option value="non_inventory">{t('items.nonInventoryType')}</option>
                                    <option value="service">{t('items.serviceNoStock')}</option>
                                </select>
                            </Field>
                            <Field label={t('items.costingMethod')} error={errors.costing_method}>
                                <select className="input" value={form.costing_method ?? 'average'} disabled={isService} onChange={(e) => set('costing_method', e.target.value)}>
                                    <option value="average">{t('items.weightedAverage')}</option>
                                    <option value="fifo">{t('items.fifoCostLayers')}</option>
                                </select>
                            </Field>
                            <Field label={t('items.reorderPoint')} error={errors.reorder_point}>
                                <input className="input" type="number" step="0.0001" value={form.reorder_point ?? ''} onChange={(e) => set('reorder_point', e.target.value)} placeholder={t('items.alertWhenBelow')} />
                            </Field>
                            <Field label={t('items.reorderQuantity')} error={errors.reorder_qty}>
                                <input className="input" type="number" step="0.0001" value={form.reorder_qty ?? ''} onChange={(e) => set('reorder_qty', e.target.value)} placeholder={t('items.suggestedTopUp')} />
                            </Field>
                            <Field label={t('items.minimumStock')} error={errors.min_stock}>
                                <input className="input" type="number" step="0.0001" value={form.min_stock ?? ''} onChange={(e) => set('min_stock', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <Field label={t('items.maximumStock')} error={errors.max_stock}>
                                <input className="input" type="number" step="0.0001" value={form.max_stock ?? ''} onChange={(e) => set('max_stock', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <Field label={t('items.safetyStock')} error={errors.safety_stock}>
                                <input className="input" type="number" step="0.0001" value={form.safety_stock ?? ''} onChange={(e) => set('safety_stock', e.target.value)} placeholder={t('items.bufferQuantity')} />
                            </Field>
                            <Field label={t('items.tracking')}>
                                <div className="check-row">
                                    <label><input type="checkbox" disabled={isService} checked={form.track_lot} onChange={(e) => set('track_lot', e.target.checked)} /> {t('items.lot')}</label>
                                    <label><input type="checkbox" disabled={isService} checked={form.track_serial} onChange={(e) => set('track_serial', e.target.checked)} /> {t('items.serial')}</label>
                                    <label><input type="checkbox" disabled={isService || !form.track_lot} checked={form.track_expiry} onChange={(e) => set('track_expiry', e.target.checked)} /> {t('items.expiry')}</label>
                                </div>
                                {isService ? <span className="field-hint">{t('items.serviceNoTracking')}</span>
                                    : <span className="field-hint">{t('items.expiryLotHint')}</span>}
                                {errors.tracking_type && <span className="field-error">{errors.tracking_type}</span>}
                                {errors.track_expiry && <span className="field-error">{errors.track_expiry}</span>}
                            </Field>
                        </div>

                        {!isEdit && !isService && (
                            <div className="fg2" style={{ marginTop: 12, borderTop: '1px solid var(--line-soft)', paddingTop: 12 }}>
                                <div className="span2"><span className="field-hint">{t('items.openingStock')}</span></div>
                                <Field label={t('items.openingWarehouse')}>
                                    <select className="input" value={form.opening_warehouse_id ?? ''} onChange={(e) => set('opening_warehouse_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">{t('items.none')}</option>
                                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                </Field>
                                <Field label={t('items.openingQuantity')}>
                                    <input className="input" type="number" step="0.0001" value={form.opening_qty} onChange={(e) => set('opening_qty', e.target.value)} placeholder="0" />
                                </Field>
                                <Field label={t('items.openingCost')}>
                                    <input className="input" type="number" step="0.0001" value={form.opening_cost} onChange={(e) => set('opening_cost', e.target.value)} placeholder="0.00" />
                                </Field>
                            </div>
                        )}
                    </Section>

                    <Section title={t('items.physicalAttributes')} sub={t('items.physicalAttributesHint')}>
                        <div className="fg2">
                            <Field label={t('items.weight')} error={errors.weight}>
                                <input className="input" type="number" step="0.0001" value={form.weight ?? ''} onChange={(e) => set('weight', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <Field label={t('items.length')} error={errors.length}>
                                <input className="input" type="number" step="0.0001" value={form.length ?? ''} onChange={(e) => set('length', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <Field label={t('items.width')} error={errors.width}>
                                <input className="input" type="number" step="0.0001" value={form.width ?? ''} onChange={(e) => set('width', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                            <Field label={t('items.height')} error={errors.height}>
                                <input className="input" type="number" step="0.0001" value={form.height ?? ''} onChange={(e) => set('height', e.target.value)} placeholder={t('items.optional')} />
                            </Field>
                        </div>
                    </Section>

                    <Section title={t('items.pricing')} sub={t('items.pricingHint')}>
                        <div className="fg2">
                            <Field label={t('items.purchasePrice')} error={errors.purchase_price}>
                                <input className="input" type="number" step="0.0001" value={form.purchase_price ?? ''} onChange={(e) => set('purchase_price', e.target.value)} placeholder="0.00" />
                            </Field>
                            <Field label={t('items.salesPrice')} error={errors.sales_price}>
                                <input className="input" type="number" step="0.0001" value={form.sales_price ?? ''} onChange={(e) => set('sales_price', e.target.value)} placeholder="0.00" />
                            </Field>
                            <div className="span2"><span className="field-hint">{t('items.priceBlankHint')}</span></div>
                        </div>
                    </Section>

                    <Section title={t('items.notes')} sub={t('items.notesHint')}>
                        <Field label={t('items.notes')} error={errors.notes}>
                            <textarea className="input" rows="3" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} placeholder={t('items.optional')} />
                        </Field>
                    </Section>
                </div>

                <aside className="form-side">
                    <div className="card"><div className="card-body">
                        <div className="img-dropzone">
                            <div className="img-dropzone-ico">🖼️</div>
                            <div className="img-dropzone-title">{t('items.image')}</div>
                            <div className="img-dropzone-hint">
                                {isEdit ? t('items.manageMedia') : t('items.saveThenMedia')}
                            </div>
                            {isEdit && <button type="button" className="btn btn--sm" onClick={() => nav(`/items/${id}`)}>{t('items.openMedia')}</button>}
                        </div>
                    </div></div>

                    <div className="card"><div className="card-head"><h3>{t('items.setupChecklist')}</h3></div><div className="card-body">
                        <ul className="checklist">
                            <li className={checks.basic ? 'done' : ''}><span className="check-dot">{checks.basic ? '✓' : ''}</span> {t('items.basicComplete')}</li>
                            <li className={checks.inventory ? 'done' : ''}><span className="check-dot">{checks.inventory ? '✓' : ''}</span> {t('items.inventoryConfigured')}</li>
                            <li className={checks.pricing ? 'done' : ''}><span className="check-dot">{checks.pricing ? '✓' : ''}</span> {t('items.pricingOptional')}</li>
                            <li className={checks.ready ? 'done' : ''}><span className="check-dot">{checks.ready ? '✓' : ''}</span> {t('items.readyToSave')}</li>
                        </ul>
                    </div></div>

                    <div className="card preview-card"><div className="card-head"><h3>{t('items.livePreview')}</h3></div><div className="card-body">
                        <div className="pc-name">{form.name || t('items.untitled')}</div>
                        <div className="pc-sku">{form.sku || t('items.noSkuYet')}</div>
                        <div className="pc-grid">
                            <div><div className="pc-label">{t('unit')}</div><div className="pc-val">{unitName}</div></div>
                            <div><div className="pc-label">{t('items.costing')}</div><div className="pc-val">{isService ? '—' : (form.costing_method === 'fifo' ? 'FIFO' : t('items.average'))}</div></div>
                            <div><div className="pc-label">{t('items.startingStock')}</div><div className="pc-val">{!isEdit && Number(form.opening_qty) > 0 ? form.opening_qty : '0'}</div></div>
                            <div><div className="pc-label">{t('items.startingValue')}</div><div className="pc-val">{!isEdit && Number(form.opening_qty) > 0 ? (Number(form.opening_qty) * Number(form.opening_cost || 0)).toFixed(2) : '0.00'}</div></div>
                        </div>
                    </div></div>
                </aside>
            </form>
        </section>
    );
}
