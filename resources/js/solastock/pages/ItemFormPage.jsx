import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useMeta } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, QuickCreateSelect, Skeleton, StatusBadge, fieldErrors } from '../components/ui.jsx';

const EMPTY = {
    name: '', sku: '', barcode: '', item_type: 'inventory', description: '',
    category_id: null, brand_id: null, base_unit_id: null, preferred_supplier_id: null,
    purchase_price: '', sales_price: '', costing_method: 'average',
    reorder_point: '', reorder_qty: '', is_active: true,
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
    const unitName = useMemo(() => lookups.units?.find((u) => u.id === form.base_unit_id)?.code ?? '—', [lookups, form.base_unit_id]);

    // Setup checklist signals.
    const checks = {
        basic: !!form.name && !!form.sku,
        inventory: !!form.item_type && (isService || !!form.costing_method),
        pricing: form.purchase_price !== '' || form.sales_price !== '' || true, // optional → always ok
        ready: !!form.name && !!form.sku,
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
            toast.push(`Item saved, but opening stock could not be posted: ${e.message}`, 'error');
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

            toast.push(isEdit ? 'Item updated.' : 'Item created.', 'success');
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['item', String(newId)] });
            nav(`/items/${newId}`);
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.push(err.message || 'Could not save the item.', 'error');
        } finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;

    const errorList = Object.entries(errors);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Items', to: '/items' }, { label: isEdit ? 'Edit' : 'New item' }]} />
            <header className="page-head">
                <h1>{isEdit ? 'Edit item' : 'New item'}</h1>
                <StatusBadge active={form.is_active} />
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button type="button" className="btn" onClick={() => nav('/items')} disabled={saving}>Cancel</button>
                    <button type="submit" form="item-form" className="btn btn--primary" disabled={!gate.allowed || saving}>
                        {saving ? 'Saving…' : (isEdit ? 'Save changes' : 'Create item')}
                    </button>
                </div>
            </header>

            {!gate.allowed && <div className="banner banner--warn">{gate.reason}</div>}

            {errorList.length > 0 && (
                <div className="form-error-summary">
                    <strong>Please fix the following:</strong>
                    <ul>{errorList.map(([f, m]) => <li key={f}>{m}</li>)}</ul>
                </div>
            )}

            <form id="item-form" className="item-form-layout" onSubmit={submit}>
                <div>
                    <Section title="Basic information" sub="Name, SKU and how this item is classified.">
                        <div className="fg2">
                            <Field label="Item name" required error={errors.name}>
                                <input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="e.g. iPhone 15 Pro" />
                            </Field>
                            <Field label="SKU" required error={errors.sku}>
                                <input className="input" value={form.sku} onChange={(e) => set('sku', e.target.value)} placeholder="Unique code, e.g. IPH-15-PRO" />
                            </Field>
                            <Field label="Barcode" error={errors.barcode}>
                                <input className="input" value={form.barcode ?? ''} onChange={(e) => set('barcode', e.target.value)} placeholder="Optional" />
                            </Field>
                            <QuickCreateSelect label="Unit" value={form.base_unit_id} onChange={(v) => set('base_unit_id', v)}
                                options={lookups.units} onCreate={(n) => quickCreate('unit', n)} error={errors.base_unit_id} />
                            <QuickCreateSelect label="Category" value={form.category_id} onChange={(v) => set('category_id', v)}
                                options={lookups.categories} onCreate={(n) => quickCreate('category', n)} error={errors.category_id} />
                            <QuickCreateSelect label="Brand" value={form.brand_id} onChange={(v) => set('brand_id', v)}
                                options={lookups.brands} onCreate={(n) => quickCreate('brand', n)} error={errors.brand_id} />
                            <div className="span2">
                                <Field label="Description" error={errors.description}>
                                    <textarea className="input" rows="2" value={form.description ?? ''} onChange={(e) => set('description', e.target.value)} placeholder="Optional short description" />
                                </Field>
                            </div>
                            <Field label="Status">
                                <label className="check-inline"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label>
                            </Field>
                        </div>
                    </Section>

                    <Section title="Inventory setup" sub="How stock is tracked, costed and replenished.">
                        <div className="fg2">
                            <Field label="Item type" error={errors.item_type}>
                                <select className="input" value={form.item_type} onChange={(e) => set('item_type', e.target.value)}>
                                    <option value="inventory">Inventory (tracks stock)</option>
                                    <option value="non_inventory">Non-inventory</option>
                                    <option value="service">Service (no stock)</option>
                                </select>
                            </Field>
                            <Field label="Costing method" error={errors.costing_method}>
                                <select className="input" value={form.costing_method ?? 'average'} disabled={isService} onChange={(e) => set('costing_method', e.target.value)}>
                                    <option value="average">Weighted average</option>
                                    <option value="fifo">FIFO (cost layers)</option>
                                </select>
                            </Field>
                            <Field label="Reorder point" error={errors.reorder_point}>
                                <input className="input" type="number" step="0.0001" value={form.reorder_point ?? ''} onChange={(e) => set('reorder_point', e.target.value)} placeholder="Alert when below" />
                            </Field>
                            <Field label="Reorder quantity" error={errors.reorder_qty}>
                                <input className="input" type="number" step="0.0001" value={form.reorder_qty ?? ''} onChange={(e) => set('reorder_qty', e.target.value)} placeholder="Suggested top-up" />
                            </Field>
                            <Field label="Tracking">
                                <div className="check-row">
                                    <label><input type="checkbox" disabled={isService} checked={form.track_lot} onChange={(e) => set('track_lot', e.target.checked)} /> Lot</label>
                                    <label><input type="checkbox" disabled={isService} checked={form.track_serial} onChange={(e) => set('track_serial', e.target.checked)} /> Serial</label>
                                    <label><input type="checkbox" disabled={isService || !form.track_lot} checked={form.track_expiry} onChange={(e) => set('track_expiry', e.target.checked)} /> Expiry</label>
                                </div>
                                {isService ? <span className="field-hint">Service items don’t track stock.</span>
                                    : <span className="field-hint">Expiry requires lot tracking.</span>}
                                {errors.tracking_type && <span className="field-error">{errors.tracking_type}</span>}
                                {errors.track_expiry && <span className="field-error">{errors.track_expiry}</span>}
                            </Field>
                        </div>

                        {!isEdit && !isService && (
                            <div className="fg2" style={{ marginTop: 12, borderTop: '1px solid var(--line-soft)', paddingTop: 12 }}>
                                <div className="span2"><span className="field-hint">Opening stock (optional) — start this item with quantity on hand. Posted as an opening-stock entry after the item is created.</span></div>
                                <Field label="Opening warehouse">
                                    <select className="input" value={form.opening_warehouse_id ?? ''} onChange={(e) => set('opening_warehouse_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">— none —</option>
                                        {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                                    </select>
                                </Field>
                                <Field label="Opening quantity">
                                    <input className="input" type="number" step="0.0001" value={form.opening_qty} onChange={(e) => set('opening_qty', e.target.value)} placeholder="0" />
                                </Field>
                                <Field label="Opening cost / unit">
                                    <input className="input" type="number" step="0.0001" value={form.opening_cost} onChange={(e) => set('opening_cost', e.target.value)} placeholder="0.00" />
                                </Field>
                            </div>
                        )}
                    </Section>

                    <Section title="Pricing" sub="Default purchase and sales prices.">
                        <div className="fg2">
                            <Field label="Purchase price" error={errors.purchase_price}>
                                <input className="input" type="number" step="0.0001" value={form.purchase_price ?? ''} onChange={(e) => set('purchase_price', e.target.value)} placeholder="0.00" />
                            </Field>
                            <Field label="Sales price" error={errors.sales_price}>
                                <input className="input" type="number" step="0.0001" value={form.sales_price ?? ''} onChange={(e) => set('sales_price', e.target.value)} placeholder="0.00" />
                            </Field>
                            <div className="span2"><span className="field-hint">Leave a price blank to save it as 0 — you can set it later.</span></div>
                        </div>
                    </Section>

                    <Section title="Notes" sub="Internal notes for your team.">
                        <Field label="Notes" error={errors.notes}>
                            <textarea className="input" rows="3" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} placeholder="Optional" />
                        </Field>
                    </Section>
                </div>

                <aside className="form-side">
                    <div className="card"><div className="card-body">
                        <div className="img-dropzone">
                            <div className="img-dropzone-ico">🖼️</div>
                            <div className="img-dropzone-title">Item image</div>
                            <div className="img-dropzone-hint">
                                {isEdit ? 'Manage photos from the item’s Media tab.'
                                    : 'Save the item first, then add private photos from its Media tab.'}
                            </div>
                            {isEdit && <button type="button" className="btn btn--sm" onClick={() => nav(`/items/${id}`)}>Open Media</button>}
                        </div>
                    </div></div>

                    <div className="card"><div className="card-head"><h3>Setup checklist</h3></div><div className="card-body">
                        <ul className="checklist">
                            <li className={checks.basic ? 'done' : ''}><span className="check-dot">{checks.basic ? '✓' : ''}</span> Basic info complete</li>
                            <li className={checks.inventory ? 'done' : ''}><span className="check-dot">{checks.inventory ? '✓' : ''}</span> Inventory configured</li>
                            <li className={checks.pricing ? 'done' : ''}><span className="check-dot">{checks.pricing ? '✓' : ''}</span> Pricing set (optional)</li>
                            <li className={checks.ready ? 'done' : ''}><span className="check-dot">{checks.ready ? '✓' : ''}</span> Ready to save</li>
                        </ul>
                    </div></div>

                    <div className="card preview-card"><div className="card-head"><h3>Live preview</h3></div><div className="card-body">
                        <div className="pc-name">{form.name || 'Untitled item'}</div>
                        <div className="pc-sku">{form.sku || 'No SKU yet'}</div>
                        <div className="pc-grid">
                            <div><div className="pc-label">Unit</div><div className="pc-val">{unitName}</div></div>
                            <div><div className="pc-label">Costing</div><div className="pc-val">{isService ? '—' : (form.costing_method === 'fifo' ? 'FIFO' : 'Average')}</div></div>
                            <div><div className="pc-label">Starting stock</div><div className="pc-val">{!isEdit && Number(form.opening_qty) > 0 ? form.opening_qty : '0'}</div></div>
                            <div><div className="pc-label">Starting value</div><div className="pc-val">{!isEdit && Number(form.opening_qty) > 0 ? (Number(form.opening_qty) * Number(form.opening_cost || 0)).toFixed(2) : '0.00'}</div></div>
                        </div>
                    </div></div>
                </aside>
            </form>
        </section>
    );
}
