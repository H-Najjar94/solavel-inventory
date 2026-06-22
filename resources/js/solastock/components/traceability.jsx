import React, { useEffect, useRef, useState } from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';

// ── Traceability capture + selection components ───────────────────────────────
// Capture (IN): LotCapture, SerialCapture/SerialNumberListInput, ExpiryDateInput.
// Selection (OUT): LotSelector, SerialSelector (availability-backed).
// All are controlled and scanner-friendly where a serial is keyed.

export function TraceabilityRequiredBadge({ trackingType, tracksExpiry }) {
    const tags = [];
    if (trackingType === 'lot' || trackingType === 'lot_serial') tags.push('Lot');
    if (trackingType === 'serial' || trackingType === 'lot_serial') tags.push('Serial');
    if (tracksExpiry) tags.push('Expiry');
    if (tags.length === 0) return null;
    return <span className="badge badge--demo" title="Traceability required on this item">{tags.join(' + ')} tracked</span>;
}

export function ExpiryDateInput({ value, onChange, required, error }) {
    return (
        <input className={`input${error ? ' input--error' : ''}`} type="date"
            value={value ?? ''} required={required}
            onChange={(e) => onChange(e.target.value)} aria-label="Expiry date" />
    );
}

/** Capture a lot code + optional mfg/expiry for an inbound line. */
export function LotCapture({ value, onChange, requireExpiry, error }) {
    const v = value ?? {};
    const set = (patch) => onChange({ ...v, ...patch });
    return (
        <div className="lot-capture">
            <input className={`input${error?.lot_code ? ' input--error' : ''}`} placeholder="Lot / batch code"
                value={v.lot_code ?? ''} onChange={(e) => set({ lot_code: e.target.value })} aria-label="Lot code" />
            <ExpiryDateInput value={v.expiry_date} onChange={(d) => set({ expiry_date: d })}
                required={requireExpiry} error={error?.expiry_date} />
        </div>
    );
}

/**
 * Scanner-friendly multi-serial input. Enter adds, paste splits on newline/comma,
 * duplicates are flagged. Count is validated against the expected quantity.
 */
