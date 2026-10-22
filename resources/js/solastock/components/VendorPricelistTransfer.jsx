import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { Badge } from './ui.jsx';
import { TermsModal, Num, useSupplierTermsAccess } from './SupplierTermsShared.jsx';
import { t } from '../i18n/index.js';

const MAX_BYTES = 2_000_000;
const STATUS_TONES = { created: 'ok', updated: 'info', unchanged: 'muted', error: 'danger', pending: 'muted' };
const COLUMNS = 'item_sku, supplier_code, supplier_name, status, preferred, supplier_sku, supplier_item_name, currency_code, purchase_unit, minimum_order_qty, order_multiple, lead_time_days, discount_percent, price_scale, min_qty, unit_price, valid_from, valid_to, notes';

/** "Export CSV" + "Import pricelist" actions, filtered to an item or a supplier when given. */
export default function VendorPricelistTransfer({ itemId, supplierId, compact = false }) {
    const access = useSupplierTermsAccess();
    const toast = useToast();
    const [exporting, setExporting] = useState(false);
    const [importing, setImporting] = useState(false);
    if (!access.exportTerms && !access.importTerms) return null;

    async function exportCsv() {
        setExporting(true);
        try {
            await api.downloadSupplierTerms({ item_id: itemId, supplier_id: supplierId });
        } catch (err) {
            if ([402, 403].includes(err?.status)) toast.push(t('supplierTerms.transfer.exportDenied'), 'error');
            else toast.failure(err, t('supplierTerms.transfer.exportFailed'));
        } finally { setExporting(false); }
    }

    const size = compact ? 'btn btn--sm' : 'btn';
    return (
        <>
            {access.exportTerms && <button type="button" className={size} onClick={exportCsv} disabled={exporting}>
                <i className="fa-solid fa-file-arrow-down" aria-hidden="true" /> {exporting ? t('supplierTerms.transfer.exporting') : t('supplierTerms.transfer.export')}
            </button>}
            {access.importTerms && <button type="button" className={size} onClick={() => setImporting(true)}>
                <i className="fa-solid fa-file-arrow-up" aria-hidden="true" /> {t('supplierTerms.transfer.import')}
            </button>}
            {importing && <PricelistImportModal onClose={() => setImporting(false)} />}
        </>
    );
}

