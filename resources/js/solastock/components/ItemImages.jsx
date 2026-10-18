import {ConfirmedActionButton} from './ConfirmedActionButton';
import {text as feedbackText} from '../../shared/feedback/messages';
import React, { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useToast } from '../stores/toast.jsx';
import { Skeleton } from './ui.jsx';
import { t } from '../i18n/index.js';

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
    const [dragging, setDragging] = useState(false);
    const [busy, setBusy] = useState(false);
    const [validation,setValidation]=useState('');
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
        await uploadFiles(files);
    }

    async function uploadFiles(files) {
        if (!files.length || busy || !canManage) return;

        const valid = [],rejected=[];
        for (const f of files) {
            if (!ALLOWED.includes(f.type)) { rejected.push(t('media.imageTypeError', undefined, { file: f.name })); continue; }
            if (f.size > MAX_BYTES) { rejected.push(t('media.imageSizeError', undefined, { file: f.name })); continue; }
            valid.push(f);
        }
        setValidation(rejected.join(' '));
        if (!valid.length) return;

        setBusy(true); setProgress({ done: 0, total: valid.length });
        let ok = 0;
        for (let i = 0; i < valid.length; i++) {
            try { await api.uploadItemImage(itemId, valid[i]); ok++; }
            catch (err) { toast.failure(err, `${valid[i].name}: ${err.message}`); }
            setProgress({ done: i + 1, total: valid.length });
        }
        setBusy(false); setProgress(null);
        if (ok) { toast.push(ok === 1 ? t('media.imageUploaded') : t('media.imagesUploaded', undefined, { count: ok }), 'success'); invalidate(); }
    }

    async function makePrimary(id) {
        setBusy(true);
        try { await api.setItemImagePrimary(id); invalidate(); }
        catch (err) { toast.failure(err, err.message); }
        finally { setBusy(false); }
    }

    async function remove(id) {
        setBusy(true);
        try { await api.deleteItemImage(id); toast.push(t('media.imageRemoved'), 'success'); invalidate(); }
        catch (err) { toast.failure(err, err.message); }
        finally { setBusy(false); }
    }

    if (isLoading) return <Skeleton rows={2} />;

    const uploadBtn = canManage && (
        <>
            {validation&&<p className="field-error" role="alert">{validation}</p>}
            <input ref={fileRef} type="file" accept={ACCEPT} multiple hidden onChange={onPick} />
            <button type="button" className="btn btn--primary item-media-upload" disabled={busy} onClick={() => fileRef.current?.click()}>
                {busy && progress ? t('media.uploading', 'Uploading :done/:total…', progress) : (images.length ? `+ ${t('media.addImages')}` : t('media.uploadImages'))}
            </button>
        </>
    );

    if (!images.length) {
        return (
            <div className={`item-gallery-empty item-image-drop${dragging ? ' is-dragging' : ''}`} aria-busy={busy}
                onDragOver={(e) => { if (canManage && !busy) { e.preventDefault(); setDragging(true); } }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => { e.preventDefault(); setDragging(false); void uploadFiles(Array.from(e.dataTransfer.files ?? [])); }}>
                <div className="item-image-drop-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m3 17 5-5 4 4 3-3 6 6"/></svg></div>
                <h3>{t('media.noImages')}</h3>
                <p>{canManage ? t('media.imagesHint') : undefined}</p>
                <div className="item-image-drop-actions">{uploadBtn}</div>
            </div>
        );
    }

    return (
        <div className={`item-gallery ${compact ? 'item-gallery--compact' : ''}`}>
            <div className="gallery-grid">
                {images.map((img) => (
                    <figure key={img.id} className={`gallery-cell ${img.is_primary ? 'gallery-cell--primary' : ''}`}>
                        <img src={img.url} alt="" loading="lazy" />
                        {img.is_primary && <span className="gallery-primary-badge">{t('media.primary')}</span>}
                        {canManage && (
                            <div className="gallery-actions">
                                {!img.is_primary && <button className="gallery-act" title={t('media.setPrimary')} disabled={busy} onClick={() => makePrimary(img.id)}>★</button>}
                                <ConfirmedActionButton className="gallery-act gallery-act--danger" disabled={busy}  title={feedbackText("deleteImage")} message={feedbackText("deleteImageBody")} action={feedbackText("deleteImage")} onConfirm={()=>remove(img.id)}> 🗑</ConfirmedActionButton>
                            </div>
                        )}
                    </figure>
                ))}
            </div>
            {canManage && (
                <div className="item-images-actions">
                    {uploadBtn}
                    <div className="item-images-hint">{t('media.imagesHint')}</div>
                </div>
            )}
        </div>
    );
}
