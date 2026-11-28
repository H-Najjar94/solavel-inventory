import React from 'react';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { t } from '../i18n/index.js';

// "<parent> <part>" display label (just the part name when it already contains the parent name).
export function partLineLabel(parentName, partName) {
    const parent = String(parentName ?? '').trim();
    const part = String(partName ?? '').trim();
    if (!parent || part.toLowerCase().includes(parent.toLowerCase())) return part;
    return `${parent} ${part}`;
}

// One compact single-select under a line whose item has parts. Choosing a part replaces
// THAT line's item through the page's normal item-change path; leaving it empty keeps the parent.
// Renders NOTHING while loading, on error, with no item, or with zero parts.
//   itemId    source row's item id
//   filters   extra search filters, e.g. { purpose: 'sale', item_type: 'inventory' }
//   colSpan   when given, renders as a full-width table row (DocumentLinesTable rowExtra)
//   onChoose(part, label)  part = item row, label = display label
export function ItemPartsPicker({ itemId, filters, colSpan, onChoose, disabled }) {
    if (!itemId) return null;
    return <PartsForItem key={itemId} itemId={itemId} filters={filters} colSpan={colSpan} onChoose={onChoose} disabled={disabled} />;
}

function PartsForItem({ itemId, filters, colSpan, onChoose, disabled }) {
    const query = useApiQuery(
        ['item-parts-lookup', itemId, JSON.stringify(filters ?? {})],
        () => api.items({ per_page: 50, is_active: true, ...(filters ?? {}), part_of: itemId }),
        { fallback: null },
    );
    if (query.isLoading || query.isError || (query.isFetching && !query.data)) return null;
    const parts = (Array.isArray(query.data) ? query.data : (query.data?.data ?? [])).filter((p) => p && p.id && String(p.id) !== String(itemId));
    if (parts.length === 0) return null;

    const choose = (e) => {
        const part = parts.find((p) => String(p.id) === e.target.value);
        if (!part) return;
        const parent = (part.part_of ?? []).find((x) => String(x.id) === String(itemId));
        onChoose?.(part, partLineLabel(parent?.name, part.name));
    };
    const body = <select className="input item-parts-select-one" value="" disabled={disabled} onChange={choose} aria-label={t('itemParts.choosePart')}>
        <option value="">{t('itemParts.choosePart')}</option>
        {parts.map((p) => <option key={p.id} value={p.id}>{p.name}{p.sku ? ` · ${p.sku}` : ''}</option>)}
    </select>;
    return colSpan ? <tr className="item-parts-row"><td colSpan={colSpan}>{body}</td></tr> : <div className="item-parts-row">{body}</div>;
}
