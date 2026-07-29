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
    const { data, isLoading, refetch } = useApiQuery(['item-attachments', String(itemId)], () => api.itemAttachments(itemId), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);

    async function upload(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        if (file.size > MAX_BYTES) {
            toast.push(t('media.attachmentSizeError', 'Attachment must be 10 MB or smaller.'), 'error');
            return;
        }
        setBusy(true);
        try {
            await api.uploadItemAttachment(itemId, file);
            toast.push(t('media.attachmentUploaded', 'Attachment uploaded.'), 'success');
            await refetch();
            await qc.invalidateQueries({ queryKey: ['item', String(itemId)] });
        } catch (err) {
            toast.push(err.message, 'error');
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
            toast.push(err.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    if (isLoading) return <Skeleton rows={2} />;

    const action = canManage && (
        <>
            <input ref={fileRef} type="file" hidden onChange={upload} />
            <button className="btn btn--sm btn--primary" disabled={busy} onClick={() => fileRef.current?.click()}>
                {busy ? t('media.uploading', 'Uploading…') : t('media.uploadAttachment')}
            </button>
        </>
    );

    if (!rows.length) {
        return <EmptyState title={t('media.noAttachments')} hint={t('attachments.emptyHint', 'Item documents, specification sheets and supplier files appear here.')} action={action} />;
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
                        <td style={{ textAlign: 'right' }}>{canManage && <button className="btn btn--sm btn--danger" disabled={busy} onClick={() => remove(a.id)}>{t('delete')}</button>}</td>
                    </tr>
                ))}</tbody>
            </table>
        </div>
    );
}
