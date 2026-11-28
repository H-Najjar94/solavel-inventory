import React, { useEffect, useState } from 'react';
import { api } from '../services/api.js';
import { t } from '../i18n/index.js';

// Searchable multi-select for the item form's "Part of" field. Offers active items only
// (never the item itself); already-linked inactive parents stay listed as chips.
//   value     [{id, name, sku, is_active}]
//   onChange  (next) => void
export function ItemPartOfSelect({ value, onChange, excludeId, disabled }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [busy, setBusy] = useState(false);
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
                if (live) setResults(Array.isArray(res?.data) ? res.data : []);
            } catch { if (live) setResults([]); }
            finally { if (live) setBusy(false); }
        }, 250);
        return () => { live = false; clearTimeout(timer); };
    }, [q]);

    const pick = (item) => {
        if (chosen.some((c) => c.id === item.id)) return;
        onChange([...chosen, { id: item.id, name: item.name, sku: item.sku, is_active: item.is_active !== false }]);
        setQ(''); setResults([]);
    };

    return (
        <div className="item-parts-box">
            {chosen.length > 0 && <div className="item-parts-chips">
                {chosen.map((c) => <span key={c.id} className="item-parts-chip">
                    <bdi>{c.name}</bdi> <bdi className="item-parts-sku">{c.sku}</bdi>{c.is_active === false && <span className="muted">· {t('itemParts.inactive')}</span>}
                    <button type="button" aria-label={t('itemParts.remove')} disabled={disabled} onClick={() => onChange(chosen.filter((x) => x.id !== c.id))}>×</button>
                </span>)}
            </div>}
            <input className="input" value={q} disabled={disabled} onChange={(e) => setQ(e.target.value)} placeholder={t('itemParts.searchPlaceholder')} />
            {q.trim() !== '' && !busy && results.length === 0 && <span className="muted">{t('itemParts.noMatches')}</span>}
            {results.length > 0 && <div className="item-parts-results">
                {results.map((r) => <button type="button" key={r.id} onClick={() => pick(r)}><bdi>{r.name}</bdi> <bdi className="item-parts-sku">{r.sku}</bdi></button>)}
            </div>}
        </div>
    );
}
