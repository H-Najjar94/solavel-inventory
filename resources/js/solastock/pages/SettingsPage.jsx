import React, { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { Field, Skeleton, fieldErrors } from '../components/ui.jsx';

export default function SettingsPage() {
    const { data, isLoading, isMock } = useApiQuery(['settings'], api.settings, { fallback: { settings: null, units: [], categories: [], brands: [], items: [], warehouses: [], warehouse_reorder_rules: [] } });
    const integration = useApiQuery(['integration'], api.integrationStatus, { fallback: { connected: false, planned_events: [], account_mappings: {} } });
    const rolesQuery = useApiQuery(['custom-roles'], api.customRoles, { fallback: { permissions: [], roles: [], assignments: [], builtin_roles: [] } });
    const warehouseAssignmentsQuery = useApiQuery(['warehouse-assignments'], api.allWarehouseAssignments, { fallback: [] });
    const qc = useQueryClient();
    const toast = useToast();
    const s = data ?? {};
    const intg = integration.data ?? {};
    const [policy, setPolicy] = useState({ default_costing_method: 'average', allow_negative_stock: false, picking_policy: 'manual', value_tolerance: '0.0100', expiry_warning_days: 30 });
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
    const [currencyRate, setCurrencyRate] = useState({ currency_code: '', rate_to_base: '', effective_date: new Date().toISOString().slice(0, 10) });
    const [customRole, setCustomRole] = useState({ name: '', key: '', permissions: [] });
    const [roleAssignment, setRoleAssignment] = useState({ user_id: '', role_id: '' });
    const [warehouseAssignment, setWarehouseAssignment] = useState({ user_id: '', warehouse_ids: [] });
    const [taxes, setTaxes] = useState([]);
    const [defaultTaxes, setDefaultTaxes] = useState({ purchase: '', sales: '' });
    const [taxDraft, setTaxDraft] = useState({ code: '', name: '', rate: '', treatment: 'standard', active: true, purchase: true, sales: true });
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
                value_tolerance: s.settings.value_tolerance ?? '0.0100',
                expiry_warning_days: s.settings.expiry_warning_days ?? 30,
            });
            setTaxes(Array.isArray(s.settings.taxes) ? s.settings.taxes : []);
            setDefaultTaxes({ purchase: s.settings.default_purchase_tax_code ?? '', sales: s.settings.default_sales_tax_code ?? '' });
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

    async function saveWarehouseAssignment(e) {
        e.preventDefault();
        if (!warehouseAssignment.user_id) return;
        try {
            await api.syncWarehouseAssignments(Number(warehouseAssignment.user_id), warehouseAssignment.warehouse_ids.map(Number));
            await qc.invalidateQueries({ queryKey: ['warehouse-assignments'] });
            toast.push('Warehouse scope saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function saveTaxes(e) {
        e.preventDefault();
        try { await api.updateTaxes(taxes, defaultTaxes); await qc.invalidateQueries({ queryKey: ['settings'] }); toast.push('Tax settings saved.', 'success'); }
        catch (err) { toast.push(err.message, 'error'); }
    }

    function addTax(e) {
        e.preventDefault();
        const code = taxDraft.code.trim().toUpperCase();
        if (!code || !taxDraft.name.trim()) return;
        if (taxes.some((tax) => String(tax.code).trim().toUpperCase() === code)) {
            toast.push('Tax codes must be unique.', 'error');
            return;
        }
        setTaxes([...taxes, { ...taxDraft, code, name: taxDraft.name.trim(), rate: Number(taxDraft.rate || 0) }]);
        setTaxDraft({ code: '', name: '', rate: '', treatment: 'standard', active: true, purchase: true, sales: true });
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

    async function addCurrencyRate(e) {
        e.preventDefault();
        try {
            await api.createCurrencyRate({ ...currencyRate, currency_code: currencyRate.currency_code.toUpperCase() });
            setCurrencyRate({ currency_code: '', rate_to_base: '', effective_date: new Date().toISOString().slice(0, 10) });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            toast.push('Currency rate saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function addCustomRole(e) {
        e.preventDefault();
        try {
            await api.createCustomRole({ ...customRole, is_active: true });
            setCustomRole({ name: '', key: '', permissions: [] });
            await qc.invalidateQueries({ queryKey: ['custom-roles'] });
            toast.push('Custom role saved.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    function togglePermission(key) {
        setCustomRole((role) => ({
            ...role,
            permissions: role.permissions.includes(key)
                ? role.permissions.filter((p) => p !== key)
                : [...role.permissions, key],
        }));
    }

    async function assignRole(e) {
        e.preventDefault();
        try {
            await api.assignCustomRole({ user_id: Number(roleAssignment.user_id), role_id: Number(roleAssignment.role_id) });
            setRoleAssignment({ user_id: '', role_id: '' });
            await qc.invalidateQueries({ queryKey: ['custom-roles'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Role assigned.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    async function unassignRole(userId) {
        try {
            await api.unassignCustomRole(userId);
            await qc.invalidateQueries({ queryKey: ['custom-roles'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push('Role assignment removed.', 'success');
        } catch (err) { toast.push(err.message, 'error'); }
    }

    if (isLoading || !s.settings) return <section className="page"><Skeleton /></section>;

    return (
        <section className="page">
            <header className="page-head"><h1>Settings</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>

            <div className="panel">
                <h2>Inventory Policy</h2>
                <div className="fg2">
                    <Field label="Default costing method" error={errors.default_costing_method}>
                        <select className="input" value={policy.default_costing_method} onChange={(e) => setPolicy((current) => ({ ...current, default_costing_method: e.target.value }))}>
                            <option value="average">Weighted average</option>
                            <option value="fifo">FIFO</option>
                            <option value="standard">Standard</option>
                        </select>
                    </Field>
                    <Field label="Picking policy" error={errors.picking_policy}>
                        <select className="input" value={policy.picking_policy} onChange={(e) => setPolicy((current) => ({ ...current, picking_policy: e.target.value }))}>
                            <option value="manual">Manual</option>
                            <option value="fifo">FIFO</option>
                            <option value="fefo">FEFO</option>
                        </select>
                    </Field>
                    <Field label="Negative stock">
                        <label className="check-inline"><input type="checkbox" checked={policy.allow_negative_stock} onChange={(e) => setPolicy((current) => ({ ...current, allow_negative_stock: e.target.checked }))} /> Allow when policy permits it</label>
                    </Field>
                    <Field label="Value reconciliation tolerance">
                        <input className="input" type="number" min="0" step="0.0001" value={policy.value_tolerance} onChange={(e) => setPolicy((current) => ({ ...current, value_tolerance: e.target.value }))} />
                    </Field>
                    <Field label="Expiry warning days">
                        <input className="input" type="number" min="0" max="3650" value={policy.expiry_warning_days} onChange={(e) => setPolicy((current) => ({ ...current, expiry_warning_days: Number(e.target.value) }))} />
                    </Field>
                </div>
                <button className="btn btn--primary" disabled={saving} onClick={savePolicy}>{saving ? 'Saving…' : 'Save policy'}</button>
            </div>

            <div className="panel">
                <h2>Tax administration</h2>
                <p className="muted">Organization-scoped tax treatments used by purchase and sales documents.</p>
                <div className="fg2">
                    <Field label="Default purchase tax"><select className="input" value={defaultTaxes.purchase} onChange={(e) => setDefaultTaxes({ ...defaultTaxes, purchase: e.target.value })}><option value="">No default</option>{taxes.filter((tax) => tax.active && tax.purchase).map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.name}</option>)}</select></Field>
                    <Field label="Default sales tax"><select className="input" value={defaultTaxes.sales} onChange={(e) => setDefaultTaxes({ ...defaultTaxes, sales: e.target.value })}><option value="">No default</option>{taxes.filter((tax) => tax.active && tax.sales).map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.name}</option>)}</select></Field>
                </div>
                <form className="fg2" onSubmit={addTax}>
                    <Field label="Code"><input className="input" value={taxDraft.code} onChange={(e) => setTaxDraft({ ...taxDraft, code: e.target.value })} /></Field>
                    <Field label="Name"><input className="input" value={taxDraft.name} onChange={(e) => setTaxDraft({ ...taxDraft, name: e.target.value })} /></Field>
                    <Field label="Rate"><input className="input" type="number" step="0.0001" min="0" max="100" value={taxDraft.rate} onChange={(e) => setTaxDraft({ ...taxDraft, rate: e.target.value })} /></Field>
                    <Field label="Treatment"><select className="input" value={taxDraft.treatment} onChange={(e) => setTaxDraft({ ...taxDraft, treatment: e.target.value })}><option value="standard">Standard</option><option value="zero">Zero</option><option value="exempt">Exempt</option></select></Field>
                    <label className="check-inline"><input type="checkbox" checked={taxDraft.active} onChange={(e) => setTaxDraft({ ...taxDraft, active: e.target.checked })} /> Active</label>
                    <button className="btn btn--sm">Add tax</button>
                </form>
                <form onSubmit={saveTaxes}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Code</th><th>Name</th><th>Rate</th><th>Treatment</th><th>Active</th><th>Use</th><th></th></tr></thead><tbody>{taxes.map((tax, i) => <tr key={`${tax.code}-${i}`}>
                    <td>{tax.code}</td>
                    <td><input className="input" aria-label={`Tax name ${tax.code}`} value={tax.name} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, name: e.target.value } : row))} /></td>
                    <td><input className="input" aria-label={`Tax rate ${tax.code}`} type="number" min="0" max="100" step="0.0001" value={tax.rate} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, rate: Number(e.target.value) } : row))} /></td>
                    <td><select className="input" aria-label={`Tax treatment ${tax.code}`} value={tax.treatment} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, treatment: e.target.value } : row))}><option value="standard">Standard</option><option value="zero">Zero</option><option value="exempt">Exempt</option></select></td>
                    <td><input aria-label={`Tax active ${tax.code}`} type="checkbox" checked={!!tax.active} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, active: e.target.checked } : row))} /></td>
                    <td><label className="check-inline"><input aria-label={`Tax purchase ${tax.code}`} type="checkbox" checked={!!tax.purchase} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, purchase: e.target.checked } : row))} /> Purchase</label><label className="check-inline"><input aria-label={`Tax sales ${tax.code}`} type="checkbox" checked={!!tax.sales} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, sales: e.target.checked } : row))} /> Sales</label></td>
                    <td><button type="button" className="btn btn--sm" onClick={() => setTaxes(taxes.filter((_, j) => j !== i))}>Remove</button></td>
                </tr>)}</tbody></table><button className="btn btn--primary" style={{ marginTop: 12 }}>Save tax settings</button></form>
            </div>

            <div className="panel">
                <h2>Warehouse user scope</h2>
                <p className="muted">Assign an operational user to one or more warehouses. Owners remain unrestricted.</p>
                <form className="fg2" onSubmit={saveWarehouseAssignment}>
                    <Field label="Central user ID"><input className="input" type="number" min="1" value={warehouseAssignment.user_id} onChange={(e) => setWarehouseAssignment({ ...warehouseAssignment, user_id: e.target.value })} placeholder="e.g. 91" /></Field>
                    <div><span className="field-label">Allowed warehouses</span>{(s.warehouses ?? []).map((warehouse) => <label className="check-inline" key={warehouse.id}><input type="checkbox" checked={warehouseAssignment.warehouse_ids.includes(Number(warehouse.id))} onChange={(e) => setWarehouseAssignment({ ...warehouseAssignment, warehouse_ids: e.target.checked ? [...warehouseAssignment.warehouse_ids, Number(warehouse.id)] : warehouseAssignment.warehouse_ids.filter((id) => id !== Number(warehouse.id)) })} /> {warehouse.code} · {warehouse.name}</label>)}</div>
                    <button className="btn btn--primary">Save warehouse scope</button>
                </form>
                {(warehouseAssignmentsQuery.data?.data ?? warehouseAssignmentsQuery.data ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>User</th><th>Warehouse</th></tr></thead><tbody>{(warehouseAssignmentsQuery.data?.data ?? warehouseAssignmentsQuery.data ?? []).map((row) => <tr key={row.id}><td>{row.user_id}</td><td>{row.warehouse_id}</td></tr>)}</tbody></table>}
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
                <h2>Currency Rates</h2>
                <form className="fg2" onSubmit={addCurrencyRate}>
                    <Field label="Currency"><input className="input" value={currencyRate.currency_code} onChange={(e) => setCurrencyRate({ ...currencyRate, currency_code: e.target.value.toUpperCase().slice(0, 3) })} placeholder="USD" required /></Field>
                    <Field label="Rate to base"><input className="input" type="number" step="0.00000001" min="0" value={currencyRate.rate_to_base} onChange={(e) => setCurrencyRate({ ...currencyRate, rate_to_base: e.target.value })} required /></Field>
                    <Field label="Effective date"><input className="input" type="date" value={currencyRate.effective_date} onChange={(e) => setCurrencyRate({ ...currencyRate, effective_date: e.target.value })} required /></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">Save rate</button></div>
                </form>
                {(s.currency_rates ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Currency</th><th>Rate to base</th><th>Effective</th></tr></thead><tbody>
                    {(s.currency_rates ?? []).map((r) => <tr key={r.id}><td>{r.currency_code}</td><td>{r.rate_to_base}</td><td>{r.effective_date}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>Custom Roles</h2>
                <form onSubmit={addCustomRole}>
                    <div className="fg2">
                        <Field label="Role name"><input className="input" value={customRole.name} onChange={(e) => setCustomRole({ ...customRole, name: e.target.value })} required /></Field>
                        <Field label="Role key"><input className="input" value={customRole.key} onChange={(e) => setCustomRole({ ...customRole, key: e.target.value })} placeholder="warehouse_supervisor" /></Field>
                    </div>
                    <div className="overview-grid" style={{ marginTop: 10 }}>
                        {(rolesQuery.data?.permissions ?? []).map((p) => <label key={p.key} className="card" style={{ padding: 12 }}>
                            <input type="checkbox" checked={customRole.permissions.includes(p.key)} onChange={() => togglePermission(p.key)} /> <strong>{p.key}</strong><br /><span className="muted">{p.label}</span>
                        </label>)}
                    </div>
                    <button className="btn btn--primary" disabled={customRole.permissions.length === 0}>Save role</button>
                </form>
                {(rolesQuery.data?.roles ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>Role</th><th>Key</th><th>Permissions</th><th>Assignments</th><th>Status</th></tr></thead><tbody>
                    {(rolesQuery.data?.roles ?? []).map((r) => <tr key={r.id}><td>{r.name}</td><td>{r.key}</td><td>{(r.permissions ?? []).length}</td><td>{r.assignments_count ?? 0}</td><td>{r.is_active ? 'Active' : 'Inactive'}</td></tr>)}
                </tbody></table>}
                <form className="fg2" onSubmit={assignRole} style={{ marginTop: 12 }}>
                    <Field label="Central user ID"><input className="input" type="number" min="1" value={roleAssignment.user_id} onChange={(e) => setRoleAssignment({ ...roleAssignment, user_id: e.target.value })} required /></Field>
                    <Field label="Role"><select className="input" value={roleAssignment.role_id} onChange={(e) => setRoleAssignment({ ...roleAssignment, role_id: e.target.value })} required><option value="">Select role</option>{(rolesQuery.data?.roles ?? []).filter((r) => r.is_active).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">Assign role</button></div>
                </form>
                {(rolesQuery.data?.assignments ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>User ID</th><th>Role</th><th></th></tr></thead><tbody>
                    {(rolesQuery.data?.assignments ?? []).map((a) => <tr key={a.id}><td>{a.user_id}</td><td>{a.role?.name ?? `#${a.role_id}`}</td><td><button className="btn btn--sm btn--danger" onClick={() => unassignRole(a.user_id)}>Remove</button></td></tr>)}
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
