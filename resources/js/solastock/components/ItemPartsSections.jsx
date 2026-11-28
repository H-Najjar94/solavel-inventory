import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { t } from '../i18n/index.js';
import { partContext } from './ItemPartOfSelect.jsx';

// "Part of X" links shown right under the item title.
export function ItemPartOfLinks({ parents }) {
    const list = parents ?? [];
    if (list.length === 0) return null;
    const first = list[0];
    return <div className="item-partof-line">
        {t('itemParts.partOf')} <Link to={`/items/${first.id}`}><bdi>{first.name}</bdi></Link>
        {list.length > 1 && <> {list.slice(1).map((p, i) => <span key={p.id}>{i > 0 ? ', ' : '+ '}<Link to={`/items/${p.id}`}><bdi>{p.name}</bdi></Link></span>)}</>}
    </div>;
}

// Compact search that links an existing item as a part of this item.
export function LinkExistingPart({ itemId, parts, onClose }) {
    const qc = useQueryClient();
    const toast = useToast();
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [busyId, setBusyId] = useState(null);
    const timer = React.useRef(null);
    const search = (value) => {
        setQ(value);
        clearTimeout(timer.current);
        if (!value.trim()) { setResults([]); return; }
        timer.current = setTimeout(async () => {
            try {
                const exclude = [itemId, ...(parts ?? []).map((p) => p.id)].join(',');
                const res = await api.items({ search: value.trim(), is_active: true, per_page: 10, exclude });
                setResults(Array.isArray(res?.data) ? res.data : []);
            } catch { setResults([]); }
        }, 250);
    };
    async function link(row) {
        setBusyId(row.id);
        try {
            await api.createItemPart(itemId, row.id);
            toast.push(t('itemParts.linked'), 'success');
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
            await qc.invalidateQueries({ queryKey: ['item', itemId] });
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['item-parts-lookup'] });
            onClose?.();
        } catch (e) { toast.failure(e, e.message || t('itemParts.linkFailed')); }
        finally { setBusyId(null); }
    }
    return <div className="card item-parts-link-card"><div className="card-body item-parts-box" onKeyDown={(e) => { if (e.key === 'Escape') onClose?.(); }}>
        <div className="item-parts-bar">
            <input className="input" autoFocus value={q} onChange={(e) => search(e.target.value)} placeholder={t('itemParts.searchPlaceholder')} />
            <button type="button" className="btn btn--sm" onClick={onClose}>{t('itemParts.close')}</button>
        </div>
        {q.trim() !== '' && results.length === 0 && <span className="muted">{t('itemParts.noMatches')}</span>}
        <ul className="item-parts-list">
            {results.map((r) => <li key={r.id}>
                <span><bdi className="item-parts-sku">{r.sku}</bdi> · <bdi>{r.name}</bdi>{partContext(r) && <span className="muted"> · {partContext(r)}</span>}</span>
                <button type="button" className="btn btn--sm" disabled={busyId === r.id} onClick={() => link(r)}>{t('itemParts.link')}</button>
            </li>)}
        </ul>
    </div></div>;
}

// Compact "Parts (n)" card: name · SKU · category. Renders only with at least one part.
export default function ItemPartsSections({ itemId, links, canManage }) {
    const qc = useQueryClient();
    const toast = useToast();
    const [busyId, setBusyId] = useState(null);
    const parts = links?.parts ?? [];
    if (parts.length === 0) return null;

    async function remove(row) {
        setBusyId(row.id);
        try {
            await api.deleteItemPart(itemId, row.id);
            toast.push(t('itemParts.removed'), 'success');
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
            await qc.invalidateQueries({ queryKey: ['item', itemId] });
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['item-parts-lookup'] });
        } catch (e) { toast.failure(e, e.message || t('itemParts.removeFailed')); }
        finally { setBusyId(null); }
    }

    return <div className="card item-parts-card"><div className="card-head"><h3>{t('itemParts.partsN', undefined, { count: parts.length })}</h3></div><div className="card-body">
        <ul className="item-parts-list">
            {parts.map((r) => <li key={r.id}>
                <Link to={`/items/${r.id}`}><bdi>{r.name}</bdi></Link>
                <bdi className="item-parts-sku">· {r.sku}</bdi>
                {r.category && <span className="muted">· {r.category}</span>}
                {r.is_active === false && <span className="muted">· {t('itemParts.inactive')}</span>}
                {canManage && <button type="button" className="item-parts-linkbtn" disabled={busyId === r.id} onClick={() => remove(r)}>{t('itemParts.removeLink')}</button>}
            </li>)}
        </ul>
    </div></div>;
}
