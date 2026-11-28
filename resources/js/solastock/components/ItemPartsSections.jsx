import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { t } from '../i18n/index.js';

function LinkList({ title, rows, canManage, onRemove, busyId }) {
    return (
        <div className="card"><div className="card-head"><h3>{title}</h3></div><div className="card-body">
            <ul className="item-parts-list">
                {rows.map((r) => <li key={r.id}>
                    <Link to={`/items/${r.id}`}><bdi>{r.name}</bdi></Link> <bdi className="item-parts-sku">{r.sku}</bdi>
                    {r.is_active === false && <span className="muted">· {t('itemParts.inactive')}</span>}
                    {canManage && <button type="button" className="btn btn--sm" disabled={busyId === r.id} onClick={() => onRemove(r)}>{t('itemParts.removeLink')}</button>}
                </li>)}
            </ul>
        </div></div>
    );
}

// "Parts" and "Part of" sections of the item page. Each renders only when it has rows.
export default function ItemPartsSections({ itemId, links, canManage }) {
    const qc = useQueryClient();
    const toast = useToast();
    const [busyId, setBusyId] = useState(null);
    const parts = links?.parts ?? [];
    const parents = links?.parents ?? [];
    if (parts.length === 0 && parents.length === 0) return null;

    async function remove(row, role) {
        setBusyId(row.id);
        try {
            if (role === 'part') await api.deleteItemPart(itemId, row.id);
            else await api.deleteItemPart(row.id, itemId);
            toast.push(t('itemParts.removed'), 'success');
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
            await qc.invalidateQueries({ queryKey: ['item', itemId] });
            qc.invalidateQueries({ queryKey: ['item-parts-lookup'] });
        } catch (e) { toast.failure(e, e.message || t('itemParts.removeFailed')); }
        finally { setBusyId(null); }
    }

    return <>
        {parts.length > 0 && <LinkList title={t('itemParts.parts')} rows={parts} canManage={canManage} busyId={busyId} onRemove={(r) => remove(r, 'part')} />}
        {parents.length > 0 && <LinkList title={t('itemParts.partOf')} rows={parents} canManage={canManage} busyId={busyId} onRemove={(r) => remove(r, 'parent')} />}
    </>;
}
