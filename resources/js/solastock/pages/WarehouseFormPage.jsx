import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Field, Skeleton, StatusBadge, fieldErrors } from '../components/ui.jsx';

const EMPTY = {
    code: '', name: '', type: 'warehouse', is_active: true,
    address: { line1: '', city: '', country: '' }, max_capacity_units: '',
};

function Section({ title, sub, children }) {
    return (
        <div className="card form-section">
            <div className="card-head"><h3>{title}</h3>{sub && <span className="sub">{sub}</span>}</div>
            <div className="card-body">{children}</div>
        </div>
    );
}

export default function WarehouseFormPage() {
    const { id } = useParams();
    const isEdit = !!id;
    const nav = useNavigate();
    const toast = useToast();
    const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_warehouses');

    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const existing = useApiQuery(['warehouse', id], () => api.warehouse(id), { fallback: null, enabled: isEdit });
    useEffect(() => {
        if (isEdit && existing.data?.warehouse) {
            const w = existing.data.warehouse;
            setForm({ ...EMPTY, ...w, address: w.address ?? EMPTY.address });
        }
    }, [isEdit, existing.data]);

    function set(k, v) { setForm((f) => ({ ...f, [k]: v })); }
    function setAddr(k, v) { setForm((f) => ({ ...f, address: { ...f.address, [k]: v } })); }

    const typeLabel = { warehouse: 'Warehouse', retail: 'Retail', transit: 'Transit', virtual: 'Virtual' }[form.type] ?? form.type;
    const checks = { basic: !!form.code && !!form.name, type: !!form.type, ready: !!form.code && !!form.name };

    async function submit(e) {
        e.preventDefault();
        if (!gate.allowed || saving) return;
        setSaving(true); setErrors({});
        try {
            const res = isEdit ? await api.updateWarehouse(id, form) : await api.createWarehouse(form);
            const newId = res?.data?.id ?? id;
            toast.push(isEdit ? 'Warehouse updated.' : 'Warehouse created.', 'success');
            qc.invalidateQueries({ queryKey: ['warehouses'] });
            qc.invalidateQueries({ queryKey: ['warehouse', String(newId)] });
            nav(`/warehouses/${newId}`);
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.push(err.message || 'Could not save the warehouse.', 'error');
        } finally { setSaving(false); }
    }

    if (isEdit && existing.isLoading) return <section className="page"><Skeleton /></section>;
    const errorList = Object.entries(errors);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Warehouses', to: '/warehouses' }, { label: isEdit ? 'Edit' : 'New warehouse' }]} />
            <header className="page-head">
                <h1>{isEdit ? 'Edit warehouse' : 'New warehouse'}</h1>
                <StatusBadge active={form.is_active} />
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button type="button" className="btn" onClick={() => nav('/warehouses')} disabled={saving}>Cancel</button>
                    <button type="submit" form="wh-form" className="btn btn--primary" disabled={!gate.allowed || saving}>
                        {saving ? 'Saving…' : (isEdit ? 'Save changes' : 'Create warehouse')}
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

            <form id="wh-form" className="item-form-layout" onSubmit={submit}>
                <div>
                    <Section title="Basic information" sub="What this location is and where it sits.">
                        <div className="fg2">
                            <Field label="Name" required error={errors.name}>
                                <input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="e.g. Main Warehouse" />
                            </Field>
                            <Field label="Code" required error={errors.code}>
                                <input className="input" value={form.code} onChange={(e) => set('code', e.target.value)} placeholder="Short code, e.g. WH-MAIN" />
                            </Field>
                            <Field label="Type" error={errors.type}>
                                <select className="input" value={form.type} onChange={(e) => set('type', e.target.value)}>
                                    <option value="warehouse">Warehouse</option><option value="retail">Retail</option>
                                    <option value="transit">Transit</option><option value="virtual">Virtual</option>
                                </select>
                            </Field>
                            <Field label="Status">
                                <label className="check-inline"><input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /> Active</label>
                            </Field>
                            <div className="span2"><Field label="Address line" error={errors.address}><input className="input" value={form.address?.line1 ?? ''} onChange={(e) => setAddr('line1', e.target.value)} placeholder="Street / building" /></Field></div>
                            <Field label="City"><input className="input" value={form.address?.city ?? ''} onChange={(e) => setAddr('city', e.target.value)} /></Field>
                            <Field label="Country"><input className="input" value={form.address?.country ?? ''} onChange={(e) => setAddr('country', e.target.value)} /></Field>
                        </div>
                    </Section>

                    <Section title="Operations" sub="Capacity and structure.">
                        <div className="fg2">
                            <Field label="Capacity (units)" error={errors.max_capacity_units}>
                                <input className="input" type="number" step="0.0001" value={form.max_capacity_units ?? ''} onChange={(e) => set('max_capacity_units', e.target.value)} placeholder="Optional" />
                            </Field>
                            <div />
                            <div className="span2"><span className="field-hint">Zones and bins are managed from the warehouse’s detail page after it’s created.</span></div>
                        </div>
                    </Section>
                </div>

                <aside className="form-side">
                    <div className="card"><div className="card-body">
                        <div className="img-dropzone">
                            <div className="img-dropzone-ico">🏬</div>
                            <div className="img-dropzone-title">Warehouse banner</div>
                            <div className="img-dropzone-hint">
                                {isEdit ? 'Manage the banner from the warehouse’s Media tab.'
                                    : 'Save the warehouse first, then add a private banner photo from its Media tab.'}
                            </div>
                            {isEdit && <button type="button" className="btn btn--sm" onClick={() => nav(`/warehouses/${id}`)}>Open Media</button>}
                        </div>
                    </div></div>

                    <div className="card"><div className="card-head"><h3>Setup checklist</h3></div><div className="card-body">
                        <ul className="checklist">
                            <li className={checks.basic ? 'done' : ''}><span className="check-dot">{checks.basic ? '✓' : ''}</span> Name &amp; code</li>
                            <li className={checks.type ? 'done' : ''}><span className="check-dot">{checks.type ? '✓' : ''}</span> Type selected</li>
                            <li className={checks.ready ? 'done' : ''}><span className="check-dot">{checks.ready ? '✓' : ''}</span> Ready to save</li>
                        </ul>
                    </div></div>

                    <div className="card preview-card"><div className="card-head"><h3>Preview</h3></div><div className="card-body">
                        <div className="pc-name">{form.name || 'Untitled warehouse'}</div>
                        <div className="pc-sku">{form.code || 'No code yet'}</div>
                        <div className="pc-grid">
                            <div><div className="pc-label">Type</div><div className="pc-val">{typeLabel}</div></div>
                            <div><div className="pc-label">Status</div><div className="pc-val">{form.is_active ? 'Active' : 'Inactive'}</div></div>
                            <div><div className="pc-label">City</div><div className="pc-val">{form.address?.city || '—'}</div></div>
                            <div><div className="pc-label">Capacity</div><div className="pc-val">{form.max_capacity_units || '—'}</div></div>
                        </div>
                    </div></div>
                </aside>
            </form>
        </section>
    );
}
