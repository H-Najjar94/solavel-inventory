import React, { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useToast } from '../stores/toast.jsx';
import { Skeleton, EmptyState } from './ui.jsx';

const ACCEPT = 'image/jpeg,image/png,image/webp';
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * Item image gallery. Images are served from a private, authenticated API route
 * (never a public URL). Viewers see the gallery; managers (canManage) can upload
 * MULTIPLE images at once, set any image primary, and delete any image.
 */
export default function ItemImages({ itemId, canManage, compact = false }) {
    const qc = useQueryClient();
    const toast = useToast();
    const fileRef = useRef(null);
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState(null); // {done,total}

    const { data, isLoading, refetch } = useApiQuery(['item-images', String(itemId)],
        () => api.itemImages(itemId), { fallback: [] });
    const images = Array.isArray(data) ? data : (data?.data ?? []);

    function invalidate() {
        refetch();
        qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
        qc.invalidateQueries({ queryKey: ['items'] });
    }

    async function onPick(e) {
        const files = Array.from(e.target.files ?? []);
        e.target.value = '';
        if (!files.length) return;

        const valid = [];
        for (const f of files) {
            if (!ALLOWED.includes(f.type)) { toast.push(`${f.name}: only JPG, PNG or WEBP allowed.`, 'error'); continue; }
            if (f.size > MAX_BYTES) { toast.push(`${f.name}: must be 5 MB or smaller.`, 'error'); continue; }
            valid.push(f);
        }
        if (!valid.length) return;

        setBusy(true); setProgress({ done: 0, total: valid.length });
        let ok = 0;
        for (let i = 0; i < valid.length; i++) {
            try { await api.uploadItemImage(itemId, valid[i]); ok++; }
            catch (err) { toast.push(`${valid[i].name}: ${err.message}`, 'error'); }
            setProgress({ done: i + 1, total: valid.length });
        }
        setBusy(false); setProgress(null);
        if (ok) { toast.push(ok === 1 ? 'Image uploaded.' : `${ok} images uploaded.`, 'success'); invalidate(); }
    }

    async function makePrimary(id) {
        setBusy(true);
        try { await api.setItemImagePrimary(id); invalidate(); }
        catch (err) { toast.push(err.message, 'error'); }
        finally { setBusy(false); }
    }

    async function remove(id) {
        setBusy(true);
        try { await api.deleteItemImage(id); toast.push('Image removed.', 'success'); invalidate(); }
        catch (err) { toast.push(err.message, 'error'); }
        finally { setBusy(false); }
    }

    if (isLoading) return <Skeleton rows={2} />;

    const uploadBtn = canManage && (
        <>
            <input ref={fileRef} type="file" accept={ACCEPT} multiple hidden onChange={onPick} />
            <button className="btn btn--sm btn--primary" disabled={busy} onClick={() => fileRef.current?.click()}>
                {busy && progress ? `Uploading ${progress.done}/${progress.total}…` : (images.length ? '+ Add images' : 'Upload images')}
            </button>
        </>
    );

    if (!images.length) {
        return (
            <div className="item-gallery-empty">
                <EmptyState title="No images yet"
                    hint={canManage ? 'Upload one or more product photos (JPG, PNG or WEBP, up to 5 MB each). Stored privately.' : 'No product photos have been added for this item.'}
                    action={uploadBtn} />
            </div>
        );
    }

    return (
        <div className={`item-gallery ${compact ? 'item-gallery--compact' : ''}`}>
            <div className="gallery-grid">
                {images.map((img) => (
                    <figure key={img.id} className={`gallery-cell ${img.is_primary ? 'gallery-cell--primary' : ''}`}>
                        <img src={img.url} alt="" loading="lazy" />
                        {img.is_primary && <span className="gallery-primary-badge">Primary</span>}
                        {canManage && (
                            <div className="gallery-actions">
                                {!img.is_primary && <button className="gallery-act" title="Set primary" disabled={busy} onClick={() => makePrimary(img.id)}>★</button>}
                                <button className="gallery-act gallery-act--danger" title="Delete" disabled={busy} onClick={() => remove(img.id)}>🗑</button>
                            </div>
                        )}
                    </figure>
                ))}
            </div>
            {canManage && (
                <div className="item-images-actions">
                    {uploadBtn}
                    <div className="item-images-hint">JPG, PNG or WEBP · up to 5 MB each · stored privately · ★ sets primary.</div>
                </div>
            )}
        </div>
    );
}