function PricelistImportModal({ onClose }) {
    const toast = useToast();
    const qc = useQueryClient();
    const [file, setFile] = useState(null);
    const [content, setContent] = useState('');
    const [preview, setPreview] = useState(null);
    const [problem, setProblem] = useState('');
    const [busy, setBusy] = useState(false);

    function choose(e) {
        const chosen = e.target.files?.[0] ?? null;
        setFile(chosen); setPreview(null); setProblem(''); setContent('');
        if (!chosen) return;
        if (chosen.size > MAX_BYTES) { setProblem(t('supplierTerms.transfer.tooLarge')); return; }
        const reader = new FileReader();
        reader.onload = () => {
            const value = String(reader.result ?? '');
            setContent(value);
            runPreview(value);
        };
        reader.onerror = () => setProblem(t('supplierTerms.transfer.readFailed'));
        reader.readAsText(chosen, 'UTF-8');
    }

    async function runPreview(value = content) {
        if (!value.trim()) { setProblem(t('supplierTerms.transfer.empty')); return; }
        setBusy(true); setProblem('');
        try {
            const res = await api.importSupplierTerms(value, false);
            setPreview(res?.data ?? null);
        } catch (err) {
            setPreview(null);
            if (err?.status === 422) setProblem(err?.payload?.errors?.content?.[0] ?? err.message);
            else if ([402, 403].includes(err?.status)) setProblem(t('supplierTerms.access.saveDenied'));
            else toast.failure(err, t('supplierTerms.transfer.previewFailed'));
        } finally { setBusy(false); }
    }

    async function apply() {
        setBusy(true); setProblem('');
        try {
            const res = await api.importSupplierTerms(content, true);
            const data = res?.data;
            if (data?.applied) {
                const s = data.summary ?? {};
                toast.push(t('supplierTerms.transfer.applied', undefined, { created: s.created ?? 0, updated: s.updated ?? 0, unchanged: s.unchanged ?? 0 }), 'success');
                ['item-suppliers', 'supplier-items', 'vendor-comparison', 'purchase-history', 'item'].forEach((key) => qc.invalidateQueries({ queryKey: [key] }));
                onClose();
            } else {
                // The server re-validated and found errors: nothing was saved.
                setPreview(data ?? null);
                setProblem(t('supplierTerms.transfer.notApplied'));
            }
        } catch (err) {
            if (err?.status === 422) setProblem(err?.payload?.errors?.content?.[0] ?? err.message);
            else if ([402, 403].includes(err?.status)) setProblem(t('supplierTerms.access.saveDenied'));
            else toast.failure(err, t('supplierTerms.transfer.applyFailed'));
        } finally { setBusy(false); }
    }

    const summary = preview?.summary;
    const rows = preview?.rows ?? [];
    const errorCount = summary?.errors ?? rows.filter((r) => r.status === 'error').length;
    const canApply = Boolean(preview) && errorCount === 0 && rows.length > 0 && !busy;

    return (
        <TermsModal wide title={t('supplierTerms.transfer.importTitle')} subtitle={t('supplierTerms.transfer.importSubtitle')} onClose={onClose} busy={busy}
            actions={<>
                <button type="button" className="btn" onClick={onClose} disabled={busy}>{t('supplierTerms.common.cancel')}</button>
                {content && <button type="button" className="btn" onClick={() => runPreview()} disabled={busy}>{t('supplierTerms.transfer.recheck')}</button>}
                <button type="button" className="btn btn--primary" onClick={apply} disabled={!canApply}>{busy ? t('supplierTerms.common.working') : t('supplierTerms.transfer.apply')}</button>
            </>}>
            <label className="field">
                <span className="field-label">{t('supplierTerms.transfer.chooseFile')}</span>
                <input className="input" type="file" accept=".csv,text/csv" onChange={choose} disabled={busy} />
                <span className="field-hint">{t('supplierTerms.transfer.formatHint')}</span>
            </label>
            <details className="st-details">
                <summary>{t('supplierTerms.transfer.columns')}</summary>
                <p className="field-hint"><Num>{COLUMNS}</Num></p>
                <p className="field-hint">{t('supplierTerms.transfer.columnsHint')}</p>
            </details>
            {file && <p className="muted"><Num>{file.name}</Num></p>}
            {problem && <div className="banner banner--danger" role="alert">{problem}</div>}
            {busy && !preview && <p className="muted">{t('supplierTerms.transfer.checking')}</p>}
            {summary && <div className="st-chip-row" aria-live="polite">
                <span className="st-chip"><Num>{summary.rows}</Num> {t('supplierTerms.transfer.rows')}</span>
                <span className="st-chip is-ok"><Num>{summary.created}</Num> {t('supplierTerms.transfer.created')}</span>
                <span className="st-chip is-info"><Num>{summary.updated}</Num> {t('supplierTerms.transfer.updated')}</span>
                <span className="st-chip is-muted"><Num>{summary.unchanged}</Num> {t('supplierTerms.transfer.unchanged')}</span>
                <span className={`st-chip ${errorCount ? 'is-danger' : 'is-muted'}`}><Num>{errorCount}</Num> {t('supplierTerms.transfer.errors')}</span>
            </div>}
            {preview && errorCount > 0 && <div className="banner banner--warn">{t('supplierTerms.transfer.fixBeforeApply')}</div>}
            {preview && errorCount === 0 && rows.length > 0 && <div className="banner">{t('supplierTerms.transfer.readyToApply')}</div>}
            {preview && rows.length === 0 && <div className="banner banner--warn">{t('supplierTerms.transfer.noRows')}</div>}
            {rows.length > 0 && <div className="tbl-wrap st-import-table"><table className="data-table">
                <thead><tr>
                    <th>{t('supplierTerms.transfer.row')}</th><th>{t('supplierTerms.transfer.itemSku')}</th><th>{t('supplierTerms.transfer.supplierCode')}</th>
                    <th className="num">{t('supplierTerms.tiers.minimumQty')}</th><th className="num">{t('supplierTerms.tiers.unitCost')}</th>
                    <th>{t('supplierTerms.common.currency')}</th><th>{t('supplierTerms.common.status')}</th><th>{t('supplierTerms.transfer.message')}</th>
                </tr></thead>
                <tbody>{rows.map((row) => <tr key={row.row} className={row.status === 'error' ? 'row--error' : ''}>
                    <td><Num>{row.row}</Num></td>
                    <td><Num>{row.values?.item_sku || '—'}</Num></td>
                    <td><Num>{row.values?.supplier_code || '—'}</Num></td>
                    <td className="num"><Num>{row.values?.min_qty || '—'}</Num></td>
                    <td className="num"><Num>{row.values?.unit_price || '—'}</Num></td>
                    <td><Num>{row.values?.currency_code || '—'}</Num></td>
                    <td><Badge tone={STATUS_TONES[row.status] ?? 'muted'}>{t(`supplierTerms.transfer.status.${row.status}`, row.status)}</Badge></td>
                    <td>{row.message ?? ''}</td>
                </tr>)}</tbody>
            </table></div>}
        </TermsModal>
    );
}
