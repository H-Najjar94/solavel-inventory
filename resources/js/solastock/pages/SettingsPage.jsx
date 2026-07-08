import React, { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { Field, fieldErrors } from '../components/ui.jsx';

export default function SettingsPage() {
    const { data, isMock } = useApiQuery(['settings'], api.settings, { fallback: { settings: null, units: [], categories: [], brands: [], items: [], warehouses: [], warehouse_reorder_rules: [] } });
    const integration = useApiQuery(['integration'], api.integrationStatus, { fallback: { connected: false, planned_events: [], account_mappings: {} } });
    const qc = useQueryClient();
    const toast = useToast();
    const s = data ?? {};
    const intg = integration.data ?? {};
    const [policy, setPolicy] = useState({ default_costing_method: 'average', allow_negative_stock: false, picking_policy: 'manual' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [calculatingSafety, setCalculatingSafety] = useState(false);
    const [safetyResult, setSafetyResult] = useState(null);
    const [conversion, setConversion] = useState({ from_unit_id: '', to_unit_id: '', factor: '' });
    const [category, setCategory] = useState({ name: '', parent_id: '' });
    const [brand, setBrand] = useState({ name: '' });
    const [editingCategory, setEditingCategory] = useState(null);
    const [editingBrand, setEditingBrand] = useState(null);
    const [reasonCode, setReasonCode] = useState({ code: '', label: '' });
    const [reorderRule, setReorderRule] = useState({
        item_id: '',
        warehouse_id: '',
        reorder_point: '',
        reorder_qty: '',
        min_stock: '',
        max_stock: '',
        safety_stock: '',
        lookback_days: '30',
        lead_time_days: '14',
        service_factor: '1.65',
    });

    useEffect(() => {
        if (s.settings) {
            setPolicy({
                default_costing_method: s.settings.default_costing_method ?? 'average',
                allow_negative_stock: !!s.settings.allow_negative_stock,
                picking_policy: s.settings.picking_policy ?? 'manual',
            });
        }
    }, [s.settings]);

    async function savePolicy() {
        setSaving(true); setErrors({});
        try {
            await api.updateSettings(policy);
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Settings saved.', 'success');
        } catch (e) {
            setErrors(fieldErrors(e));
            toast.push(e.message || 'Could not save settings.', 'error');
        } finally {
            setSaving(false);
        }
    }

    async function addConversion(e) {
        e.preventDefault();
        try {
            await api.createUnitConversion({
                from_unit_id: Number(conversion.from_unit_id),
                to_unit_id: Number(conversion.to_unit_id),
                factor: conversion.factor,
            });
            setConversion({ from_unit_id: '', to_unit_id: '', factor: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            toast.push('Unit conversion saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addCategory(e) {
        e.preventDefault();
        try {
            await api.createCategory(category.name, category.parent_id ? Number(category.parent_id) : null);
            setCategory({ name: '', parent_id: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Category saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function saveCategoryEdit(e) {
        e.preventDefault();
        if (!editingCategory) return;
        try {
            await api.updateCategory(editingCategory.id, {
                name: editingCategory.name,
                parent_id: editingCategory.parent_id ? Number(editingCategory.parent_id) : null,
                is_active: editingCategory.is_active !== false,
            });
            setEditingCategory(null);
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Category updated.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addBrand(e) {
        e.preventDefault();
        try {
            await api.createBrand(brand.name);
            setBrand({ name: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Brand saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function saveBrandEdit(e) {
        e.preventDefault();
        if (!editingBrand) return;
        try {
            await api.updateBrand(editingBrand.id, {
                name: editingBrand.name,
                is_active: editingBrand.is_active !== false,
            });
            setEditingBrand(null);
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Brand updated.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addReorderRule(e) {
        e.preventDefault();
        try {
            await api.createWarehouseReorderRule({
                reorder_point: reorderRule.reorder_point,
                reorder_qty: reorderRule.reorder_qty,
                min_stock: reorderRule.min_stock,
                max_stock: reorderRule.max_stock,
                safety_stock: reorderRule.safety_stock,
                item_id: Number(reorderRule.item_id),
                warehouse_id: Number(reorderRule.warehouse_id),
            });
            setReorderRule({ item_id: '', warehouse_id: '', reorder_point: '', reorder_qty: '', min_stock: '', max_stock: '', safety_stock: '', lookback_days: '30', lead_time_days: '14', service_factor: '1.65' });
            setSafetyResult(null);
            await qc.invalidateQueries({ queryKey: ['settings'] });
            toast.push('Warehouse reorder rule saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function calculateSafetyStock() {
        if (!reorderRule.item_id || !reorderRule.warehouse_id) {
            toast.push('Select an item and warehouse first.', 'error');
            return;
        }
        setCalculatingSafety(true);
        try {
            const response = await api.calculateWarehouseReorderRule({
                item_id: Number(reorderRule.item_id),
                warehouse_id: Number(reorderRule.warehouse_id),
                lookback_days: Number(reorderRule.lookback_days || 30),
                lead_time_days: Number(reorderRule.lead_time_days || 14),
                service_factor: Number(reorderRule.service_factor || 1.65),
            });
            const result = response.data;
            setSafetyResult(result);
            setReorderRule({
                ...reorderRule,
                safety_stock: result.calculated_safety_stock,
                reorder_point: result.calculated_reorder_point,
                reorder_qty: result.suggested_reorder_qty,
            });
            toast.push('Safety stock calculated.', 'success');
        } catch (err) {
            toast.push(err.message, 'error');
        } finally {
            setCalculatingSafety(false);
        }
    }

    async function addReasonCode(e) {
        e.preventDefault();
        try {
            await api.createAdjustmentReasonCode(reasonCode);
            setReasonCode({ code: '', label: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Adjustment reason code saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    return (
        <section className="page">
            <header className="page-head"><h1>Settings</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>

            <div className="panel">
                <h2>Inventory Policy</h2>
                <div className="fg2">
                    <Field label="Default costing method" error={errors.default_costing_method}>
                        <select className="input" value={policy.default_costing_method} onChange={(e) => setPolicy({ ...policy, default_costing_method: e.target.value })}>
                            <option value="average">Weighted average</option>
                            <option value="fifo">FIFO</option>
                            <option value="standard">Standard</option>
                        </select>
                    </Field>
                    <Field label="Picking policy" error={errors.picking_policy}>
                        <select className="input" value={policy.picking_policy} onChange={(e) => setPolicy({ ...policy, picking_policy: e.target.value })}>
                            <option value="manual">Manual</option>
                            <option value="fifo">FIFO</option>
                            <option value="fefo">FEFO</option>
                        </select>
                    </Field>
                    <Field label="Negative stock">
                        <label className="check-inline"><input type="checkbox" checked={policy.allow_negative_stock} onChange={(e) => setPolicy({ ...policy, allow_negative_stock: e.target.checked })} /> Allow when policy permits it</label>
                    </Field>
                </div>
                <button className="btn btn--primary" disabled={saving} onClick={savePolicy}>{saving ? 'Saving…' : 'Save policy'}</button>
            </div>

            <div className="panel">
                <h2>Master Data</h2>
                <p className="muted">Units: {(s.units ?? []).length} · Categories: {(s.categories ?? []).length} · Brands: {(s.brands ?? []).length}</p>
                <div className="overview-grid">
                    <form className="card" onSubmit={addCategory}><div className="card-head"><h3>Category hierarchy</h3></div><div className="card-body">
                        <Field label="Name"><input className="input" value={category.name} onChange={(e) => setCategory({ ...category, name: e.target.value })} required /></Field>
                        <Field label="Parent">
                            <select className="input" value={category.parent_id} onChange={(e) => setCategory({ ...category, parent_id: e.target.value })}>
                                <option value="">Top level</option>
                                {(s.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Field>
                        <button className="btn btn--primary">Add category</button>
                    </div></form>
                    <form className="card" onSubmit={addBrand}><div className="card-head"><h3>Brand</h3></div><div className="card-body">
                        <Field label="Name"><input className="input" value={brand.name} onChange={(e) => setBrand({ name: e.target.value })} required /></Field>
                        <button className="btn btn--primary">Add brand</button>
                    </div></form>
                    <form className="card" onSubmit={addConversion}><div className="card-head"><h3>Unit conversion</h3></div><div className="card-body">
                        <Field label="From unit"><select className="input" value={conversion.from_unit_id} onChange={(e) => setConversion({ ...conversion, from_unit_id: e.target.value })} required>
                            <option value="">Select</option>{(s.units ?? []).map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                        </select></Field>
                        <Field label="To unit"><select className="input" value={conversion.to_unit_id} onChange={(e) => setConversion({ ...conversion, to_unit_id: e.target.value })} required>
                            <option value="">Select</option>{(s.units ?? []).map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                        </select></Field>
                        <Field label="Factor"><input className="input" type="number" step="0.00000001" value={conversion.factor} onChange={(e) => setConversion({ ...conversion, factor: e.target.value })} required /></Field>
                        <button className="btn btn--primary">Save conversion</button>
                    </div></form>
                </div>
                {(s.categories ?? []).length > 0 && <form onSubmit={saveCategoryEdit}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Category</th><th>Parent</th><th>Level</th><th>Status</th><th></th></tr></thead><tbody>
                    {(s.categories ?? []).map((c) => {
                        const editing = editingCategory?.id === c.id;
                        return <tr key={c.id}>
                            <td>{editing ? <input className="input" value={editingCategory.name} onChange={(e) => setEditingCategory({ ...editingCategory, name: e.target.value })} /> : c.name}</td>
                            <td>{editing ? <select className="input" value={editingCategory.parent_id ?? ''} onChange={(e) => setEditingCategory({ ...editingCategory, parent_id: e.target.value })}>
                                <option value="">Top level</option>
                                {(s.categories ?? []).filter((p) => p.id !== c.id).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select> : ((s.categories ?? []).find((p) => p.id === c.parent_id)?.name ?? 'Top level')}</td>
                            <td>{c.level ?? 0}</td>
                            <td>{editing ? <label className="check-inline"><input type="checkbox" checked={editingCategory.is_active !== false} onChange={(e) => setEditingCategory({ ...editingCategory, is_active: e.target.checked })} /> Active</label> : (c.is_active === false ? 'Inactive' : 'Active')}</td>
                            <td>{editing ? <><button className="btn btn--primary btn--sm">Save</button> <button type="button" className="btn btn--sm" onClick={() => setEditingCategory(null)}>Cancel</button></> : <button type="button" className="btn btn--sm" onClick={() => setEditingCategory({ id: c.id, name: c.name, parent_id: c.parent_id ?? '', is_active: c.is_active !== false })}>Edit</button>}</td>
                        </tr>;
                    })}
                </tbody></table></form>}
                {(s.brands ?? []).length > 0 && <form onSubmit={saveBrandEdit}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Brand</th><th>Status</th><th></th></tr></thead><tbody>
                    {(s.brands ?? []).map((b) => {
                        const editing = editingBrand?.id === b.id;
                        return <tr key={b.id}>
                            <td>{editing ? <input className="input" value={editingBrand.name} onChange={(e) => setEditingBrand({ ...editingBrand, name: e.target.value })} /> : b.name}</td>
                            <td>{editing ? <label className="check-inline"><input type="checkbox" checked={editingBrand.is_active !== false} onChange={(e) => setEditingBrand({ ...editingBrand, is_active: e.target.checked })} /> Active</label> : (b.is_active === false ? 'Inactive' : 'Active')}</td>
                            <td>{editing ? <><button className="btn btn--primary btn--sm">Save</button> <button type="button" className="btn btn--sm" onClick={() => setEditingBrand(null)}>Cancel</button></> : <button type="button" className="btn btn--sm" onClick={() => setEditingBrand({ id: b.id, name: b.name, is_active: b.is_active !== false })}>Edit</button>}</td>
                        </tr>;
                    })}
                </tbody></table></form>}
                {(s.unit_conversions ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>From</th><th>To</th><th>Factor</th><th>Item</th></tr></thead><tbody>
                    {s.unit_conversions.map((c) => <tr key={c.id}><td>{c.from_unit?.code ?? c.from_unit_id}</td><td>{c.to_unit?.code ?? c.to_unit_id}</td><td>{c.factor}</td><td>{c.item?.sku ?? 'Global'}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>Reorder Planning</h2>
                <form onSubmit={addReorderRule}>
                    <div className="fg2">
                        <Field label="Item">
                            <select className="input" value={reorderRule.item_id} onChange={(e) => setReorderRule({ ...reorderRule, item_id: e.target.value })} required>
                                <option value="">Select item</option>
                                {(s.items ?? []).map((item) => <option key={item.id} value={item.id}>{item.sku} · {item.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Warehouse">
                            <select className="input" value={reorderRule.warehouse_id} onChange={(e) => setReorderRule({ ...reorderRule, warehouse_id: e.target.value })} required>
                                <option value="">Select warehouse</option>
                                {(s.warehouses ?? []).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}
                            </select>
                        </Field>
                        <Field label="Reorder point"><input className="input" type="number" step="0.0001" min="0" value={reorderRule.reorder_point} onChange={(e) => setReorderRule({ ...reorderRule, reorder_point: e.target.value })} /></Field>
                        <Field label="Reorder quantity"><input className="input" type="number" step="0.0001" min="0" value={reorderRule.reorder_qty} onChange={(e) => setReorderRule({ ...reorderRule, reorder_qty: e.target.value })} /></Field>
                        <Field label="Minimum stock"><input className="input" type="number" step="0.0001" min="0" value={reorderRule.min_stock} onChange={(e) => setReorderRule({ ...reorderRule, min_stock: e.target.value })} /></Field>
                        <Field label="Maximum stock"><input className="input" type="number" step="0.0001" min="0" value={reorderRule.max_stock} onChange={(e) => setReorderRule({ ...reorderRule, max_stock: e.target.value })} /></Field>
                        <Field label="Safety stock"><input className="input" type="number" step="0.0001" min="0" value={reorderRule.safety_stock} onChange={(e) => setReorderRule({ ...reorderRule, safety_stock: e.target.value })} /></Field>
                        <Field label="Demand lookback"><input className="input" type="number" step="1" min="7" max="365" value={reorderRule.lookback_days} onChange={(e) => setReorderRule({ ...reorderRule, lookback_days: e.target.value })} /></Field>
                        <Field label="Lead time days"><input className="input" type="number" step="1" min="1" max="365" value={reorderRule.lead_time_days} onChange={(e) => setReorderRule({ ...reorderRule, lead_time_days: e.target.value })} /></Field>
                        <Field label="Service factor"><input className="input" type="number" step="0.01" min="0" max="3" value={reorderRule.service_factor} onChange={(e) => setReorderRule({ ...reorderRule, service_factor: e.target.value })} /></Field>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="btn" disabled={calculatingSafety} onClick={calculateSafetyStock}>{calculatingSafety ? 'Calculating...' : 'Calculate safety stock'}</button>
                        <button className="btn btn--primary">Save reorder rule</button>
                    </div>
                </form>
                {safetyResult && <div className="banner banner--info" style={{ marginTop: 12 }}>
                    Demand {safetyResult.total_demand} over {safetyResult.lookback_days} days · average {safetyResult.average_daily_demand}/day · lead-time demand {safetyResult.lead_time_demand} · available {safetyResult.available_qty}.
                </div>}
                {(s.warehouse_reorder_rules ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Item</th><th>Warehouse</th><th>Point</th><th>Qty</th><th>Min / Max</th><th>Safety</th></tr></thead><tbody>
                    {s.warehouse_reorder_rules.map((rule) => <tr key={rule.id}><td>{rule.item ? `${rule.item.sku} · ${rule.item.name}` : `#${rule.item_id}`}</td><td>{rule.warehouse ? `${rule.warehouse.code} · ${rule.warehouse.name}` : `#${rule.warehouse_id}`}</td><td>{rule.reorder_point ?? '—'}</td><td>{rule.reorder_qty ?? '—'}</td><td>{rule.min_stock ?? '—'} / {rule.max_stock ?? '—'}</td><td>{rule.safety_stock ?? '—'}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>Adjustment Reasons</h2>
                <form className="fg2" onSubmit={addReasonCode}>
                    <Field label="Code"><input className="input" value={reasonCode.code} onChange={(e) => setReasonCode({ ...reasonCode, code: e.target.value })} placeholder="damage" required /></Field>
                    <Field label="Label"><input className="input" value={reasonCode.label} onChange={(e) => setReasonCode({ ...reasonCode, label: e.target.value })} placeholder="Damaged stock" /></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">Save reason</button></div>
                </form>
                {(s.settings?.adjustment_reason_codes ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Code</th><th>Label</th><th>Status</th></tr></thead><tbody>
                    {(s.settings.adjustment_reason_codes ?? []).map((code) => <tr key={code.code}><td>{code.code}</td><td>{code.label ?? code.code}</td><td>{code.active === false ? 'Inactive' : 'Active'}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>SolaBooks Integration</h2>
                <dl className="kv">
                    <dt>Status</dt><dd>{intg.connected ? 'Connected' : 'Not connected'}</dd>
                    <dt>Delivery</dt><dd>{intg.delivery_configured ? 'Configured' : 'Awaiting credentials'}</dd>
                    <dt>Inventory asset</dt><dd>{intg.account_mappings?.inventory_asset ?? '— (unmapped)'}</dd>
                    <dt>COGS</dt><dd>{intg.account_mappings?.cogs ?? '— (unmapped)'}</dd>
                    <dt>GRNI / accrual</dt><dd>{intg.account_mappings?.grni_accrual ?? '— (unmapped)'}</dd>
                </dl>
                <p className="muted">Planned events: {(intg.planned_events ?? []).join(', ')}</p>
                <p className="muted">Posting outbox: authenticated, idempotent delivery with retries and reconciliation is implemented; production activation requires approved credentials.</p>
            </div>
        </section>
    );
}
