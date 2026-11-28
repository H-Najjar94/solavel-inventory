import React, { useState } from 'react';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { t } from '../i18n/index.js';

// Parts of the row's item, offered beneath that exact row. Catalog metadata only:
// nothing is added or changed until the user clicks, and added parts are ordinary
// independent lines (the page's own item-selection path decides price/tax/unit).
// Renders NOTHING while loading, with no item, or with zero parts.
//   itemId   source row's item id
//   filters  extra search filters, e.g. { purpose: 'sale', item_type: 'inventory' }
//   colSpan  when given, renders as a full-width table row (DocumentLinesTable rowExtra)
//   onAdd(parts[])   insert independent lines below the source row
//   onReplace(part)  run the normal item change on the source row
export function ItemPartsPicker({ itemId, filters, colSpan, onAdd, onReplace, disabled }) {
    if (!itemId) return null;
    return <PartsForItem key={itemId} itemId={itemId} filters={filters} colSpan={colSpan} onAdd={onAdd} onReplace={onReplace} disabled={disabled} />;
}

function PartsForItem({ itemId, filters, colSpan, onAdd, onReplace, disabled }) {
    const [open, setOpen] = useState(false);
    const [picked, setPicked] = useState([]);
    const query = useApiQuery(
        ['item-parts-lookup', itemId, JSON.stringify(filters ?? {})],
        () => api.items({ per_page: 50, is_active: true, ...(filters ?? {}), part_of: itemId }),
        { fallback: null },
    );
    if (query.isLoading || (query.isFetching && !query.data && !query.isError)) return null;

    const wrap = (body) => colSpan
        ? <tr className="item-parts-row"><td colSpan={colSpan}>{body}</td></tr>
        : <div className="item-parts-row">{body}</div>;

    if (query.isError) {
        return wrap(<div className="item-parts-box" role="status">
            <span className="item-parts-bar"><span className="muted">{t('itemParts.loadFailed')} ·</span>
                <button type="button" className="btn btn--sm" onClick={() => query.refetch()}>{t('itemParts.retry')}</button></span>
        </div>);
    }
    const parts = (Array.isArray(query.data) ? query.data : (query.data?.data ?? [])).filter((p) => p && p.id && String(p.id) !== String(itemId));
    if (parts.length === 0) return null;

    const toggle = (id) => setPicked((cur) => cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]);
    const addSelected = () => {
        const chosen = parts.filter((p) => picked.includes(p.id));
        if (chosen.length === 0) return;
        onAdd?.(chosen);
        setPicked([]); setOpen(false);
    };

    return wrap(<div className="item-parts-box item-parts-popover" onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false); }}>
        <div className="item-parts-bar">
            <button type="button" className="btn btn--sm" aria-expanded={open} disabled={disabled} onClick={() => setOpen((v) => !v)}>
                {t('itemParts.addParts', undefined, { count: parts.length })}
            </button>
        </div>
        {open && <>
            <ul className="item-parts-list">
                {parts.map((p) => <li key={p.id}>
                    <label><input type="checkbox" checked={picked.includes(p.id)} disabled={disabled} onChange={() => toggle(p.id)} />
                        <bdi>{p.name}</bdi> <bdi className="item-parts-sku">{p.sku}</bdi></label>
                    <button type="button" className="item-parts-linkbtn" disabled={disabled} onClick={() => { onReplace?.(p); setOpen(false); }}>{t('itemParts.usePartInstead')}</button>
                </li>)}
            </ul>
            <div className="item-parts-bar">
                <button type="button" className="btn btn--sm btn--primary" disabled={disabled || picked.length === 0} onClick={addSelected}>{picked.length === 0 ? t('itemParts.addPartsNone') : t(picked.length === 1 ? 'itemParts.addNPart' : 'itemParts.addNParts', undefined, { count: picked.length })}</button>
            </div>
        </>}
    </div>);
}
