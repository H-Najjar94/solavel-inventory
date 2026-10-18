import {ConfirmedActionButton} from './ConfirmedActionButton';
import {text as feedbackText} from '../../shared/feedback/messages';
import React, { useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useToast } from '../stores/toast.jsx';
import { EmptyState, Skeleton } from './ui.jsx';
import { t } from '../i18n/index.js';

const MAX_BYTES = 10 * 1024 * 1024;

export default function ItemAttachments({ itemId, canManage }) {
    const qc = useQueryClient();
    const toast = useToast();
    const fileRef = useRef(null);
    const [busy, setBusy] = useState(false);
    const [validation,setValidation]=useState('');
    const { data, isLoading, refetch } = useApiQuery(['item-attachments', String(itemId)], () => api.itemAttachments(itemId), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);

    async function upload(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        if (file.size > MAX_BYTES) {
            setValidation(t('media.attachmentSizeError', 'Attachment must be 10 MB or smaller.'));
            return;
        }
        setValidation('');
        setBusy(true);
        try {
            await api.uploadItemAttachment(itemId, file);
            toast.push(t('media.attachmentUploaded', 'Attachment uploaded.'), 'success');
            await refetch();
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
        } catch (err) {
            toast.failure(err, err.message);
        } finally {
            setBusy(false);
        }
    }

    async function remove(id) {
        setBusy(true);
        try {
            await api.deleteItemAttachment(id);
            toast.push(t('media.attachmentRemoved', 'Attachment removed.'), 'success');
            await refetch();
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
        } catch (err) {
            toast.failure(err, err.message);
        } finally {
            setBusy(false);
        }
    }

    if (isLoading) return <Skeleton rows={2} />;

    const action = canManage && (
        <>
            {validation&&<p className="field-error" role="alert">{validation}</p>}
            <input ref={fileRef} type="file" hidden onChange={upload} />
            <button type="button" className="btn btn--primary item-media-upload" disabled={busy} onClick={() => fileRef.current?.click()}>
                {busy ? t('media.uploading', 'Uploading…') : t('media.uploadAttachment')}
            </button>
        </>
    );

    if (!rows.length) {
        return (
            <div>
                <div className="card-head"><h3>{t('attachments.documents', 'Documents')}</h3></div>
                <div className="item-gallery-empty item-image-drop">
                    <div className="item-image-drop-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg></div>
                    <h3>{t('media.noAttachments')}</h3>
                    <p>{t('attachments.emptyHint', 'Item documents, specification sheets and supplier files appear here.')}</p>
                    {action && <div className="item-image-drop-actions">{action}</div>}
                </div>
            </div>
        );
    }

    return (
        <div>
            <div className="card-head"><h3>{t('attachments.documents', 'Documents')}</h3><span className="spacer" />{action}</div>
            <table className="data-table">
                <thead><tr><th>{t('attachments.name', 'Name')}</th><th>{t('type')}</th><th>{t('attachments.size', 'Size')}</th><th></th></tr></thead>
                <tbody>{rows.map((a) => (
                    <tr key={a.id}>
                        <td><a href={a.download_url} target="_blank" rel="noreferrer">{a.name}</a></td>
                        <td>{a.mime_type ?? 'file'}</td>
                        <td>{Math.ceil((a.size_bytes ?? 0) / 1024)} KB</td>
                        <td style={{ textAlign: 'right' }}>{canManage && <ConfirmedActionButton className="btn btn--sm btn--danger" disabled={busy}  title={feedbackText("deleteAttachment")} message={feedbackText("deleteAttachmentBody")} action={feedbackText("deleteAttachment")} onConfirm={()=>remove(a.id)}> {t('delete')}</ConfirmedActionButton>}</td>
                    </tr>
                ))}</tbody>
            </table>
        </div>
    );
}
