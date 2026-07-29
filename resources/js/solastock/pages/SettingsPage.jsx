import React, { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { Field, Skeleton, fieldErrors } from '../components/ui.jsx';
import { useSettingsTranslation } from '../i18n/useSettingsTranslation.js';

export default function SettingsPage() {
    const tr = useSettingsTranslation();
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
            toast.push(tr('settings.policy.saved'), 'success');
        } catch (e) {
            setErrors(fieldErrors(e));
            toast.push(e.message || tr('settings.policy.saveError'), 'error');
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
            toast.push(tr('settings.warehouseScope.saved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function saveTaxes(e) {
        e.preventDefault();
        try { await api.updateTaxes(taxes, defaultTaxes); await qc.invalidateQueries({ queryKey: ['settings'] }); toast.push(tr('settings.taxes.saved'), 'success'); }
        catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    function addTax(e) {
        e.preventDefault();
        const code = taxDraft.code.trim().toUpperCase();
        if (!code || !taxDraft.name.trim()) return;
        if (taxes.some((tax) => String(tax.code).trim().toUpperCase() === code)) {
            toast.push(tr('settings.taxes.unique'), 'error');
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
            toast.push(tr('settings.master.conversionSaved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function addCategory(e) {
        e.preventDefault();
        try {
            await api.createCategory(category.name, category.parent_id ? Number(category.parent_id) : null);
            setCategory({ name: '', parent_id: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push(tr('settings.master.categorySaved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
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
            toast.push(tr('settings.master.categoryUpdated'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function addBrand(e) {
        e.preventDefault();
        try {
            await api.createBrand(brand.name);
            setBrand({ name: '' });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push(tr('settings.master.brandSaved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
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
            toast.push(tr('settings.master.brandUpdated'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
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
            toast.push(tr('settings.reorder.saved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function calculateSafetyStock() {
        if (!reorderRule.item_id || !reorderRule.warehouse_id) {
            toast.push(tr('settings.reorder.selectFirst'), 'error');
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
            toast.push(tr('settings.reorder.calculated'), 'success');
        } catch (err) {
            toast.push(err.message || tr('settings.common.errorFallback'), 'error');
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
            toast.push(tr('settings.reasons.saved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function addCurrencyRate(e) {
        e.preventDefault();
        try {
            await api.createCurrencyRate({ ...currencyRate, currency_code: currencyRate.currency_code.toUpperCase() });
            setCurrencyRate({ currency_code: '', rate_to_base: '', effective_date: new Date().toISOString().slice(0, 10) });
            await qc.invalidateQueries({ queryKey: ['settings'] });
            toast.push(tr('settings.currency.saved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function addCustomRole(e) {
        e.preventDefault();
        try {
            await api.createCustomRole({ ...customRole, is_active: true });
            setCustomRole({ name: '', key: '', permissions: [] });
            await qc.invalidateQueries({ queryKey: ['custom-roles'] });
            toast.push(tr('settings.roles.saved'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
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
            toast.push(tr('settings.roles.assigned'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    async function unassignRole(userId) {
        try {
            await api.unassignCustomRole(userId);
            await qc.invalidateQueries({ queryKey: ['custom-roles'] });
            await qc.invalidateQueries({ queryKey: ['meta'] });
            toast.push(tr('settings.roles.removed'), 'success');
        } catch (err) { toast.push(err.message || tr('settings.common.errorFallback'), 'error'); }
    }

    if (isLoading || !s.settings) return <section className="page"><Skeleton /></section>;

    return (
        <section className="page">
            <header className="page-head"><h1>{tr('settings.title')}</h1>{isMock && <span className="badge badge--warn">{tr('settings.sampleData')}</span>}</header>

            <div className="panel">
                <h2>{tr('settings.policy.title')}</h2>
                <div className="fg2">
                    <Field label={tr('settings.policy.defaultCosting')} error={errors.default_costing_method}>
                        <select className="input" value={policy.default_costing_method} onChange={(e) => setPolicy((current) => ({ ...current, default_costing_method: e.target.value }))}>
                            <option value="average">{tr('settings.policy.weightedAverage')}</option>
                            <option value="fifo">{tr('settings.policy.fifo')}</option>
                            <option value="standard">{tr('settings.policy.standard')}</option>
                        </select>
                    </Field>
                    <Field label={tr('settings.policy.pickingPolicy')} error={errors.picking_policy}>
                        <select className="input" value={policy.picking_policy} onChange={(e) => setPolicy((current) => ({ ...current, picking_policy: e.target.value }))}>
                            <option value="manual">{tr('settings.policy.manual')}</option>
                            <option value="fifo">{tr('settings.policy.fifo')}</option>
                            <option value="fefo">{tr('settings.policy.fefo')}</option>
                        </select>
                    </Field>
                    <Field label={tr('settings.policy.negativeStock')}>
                        <label className="check-inline"><input type="checkbox" checked={policy.allow_negative_stock} onChange={(e) => setPolicy((current) => ({ ...current, allow_negative_stock: e.target.checked }))} /> {tr('settings.policy.allowNegative')}</label>
                    </Field>
                    <Field label={tr('settings.policy.valueTolerance')}>
                        <input className="input" type="number" min="0" step="0.0001" value={policy.value_tolerance} onChange={(e) => setPolicy((current) => ({ ...current, value_tolerance: e.target.value }))} />
                    </Field>
                    <Field label={tr('settings.policy.expiryWarningDays')}>
                        <input className="input" type="number" min="0" max="3650" value={policy.expiry_warning_days} onChange={(e) => setPolicy((current) => ({ ...current, expiry_warning_days: Number(e.target.value) }))} />
                    </Field>
                </div>
                <button className="btn btn--primary" disabled={saving} onClick={savePolicy}>{saving ? tr('settings.policy.saving') : tr('settings.policy.save')}</button>
            </div>

            <div className="panel">
                <h2>{tr('settings.taxes.title')}</h2>
                <p className="muted">{tr('settings.taxes.description')}</p>
                <div className="fg2">
                    <Field label={tr('settings.taxes.defaultPurchase')}><select className="input" value={defaultTaxes.purchase} onChange={(e) => setDefaultTaxes({ ...defaultTaxes, purchase: e.target.value })}><option value="">{tr('settings.taxes.noDefault')}</option>{taxes.filter((tax) => tax.active && tax.purchase).map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.name}</option>)}</select></Field>
                    <Field label={tr('settings.taxes.defaultSales')}><select className="input" value={defaultTaxes.sales} onChange={(e) => setDefaultTaxes({ ...defaultTaxes, sales: e.target.value })}><option value="">{tr('settings.taxes.noDefault')}</option>{taxes.filter((tax) => tax.active && tax.sales).map((tax) => <option key={tax.code} value={tax.code}>{tax.code} · {tax.name}</option>)}</select></Field>
                </div>
                <form className="fg2" onSubmit={addTax}>
                    <Field label={tr('settings.common.code')}><input className="input" value={taxDraft.code} onChange={(e) => setTaxDraft({ ...taxDraft, code: e.target.value })} /></Field>
                    <Field label={tr('settings.common.name')}><input className="input" value={taxDraft.name} onChange={(e) => setTaxDraft({ ...taxDraft, name: e.target.value })} /></Field>
                    <Field label={tr('settings.taxes.rate')}><input className="input" type="number" step="0.0001" min="0" max="100" value={taxDraft.rate} onChange={(e) => setTaxDraft({ ...taxDraft, rate: e.target.value })} /></Field>
                    <Field label={tr('settings.taxes.treatment')}><select className="input" value={taxDraft.treatment} onChange={(e) => setTaxDraft({ ...taxDraft, treatment: e.target.value })}><option value="standard">{tr('settings.policy.standard')}</option><option value="zero">{tr('settings.taxes.zero')}</option><option value="exempt">{tr('settings.taxes.exempt')}</option></select></Field>
                    <label className="check-inline"><input type="checkbox" checked={taxDraft.active} onChange={(e) => setTaxDraft({ ...taxDraft, active: e.target.checked })} /> {tr('settings.common.active')}</label>
                    <button className="btn btn--sm">{tr('settings.taxes.add')}</button>
                </form>
                <form onSubmit={saveTaxes}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.common.code')}</th><th>{tr('settings.common.name')}</th><th>{tr('settings.taxes.rate')}</th><th>{tr('settings.taxes.treatment')}</th><th>{tr('settings.common.active')}</th><th>{tr('settings.taxes.use')}</th><th></th></tr></thead><tbody>{taxes.map((tax, i) => <tr key={`${tax.code}-${i}`}>
                    <td>{tax.code}</td>
                    <td><input className="input" aria-label={tr('settings.taxes.nameAria', { code: tax.code })} value={tax.name} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, name: e.target.value } : row))} /></td>
                    <td><input className="input" aria-label={tr('settings.taxes.rateAria', { code: tax.code })} type="number" min="0" max="100" step="0.0001" value={tax.rate} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, rate: Number(e.target.value) } : row))} /></td>
                    <td><select className="input" aria-label={tr('settings.taxes.treatmentAria', { code: tax.code })} value={tax.treatment} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, treatment: e.target.value } : row))}><option value="standard">{tr('settings.policy.standard')}</option><option value="zero">{tr('settings.taxes.zero')}</option><option value="exempt">{tr('settings.taxes.exempt')}</option></select></td>
                    <td><input aria-label={tr('settings.taxes.activeAria', { code: tax.code })} type="checkbox" checked={!!tax.active} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, active: e.target.checked } : row))} /></td>
                    <td><label className="check-inline"><input aria-label={tr('settings.taxes.purchaseAria', { code: tax.code })} type="checkbox" checked={!!tax.purchase} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, purchase: e.target.checked } : row))} /> {tr('settings.taxes.purchase')}</label><label className="check-inline"><input aria-label={tr('settings.taxes.salesAria', { code: tax.code })} type="checkbox" checked={!!tax.sales} onChange={(e) => setTaxes(taxes.map((row, j) => j === i ? { ...row, sales: e.target.checked } : row))} /> {tr('settings.taxes.sales')}</label></td>
                    <td><button type="button" className="btn btn--sm" onClick={() => setTaxes(taxes.filter((_, j) => j !== i))}>{tr('settings.common.remove')}</button></td>
                </tr>)}</tbody></table><button className="btn btn--primary" style={{ marginTop: 12 }}>{tr('settings.taxes.save')}</button></form>
            </div>

            <div className="panel">
                <h2>{tr('settings.warehouseScope.title')}</h2>
                <p className="muted">{tr('settings.warehouseScope.description')}</p>
                <form className="fg2" onSubmit={saveWarehouseAssignment}>
                    <Field label={tr('settings.warehouseScope.userId')}><input className="input" type="number" min="1" value={warehouseAssignment.user_id} onChange={(e) => setWarehouseAssignment({ ...warehouseAssignment, user_id: e.target.value })} placeholder={tr('settings.warehouseScope.userIdExample')} /></Field>
                    <div><span className="field-label">{tr('settings.warehouseScope.allowed')}</span>{(s.warehouses ?? []).map((warehouse) => <label className="check-inline" key={warehouse.id}><input type="checkbox" checked={warehouseAssignment.warehouse_ids.includes(Number(warehouse.id))} onChange={(e) => setWarehouseAssignment({ ...warehouseAssignment, warehouse_ids: e.target.checked ? [...warehouseAssignment.warehouse_ids, Number(warehouse.id)] : warehouseAssignment.warehouse_ids.filter((id) => id !== Number(warehouse.id)) })} /> {warehouse.code} · {warehouse.name}</label>)}</div>
                    <button className="btn btn--primary">{tr('settings.warehouseScope.save')}</button>
                </form>
                {(warehouseAssignmentsQuery.data?.data ?? warehouseAssignmentsQuery.data ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.common.user')}</th><th>{tr('settings.common.warehouse')}</th></tr></thead><tbody>{(warehouseAssignmentsQuery.data?.data ?? warehouseAssignmentsQuery.data ?? []).map((row) => <tr key={row.id}><td>{row.user_id}</td><td>{row.warehouse_id}</td></tr>)}</tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.master.title')}</h2>
                <p className="muted">{tr('settings.master.summary', { units: (s.units ?? []).length, categories: (s.categories ?? []).length, brands: (s.brands ?? []).length })}</p>
                <div className="overview-grid">
                    <form className="card" onSubmit={addCategory}><div className="card-head"><h3>{tr('settings.master.categoryHierarchy')}</h3></div><div className="card-body">
                        <Field label={tr('settings.common.name')}><input className="input" value={category.name} onChange={(e) => setCategory({ ...category, name: e.target.value })} required /></Field>
                        <Field label={tr('settings.master.parent')}>
                            <select className="input" value={category.parent_id} onChange={(e) => setCategory({ ...category, parent_id: e.target.value })}>
                                <option value="">{tr('settings.master.topLevel')}</option>
                                {(s.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </Field>
                        <button className="btn btn--primary">{tr('settings.master.addCategory')}</button>
                    </div></form>
                    <form className="card" onSubmit={addBrand}><div className="card-head"><h3>{tr('settings.master.brand')}</h3></div><div className="card-body">
                        <Field label={tr('settings.common.name')}><input className="input" value={brand.name} onChange={(e) => setBrand({ name: e.target.value })} required /></Field>
                        <button className="btn btn--primary">{tr('settings.master.addBrand')}</button>
                    </div></form>
                    <form className="card" onSubmit={addConversion}><div className="card-head"><h3>{tr('settings.master.unitConversion')}</h3></div><div className="card-body">
                        <Field label={tr('settings.master.fromUnit')}><select className="input" value={conversion.from_unit_id} onChange={(e) => setConversion({ ...conversion, from_unit_id: e.target.value })} required>
                            <option value="">{tr('settings.common.select')}</option>{(s.units ?? []).map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                        </select></Field>
                        <Field label={tr('settings.master.toUnit')}><select className="input" value={conversion.to_unit_id} onChange={(e) => setConversion({ ...conversion, to_unit_id: e.target.value })} required>
                            <option value="">{tr('settings.common.select')}</option>{(s.units ?? []).map((u) => <option key={u.id} value={u.id}>{u.code} · {u.name}</option>)}
                        </select></Field>
                        <Field label={tr('settings.master.factor')}><input className="input" type="number" step="0.00000001" value={conversion.factor} onChange={(e) => setConversion({ ...conversion, factor: e.target.value })} required /></Field>
                        <button className="btn btn--primary">{tr('settings.master.saveConversion')}</button>
                    </div></form>
                </div>
                {(s.categories ?? []).length > 0 && <form onSubmit={saveCategoryEdit}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.master.category')}</th><th>{tr('settings.master.parent')}</th><th>{tr('settings.master.level')}</th><th>{tr('settings.common.status')}</th><th></th></tr></thead><tbody>
                    {(s.categories ?? []).map((c) => {
                        const editing = editingCategory?.id === c.id;
                        return <tr key={c.id}>
                            <td>{editing ? <input className="input" value={editingCategory.name} onChange={(e) => setEditingCategory({ ...editingCategory, name: e.target.value })} /> : c.name}</td>
                            <td>{editing ? <select className="input" value={editingCategory.parent_id ?? ''} onChange={(e) => setEditingCategory({ ...editingCategory, parent_id: e.target.value })}>
                                <option value="">{tr('settings.master.topLevel')}</option>
                                {(s.categories ?? []).filter((p) => p.id !== c.id).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select> : ((s.categories ?? []).find((p) => p.id === c.parent_id)?.name ?? tr('settings.master.topLevel'))}</td>
                            <td>{c.level ?? 0}</td>
                            <td>{editing ? <label className="check-inline"><input type="checkbox" checked={editingCategory.is_active !== false} onChange={(e) => setEditingCategory({ ...editingCategory, is_active: e.target.checked })} /> {tr('settings.common.active')}</label> : tr(c.is_active === false ? 'settings.common.inactive' : 'settings.common.active')}</td>
                            <td>{editing ? <><button className="btn btn--primary btn--sm">{tr('settings.common.save')}</button> <button type="button" className="btn btn--sm" onClick={() => setEditingCategory(null)}>{tr('settings.common.cancel')}</button></> : <button type="button" className="btn btn--sm" onClick={() => setEditingCategory({ id: c.id, name: c.name, parent_id: c.parent_id ?? '', is_active: c.is_active !== false })}>{tr('settings.common.edit')}</button>}</td>
                        </tr>;
                    })}
                </tbody></table></form>}
                {(s.brands ?? []).length > 0 && <form onSubmit={saveBrandEdit}><table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.master.brand')}</th><th>{tr('settings.common.status')}</th><th></th></tr></thead><tbody>
                    {(s.brands ?? []).map((b) => {
                        const editing = editingBrand?.id === b.id;
                        return <tr key={b.id}>
                            <td>{editing ? <input className="input" value={editingBrand.name} onChange={(e) => setEditingBrand({ ...editingBrand, name: e.target.value })} /> : b.name}</td>
                            <td>{editing ? <label className="check-inline"><input type="checkbox" checked={editingBrand.is_active !== false} onChange={(e) => setEditingBrand({ ...editingBrand, is_active: e.target.checked })} /> {tr('settings.common.active')}</label> : tr(b.is_active === false ? 'settings.common.inactive' : 'settings.common.active')}</td>
                            <td>{editing ? <><button className="btn btn--primary btn--sm">{tr('settings.common.save')}</button> <button type="button" className="btn btn--sm" onClick={() => setEditingBrand(null)}>{tr('settings.common.cancel')}</button></> : <button type="button" className="btn btn--sm" onClick={() => setEditingBrand({ id: b.id, name: b.name, is_active: b.is_active !== false })}>{tr('settings.common.edit')}</button>}</td>
                        </tr>;
                    })}
                </tbody></table></form>}
                {(s.unit_conversions ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.master.from')}</th><th>{tr('settings.master.to')}</th><th>{tr('settings.master.factor')}</th><th>{tr('settings.common.item')}</th></tr></thead><tbody>
                    {s.unit_conversions.map((c) => <tr key={c.id}><td>{c.from_unit?.code ?? c.from_unit_id}</td><td>{c.to_unit?.code ?? c.to_unit_id}</td><td>{c.factor}</td><td>{c.item?.sku ?? tr('settings.common.global')}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.reorder.title')}</h2>
                <form onSubmit={addReorderRule}>
                    <div className="fg2">
                        <Field label={tr('settings.common.item')}>
                            <select className="input" value={reorderRule.item_id} onChange={(e) => setReorderRule({ ...reorderRule, item_id: e.target.value })} required>
                                <option value="">{tr('settings.reorder.selectItem')}</option>
                                {(s.items ?? []).map((item) => <option key={item.id} value={item.id}>{item.sku} · {item.name}</option>)}
                            </select>
                        </Field>
                        <Field label={tr('settings.common.warehouse')}>
                            <select className="input" value={reorderRule.warehouse_id} onChange={(e) => setReorderRule({ ...reorderRule, warehouse_id: e.target.value })} required>
                                <option value="">{tr('settings.reorder.selectWarehouse')}</option>
                                {(s.warehouses ?? []).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}
                            </select>
                        </Field>
                        <Field label={tr('settings.reorder.point')}><input className="input" type="number" step="0.0001" min="0" value={reorderRule.reorder_point} onChange={(e) => setReorderRule({ ...reorderRule, reorder_point: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.quantity')}><input className="input" type="number" step="0.0001" min="0" value={reorderRule.reorder_qty} onChange={(e) => setReorderRule({ ...reorderRule, reorder_qty: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.minimum')}><input className="input" type="number" step="0.0001" min="0" value={reorderRule.min_stock} onChange={(e) => setReorderRule({ ...reorderRule, min_stock: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.maximum')}><input className="input" type="number" step="0.0001" min="0" value={reorderRule.max_stock} onChange={(e) => setReorderRule({ ...reorderRule, max_stock: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.safety')}><input className="input" type="number" step="0.0001" min="0" value={reorderRule.safety_stock} onChange={(e) => setReorderRule({ ...reorderRule, safety_stock: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.lookback')}><input className="input" type="number" step="1" min="7" max="365" value={reorderRule.lookback_days} onChange={(e) => setReorderRule({ ...reorderRule, lookback_days: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.leadTime')}><input className="input" type="number" step="1" min="1" max="365" value={reorderRule.lead_time_days} onChange={(e) => setReorderRule({ ...reorderRule, lead_time_days: e.target.value })} /></Field>
                        <Field label={tr('settings.reorder.serviceFactor')}><input className="input" type="number" step="0.01" min="0" max="3" value={reorderRule.service_factor} onChange={(e) => setReorderRule({ ...reorderRule, service_factor: e.target.value })} /></Field>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="btn" disabled={calculatingSafety} onClick={calculateSafetyStock}>{calculatingSafety ? tr('settings.reorder.calculating') : tr('settings.reorder.calculate')}</button>
                        <button className="btn btn--primary">{tr('settings.reorder.save')}</button>
                    </div>
                </form>
                {safetyResult && <div className="banner banner--info" style={{ marginTop: 12 }}>
                    {tr('settings.reorder.result', { demand: safetyResult.total_demand, days: safetyResult.lookback_days, average: safetyResult.average_daily_demand, lead: safetyResult.lead_time_demand, available: safetyResult.available_qty })}
                </div>}
                {(s.warehouse_reorder_rules ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.common.item')}</th><th>{tr('settings.common.warehouse')}</th><th>{tr('settings.reorder.pointShort')}</th><th>{tr('settings.reorder.qtyShort')}</th><th>{tr('settings.reorder.minMax')}</th><th>{tr('settings.reorder.safetyShort')}</th></tr></thead><tbody>
                    {s.warehouse_reorder_rules.map((rule) => <tr key={rule.id}><td>{rule.item ? `${rule.item.sku} · ${rule.item.name}` : `#${rule.item_id}`}</td><td>{rule.warehouse ? `${rule.warehouse.code} · ${rule.warehouse.name}` : `#${rule.warehouse_id}`}</td><td>{rule.reorder_point ?? '—'}</td><td>{rule.reorder_qty ?? '—'}</td><td>{rule.min_stock ?? '—'} / {rule.max_stock ?? '—'}</td><td>{rule.safety_stock ?? '—'}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.reasons.title')}</h2>
                <form className="fg2" onSubmit={addReasonCode}>
                    <Field label={tr('settings.common.code')}><input className="input" value={reasonCode.code} onChange={(e) => setReasonCode({ ...reasonCode, code: e.target.value })} placeholder={tr('settings.reasons.codePlaceholder')} required /></Field>
                    <Field label={tr('settings.reasons.label')}><input className="input" value={reasonCode.label} onChange={(e) => setReasonCode({ ...reasonCode, label: e.target.value })} placeholder={tr('settings.reasons.labelPlaceholder')} /></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">{tr('settings.reasons.save')}</button></div>
                </form>
                {(s.settings?.adjustment_reason_codes ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.common.code')}</th><th>{tr('settings.reasons.label')}</th><th>{tr('settings.common.status')}</th></tr></thead><tbody>
                    {(s.settings.adjustment_reason_codes ?? []).map((code) => <tr key={code.code}><td>{code.code}</td><td>{code.label ?? code.code}</td><td>{tr(code.active === false ? 'settings.common.inactive' : 'settings.common.active')}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.currency.title')}</h2>
                <form className="fg2" onSubmit={addCurrencyRate}>
                    <Field label={tr('settings.currency.currency')}><input className="input" value={currencyRate.currency_code} onChange={(e) => setCurrencyRate({ ...currencyRate, currency_code: e.target.value.toUpperCase().slice(0, 3) })} placeholder={tr('settings.currency.codePlaceholder')} required dir="ltr" /></Field>
                    <Field label={tr('settings.currency.rateToBase')}><input className="input" type="number" step="0.00000001" min="0" value={currencyRate.rate_to_base} onChange={(e) => setCurrencyRate({ ...currencyRate, rate_to_base: e.target.value })} required /></Field>
                    <Field label={tr('settings.currency.effectiveDate')}><input className="input" type="date" value={currencyRate.effective_date} onChange={(e) => setCurrencyRate({ ...currencyRate, effective_date: e.target.value })} required /></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">{tr('settings.currency.save')}</button></div>
                </form>
                {(s.currency_rates ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.currency.currency')}</th><th>{tr('settings.currency.rateToBase')}</th><th>{tr('settings.currency.effective')}</th></tr></thead><tbody>
                    {(s.currency_rates ?? []).map((r) => <tr key={r.id}><td>{r.currency_code}</td><td>{r.rate_to_base}</td><td>{r.effective_date}</td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.roles.title')}</h2>
                <form onSubmit={addCustomRole}>
                    <div className="fg2">
                        <Field label={tr('settings.roles.name')}><input className="input" value={customRole.name} onChange={(e) => setCustomRole({ ...customRole, name: e.target.value })} required /></Field>
                        <Field label={tr('settings.roles.key')}><input className="input" value={customRole.key} onChange={(e) => setCustomRole({ ...customRole, key: e.target.value })} placeholder={tr('settings.roles.keyPlaceholder')} /></Field>
                    </div>
                    <div className="overview-grid" style={{ marginTop: 10 }}>
                        {(rolesQuery.data?.permissions ?? []).map((p) => <label key={p.key} className="card" style={{ padding: 12 }}>
                            <input type="checkbox" checked={customRole.permissions.includes(p.key)} onChange={() => togglePermission(p.key)} /> <strong>{p.key}</strong><br /><span className="muted">{tr(`settings.permission.${p.key}`, {}, p.label)}</span>
                        </label>)}
                    </div>
                    <button className="btn btn--primary" disabled={customRole.permissions.length === 0}>{tr('settings.roles.save')}</button>
                </form>
                {(rolesQuery.data?.roles ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.roles.role')}</th><th>{tr('settings.roles.key')}</th><th>{tr('settings.roles.permissions')}</th><th>{tr('settings.roles.assignments')}</th><th>{tr('settings.common.status')}</th></tr></thead><tbody>
                    {(rolesQuery.data?.roles ?? []).map((r) => <tr key={r.id}><td>{r.name}</td><td>{r.key}</td><td>{(r.permissions ?? []).length}</td><td>{r.assignments_count ?? 0}</td><td>{tr(r.is_active ? 'settings.common.active' : 'settings.common.inactive')}</td></tr>)}
                </tbody></table>}
                <form className="fg2" onSubmit={assignRole} style={{ marginTop: 12 }}>
                    <Field label={tr('settings.warehouseScope.userId')}><input className="input" type="number" min="1" value={roleAssignment.user_id} onChange={(e) => setRoleAssignment({ ...roleAssignment, user_id: e.target.value })} required /></Field>
                    <Field label={tr('settings.roles.role')}><select className="input" value={roleAssignment.role_id} onChange={(e) => setRoleAssignment({ ...roleAssignment, role_id: e.target.value })} required><option value="">{tr('settings.roles.select')}</option>{(rolesQuery.data?.roles ?? []).filter((r) => r.is_active).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
                    <div style={{ alignSelf: 'end' }}><button className="btn btn--primary">{tr('settings.roles.assign')}</button></div>
                </form>
                {(rolesQuery.data?.assignments ?? []).length > 0 && <table className="data-table" style={{ marginTop: 12 }}><thead><tr><th>{tr('settings.roles.userId')}</th><th>{tr('settings.roles.role')}</th><th></th></tr></thead><tbody>
                    {(rolesQuery.data?.assignments ?? []).map((a) => <tr key={a.id}><td>{a.user_id}</td><td>{a.role?.name ?? `#${a.role_id}`}</td><td><button className="btn btn--sm btn--danger" onClick={() => unassignRole(a.user_id)}>{tr('settings.common.remove')}</button></td></tr>)}
                </tbody></table>}
            </div>

            <div className="panel">
                <h2>{tr('settings.integration.title')}</h2>
                <dl className="kv">
                    <dt>{tr('settings.common.status')}</dt><dd>{tr(intg.connected ? 'settings.integration.connected' : 'settings.integration.notConnected')}</dd>
                    <dt>{tr('settings.integration.delivery')}</dt><dd>{tr(intg.delivery_configured ? 'settings.integration.configured' : 'settings.integration.awaitingCredentials')}</dd>
                    <dt>{tr('settings.integration.inventoryAsset')}</dt><dd>{intg.account_mappings?.inventory_asset ?? tr('settings.common.notMapped')}</dd>
                    <dt>{tr('settings.integration.cogs')}</dt><dd>{intg.account_mappings?.cogs ?? tr('settings.common.notMapped')}</dd>
                    <dt>{tr('settings.integration.grni')}</dt><dd>{intg.account_mappings?.grni_accrual ?? tr('settings.common.notMapped')}</dd>
                </dl>
                <p className="muted">{tr('settings.integration.plannedEvents', { events: (intg.planned_events ?? []).join(', ') })}</p>
                <p className="muted">{tr('settings.integration.outboxDescription')}</p>
            </div>
        </section>
    );
}
