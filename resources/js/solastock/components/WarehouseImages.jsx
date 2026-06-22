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
 * Warehouse image gallery — BANNER-style (wide primary). Served from a private,
 * authenticated API route (never a public URL). Viewers see the gallery; managers
 * (canManage) upload multiple, set primary, and delete.
 */
export default function WarehouseImages({ warehouseId, canManage }) {
    const qc = useQueryClient();
    const toast = useToast();
    const fileRef = useRef(null);
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState(null);

    const { data, isLoading, refetch } = useApiQuery(['warehouse-images', String(warehouseId)],
        () => api.warehouseImages(warehouseId), { fallback: [] });
    const images = Array.isArray(data) ? data : (data?.data ?? []);
    const primary = images.find((i) => i.is_primary) ?? images[0];

    function invalidate() {
        refetch();
        qc.invalidateQueries({ queryKey: ['warehouse', String(warehouseId)] });
        qc.invalidateQueries({ queryKey: ['warehouses'] });
    }

    async function onPick(e) {
        const files = Array.from(e.target.files ?? []);
        e.target.value = '';
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
            try { await api.uploadWarehouseImage(warehouseId, valid[i]); ok++; }
            catch (err) { toast.push(`${valid[i].name}: ${err.message}`, 'error'); }
            setProgress({ done: i + 1, total: valid.length });
        }
        setBusy(false); setProgress(null);
        if (ok) { toast.push(ok === 1 ? 'Image uploaded.' : `${ok} images uploaded.`, 'success'); invalidate(); }
    }

    async function makePrimary(id) { setBusy(true); try { await api.setWarehouseImagePrimary(id); invalidate(); } catch (e) { toast.push(e.message, 'error'); } finally { setBusy(false); } }
    async function remove(id) { setBusy(true); try { await api.deleteWarehouseImage(id); toast.push('Image removed.', 'success'); invalidate(); } catch (e) { toast.push(e.message, 'error'); } finally { setBusy(false); } }

    if (isLoading) return <Skeleton rows={2} />;

    const uploadBtn = canManage && (
        <>
            <input ref={fileRef} type="file" accept={ACCEPT} multiple hidden onChange={onPick} />
            <button className="btn btn--sm btn--primary" disabled={busy} onClick={() => fileRef.current?.click()}>
                {busy && progress ? `Uploading ${progress.done}/${progress.total}…` : (images.length ? '+ Add images' : 'Upload banner')}
            </button>
        </>
    );

    if (!images.length) {
        return <div className="wh-gallery-empty"><EmptyState title="No warehouse image yet"
            hint={canManage ? 'Upload a wide banner photo of this location (JPG, PNG or WEBP, up to 5 MB). Stored privately.' : 'No photo has been added for this warehouse.'}
            action={uploadBtn} /></div>;
    }

    return (
        <div className="wh-gallery">
            {primary && <div className="wh-banner"><img src={primary.url} alt="Warehouse" /></div>}
            {images.length > 1 && (
                <div className="wh-thumb-row">
                    {images.map((img) => (
                        <figure key={img.id} className={`wh-thumb ${img.is_primary ? 'wh-thumb--primary' : ''}`}>
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
            )}
            {canManage && (
                <div className="item-images-actions">
                    {uploadBtn}
                    {images.length === 1 && <button className="btn btn--sm btn--danger" disabled={busy} onClick={() => remove(primary.id)}>Remove image</button>}
                    <div className="item-images-hint">JPG, PNG or WEBP · up to 5 MB each · stored privately · ★ sets the banner.</div>
                </div>
            )}
        </div>
    );
}
