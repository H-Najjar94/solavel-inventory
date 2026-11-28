import React, { useEffect, useId, useState } from 'react';
import { api } from '../services/api.js';
import { t } from '../i18n/index.js';

// Parent label for same-named items: "SKU · Part of X".
export function partContext(item) {
    const names = Array.isArray(item?.part_of) ? item.part_of.map((p) => (typeof p === 'string' ? p : p.name)).filter(Boolean) : [];
    return names.length ? t('itemParts.partOfX', undefined, { name: names.slice(0, 2).join(', ') }) : '';
}

// Searchable multi-select for the item form's "Part of" field. Offers active items only
// (never the item itself); already-linked inactive parents stay listed as chips.
// Results render in normal flow (no absolute popup) so nothing is clipped on mobile.
//   value     [{id, name, sku, is_active}]
//   onChange  (next) => void
export function ItemPartOfSelect({ value, onChange, excludeId, disabled }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [busy, setBusy] = useState(false);
    const [active, setActive] = useState(0);
    const listId = useId();
    const chosen = value ?? [];

    useEffect(() => {
        const term = q.trim();
        if (term.length < 1) { setResults([]); return undefined; }
        let live = true;
        const timer = setTimeout(async () => {
            setBusy(true);
            try {
                const exclude = [excludeId, ...chosen.map((c) => c.id)].filter(Boolean);
                const res = await api.items({ search: term, is_active: true, per_page: 20, exclude: exclude.join(',') || undefined });
                if (live) { setResults(Array.isArray(res?.data) ? res.data : []); setActive(0); }
            } catch { if (live) setResults([]); }
            finally { if (live) setBusy(false); }
        }, 250);
        return () => { live = false; clearTimeout(timer); };
    }, [q]);

    const pick = (item) => {
        if (!item || chosen.some((c) => c.id === item.id)) return;
        onChange([...chosen, { id: item.id, name: item.name, sku: item.sku, is_active: item.is_active !== false }]);
        setQ(''); setResults([]);
    };
    const onKeyDown = (e) => {
        if (e.key === 'ArrowDown' && results.length) { e.preventDefault(); setActive((i) => Math.min(results.length - 1, i + 1)); }
        else if (e.key === 'ArrowUp' && results.length) { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
        else if (e.key === 'Enter' && results.length) { e.preventDefault(); pick(results[active]); }
        else if (e.key === 'Escape') { setQ(''); setResults([]); }
        else if (e.key === 'Backspace' && q === '' && chosen.length) { onChange(chosen.slice(0, -1)); }
    };

    return (
        <div className="item-parts-box item-parts-select">
            {chosen.length > 0 && <div className="item-parts-chips">
                {chosen.map((c) => <span key={c.id} className="item-parts-chip">
                    <bdi>{c.name}</bdi> <bdi className="item-parts-sku">{c.sku}</bdi>{c.is_active === false && <span className="muted">· {t('itemParts.inactive')}</span>}
                    <button type="button" aria-label={t('itemParts.remove')} disabled={disabled} onClick={() => onChange(chosen.filter((x) => x.id !== c.id))}>×</button>
                </span>)}
            </div>}
            <input className="input" value={q} disabled={disabled} onChange={(e) => setQ(e.target.value)} onKeyDown={onKeyDown}
                role="combobox" aria-expanded={results.length > 0} aria-controls={listId} aria-autocomplete="list"
                placeholder={t('itemParts.searchPlaceholder')} />
            {q.trim() !== '' && !busy && results.length === 0 && <span className="muted">{t('itemParts.noMatches')}</span>}
            {results.length > 0 && <div className="item-parts-results" id={listId} role="listbox">
                {results.map((r, i) => <button type="button" role="option" aria-selected={i === active} className={i === active ? 'is-active' : ''} key={r.id} onMouseEnter={() => setActive(i)} onClick={() => pick(r)}>
                    <bdi className="item-parts-sku">{r.sku}</bdi> · <bdi>{r.name}</bdi>{partContext(r) && <span className="muted"> · {partContext(r)}</span>}
                </button>)}
            </div>}
        </div>
    );
}