export function SerialNumberListInput({ value = [], onChange, expectedQty, autoFocus = true }) {
    const [entry, setEntry] = useState('');
    const [warn, setWarn] = useState('');
    const [large, setLarge] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => { if (autoFocus && inputRef.current) inputRef.current.focus(); }, [autoFocus]);

    const lower = value.map((s) => s.toLowerCase());

    function addMany(raw) {
        const parts = String(raw).split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
        if (parts.length === 0) return;
        const next = [...value];
        const seen = new Set(lower);
        let dup = 0;
        for (const p of parts) {
            if (seen.has(p.toLowerCase())) { dup++; continue; }
            seen.add(p.toLowerCase());
            next.push(p);
        }
        onChange(next);
        setWarn(dup > 0 ? `${dup} duplicate serial${dup > 1 ? 's' : ''} skipped.` : '');
        setEntry('');
    }

    function onKeyDown(e) {
        if (e.key === 'Enter') { e.preventDefault(); addMany(entry); }
    }
    function onPaste(e) {
        const text = e.clipboardData.getData('text');
        if (/[\n,]/.test(text)) { e.preventDefault(); addMany(text); }
    }
    function remove(i) { onChange(value.filter((_, idx) => idx !== i)); }

    const countOk = expectedQty == null || value.length === Number(expectedQty);

    return (
        <div className="serial-capture">
            <div className="serial-capture__bar">
                <input ref={inputRef} className={`input${large ? ' input--scan' : ''}`}
                    placeholder="Scan or type serial, Enter to add"
                    value={entry} onChange={(e) => setEntry(e.target.value)} onKeyDown={onKeyDown} onPaste={onPaste}
                    aria-label="Serial number scan input" />
                <button type="button" className="btn btn--sm" onClick={() => addMany(entry)}>Add</button>
                <label className="serial-capture__scanmode" title="Larger input for handheld scanners">
                    <input type="checkbox" checked={large} onChange={(e) => setLarge(e.target.checked)} /> Scan mode
                </label>
            </div>
            <div className="serial-capture__meta">
                <span className={countOk ? 'badge badge--live' : 'badge badge--warn'}>
                    {value.length}{expectedQty != null ? ` / ${expectedQty}` : ''} serials
                </span>
                {warn && <span className="serial-capture__warn">{warn}</span>}
            </div>
            {value.length > 0 && (
                <ul className="serial-capture__list">
                    {value.map((s, i) => (
                        <li key={s + i}><span>{s}</span><button type="button" className="btn btn--sm btn--danger" onClick={() => remove(i)}>×</button></li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Single-serial capture (qty 1 contexts). Thin wrapper over the list input. */
export function SerialCapture({ value, onChange }) {
    const list = value ? [value] : [];
    return <SerialNumberListInput value={list} expectedQty={1} onChange={(arr) => onChange(arr[arr.length - 1] ?? null)} />;
}

/** Select an available lot for an OUT line (availability-backed; flags expiry/quarantine). */
export function LotSelector({ itemId, warehouseId, value, onChange, disabled }) {
    const { data } = useApiQuery(['lot-availability', itemId, warehouseId],
        () => api.lotAvailability(itemId, warehouseId), { fallback: null, enabled: !!itemId });
    const lots = data?.lots ?? [];
    return (
        <select className="input" value={value ?? ''} disabled={disabled || !itemId}
            onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)} aria-label="Lot">
            <option value="">Lot…</option>
            {lots.map((l) => {
                const blocked = ['expired', 'quarantined', 'recalled'].includes(l.status);
                const avail = Number(l.on_hand_qty) - Number(l.reserved_qty ?? 0);
                return (
                    <option key={l.lot_id} value={l.lot_id} disabled={blocked}>
                        {l.lot_code} · {avail} avail{l.expiry_date ? ` · exp ${l.expiry_date}` : ''}{blocked ? ` (${l.status})` : ''}
                    </option>
                );
            })}
        </select>
    );
}

/** Select available serials for an OUT line. */
export function SerialSelector({ itemId, warehouseId, value = [], onChange, expectedQty, disabled }) {
    const { data } = useApiQuery(['serial-availability', itemId, warehouseId],
        () => api.serialAvailability(itemId, warehouseId), { fallback: null, enabled: !!itemId });
    const serials = data?.serials ?? [];
    const toggle = (id) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id]);
    const countOk = expectedQty == null || value.length === Number(expectedQty);
    return (
        <div className="serial-selector">
            <span className={countOk ? 'badge badge--live' : 'badge badge--warn'}>
                {value.length}{expectedQty != null ? ` / ${expectedQty}` : ''} selected
            </span>
            <div className="serial-selector__list">
                {serials.length === 0 && <span className="muted">No available serials.</span>}
                {serials.map((s) => (
                    <label key={s.id} className="serial-selector__opt">
                        <input type="checkbox" disabled={disabled} checked={value.includes(s.id)} onChange={() => toggle(s.id)} />
                        {s.serial}
                    </label>
                ))}
            </div>
        </div>
    );
}

/**
 * FEFO suggestion + ordering warning for an OUT lot line. Shows the suggested
 * earliest-expiry lot(s) and warns if the chosen lot expires later than an
 * available earlier one. Suggestion only — does not force a choice.
 */
export function FefoHint({ itemId, warehouseId, quantity, selectedLotId, onApply }) {
    const enabled = !!itemId && Number(quantity) > 0;
    const { data } = useApiQuery(['fefo', itemId, warehouseId, quantity],
        () => api.suggestOutboundLots(itemId, warehouseId, quantity), { fallback: null, enabled });
    if (!data || !data.lines || data.lines.length === 0) return null;

    const first = data.lines[0];
    const lots = data.lines;
    // Warn if the selected lot expires later than the suggested earliest.
    let warn = null;
    if (selectedLotId && first.lot_id !== selectedLotId) {
        const sel = lots.find((l) => l.lot_id === selectedLotId);
        if (!sel || (first.expiry_date && (!sel?.expiry_date || sel.expiry_date > first.expiry_date))) {
            warn = `Earlier-expiring lot ${first.lot_code}${first.expiry_date ? ` (exp ${first.expiry_date})` : ''} is available — FEFO suggests it first.`;
        }
    }

    return (
        <div className="fefo-hint">
            <span className="fefo-hint__policy">{data.policy.toUpperCase()} suggestion:</span>
            <span className="fefo-hint__lot">{first.lot_code}{first.expiry_date ? ` · exp ${first.expiry_date}` : ''} · {first.suggested_qty}</span>
            {onApply && <button type="button" className="btn btn--sm" onClick={() => onApply(first)}>Use</button>}
            {!data.fully_covered && <span className="badge badge--warn">short {data.shortfall}</span>}
            {warn && <div className="fefo-hint__warn">⚠ {warn}</div>}
        </div>
    );
}

export function LotStatusBadge({ status }) {
    const map = {
        active: ['Active', 'badge--live'], expired: ['Expired', 'badge--warn'],
        quarantined: ['Quarantined', 'badge--warn'], consumed: ['Consumed', 'badge--muted'],
        recalled: ['Recalled', 'badge--danger'],
    };
    const [label, cls] = map[status] ?? [status, 'badge--muted'];
    return <span className={`badge ${cls}`}>{label}</span>;
}

export function SerialStatusBadge({ status }) {
    const live = ['available', 'in_stock'];
    const danger = ['damaged', 'quarantined', 'retired'];
    const cls = live.includes(status) ? 'badge--live' : danger.includes(status) ? 'badge--warn' : 'badge--demo';
    return <span className={`badge ${cls}`}>{status}</span>;
}
