import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';

// Lightweight API-backed select pickers used across document line editors.
// Each falls back to an empty list (no tenant) and is plain <select> for now.

function Select({ value, onChange, options, placeholder, getLabel, disabled }) {
    return (
        <select className="input" value={value ?? ''} disabled={disabled}
            onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}>
            <option value="">{placeholder}</option>
            {options.map((o) => <option key={o.id} value={o.id}>{getLabel(o)}</option>)}
        </select>
    );
}

export function ItemPicker({ value, onChange, disabled }) {
    const { data } = useApiQuery(['items-picker'], () => api.items({ per_page: 200, is_active: true }), { fallback: [] });
    const items = Array.isArray(data) ? data : (data?.data ?? []);
    return <Select value={value} onChange={onChange} options={items} disabled={disabled}
        placeholder="Item…" getLabel={(i) => `${i.sku} · ${i.name}`} />;
}

export function WarehousePicker({ value, onChange, disabled, placeholder = 'Warehouse…' }) {
    const { data } = useApiQuery(['warehouses-picker'], () => api.warehouses({ per_page: 200 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    return <Select value={value} onChange={onChange} options={list} disabled={disabled}
        placeholder={placeholder} getLabel={(w) => `${w.code} · ${w.name}`} />;
}

export function BinPicker({ warehouseId, value, onChange, disabled, placeholder = 'Bin (optional)…' }) {
    const { data } = useApiQuery(['warehouse', warehouseId], () => api.warehouse(warehouseId),
        { fallback: null, enabled: !!warehouseId });
    const bins = data?.bins ?? [];
    return <Select value={value} onChange={onChange} options={bins} disabled={disabled || !warehouseId}
        placeholder={placeholder} getLabel={(b) => b.code} />;
}

export function SupplierPicker({ value, onChange, disabled }) {
    const { data } = useApiQuery(['suppliers-picker'], () => api.suppliers({ per_page: 200 }), { fallback: [] });
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    return <Select value={value} onChange={onChange} options={list} disabled={disabled}
        placeholder="Supplier…" getLabel={(s) => `${s.code} · ${s.name}`} />;
}

export function QuantityInput({ value, onChange, disabled }) {
    return <input className="input input--num" type="number" step="0.0001" min="0" disabled={disabled}
        value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
}

export function MoneyInput({ value, onChange, disabled }) {
    return <input className="input input--num" type="number" step="0.0001" min="0" disabled={disabled}
        value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
}
