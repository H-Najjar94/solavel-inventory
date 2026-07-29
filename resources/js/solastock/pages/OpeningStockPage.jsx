import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockOpening } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Field } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { WarehousePicker } from '../components/pickers.jsx';
import { useToast } from '../stores/toast.jsx';
import { useI18n } from '../i18n/context.jsx';
import { openingStockT } from '../i18n/openingStock.js';

const requiredCsvColumns = ['sku', 'name', 'quantity', 'unit_cost'];
const optionalCsvColumns = ['lot_code', 'expiry_date', 'bin_id'];

function CsvColumnList({ columns, t }) {
    return columns.map((column, index) => (
        <React.Fragment key={column}>
            {index > 0 && '، '}
            <span>{t(`openingStock.csvColumn.${column}`)} (<code dir="ltr"><bdi>{column}</bdi></code>)</span>
        </React.Fragment>
    ));
}

function CsvColumnsHelp({ t }) {
    return (
        <div className="muted">
            <p><strong>{t('openingStock.csvRequiredColumns')}:</strong>{' '}<CsvColumnList columns={requiredCsvColumns} t={t} />.</p>
            <p><strong>{t('openingStock.csvOptionalColumns')}:</strong>{' '}<CsvColumnList columns={optionalCsvColumns} t={t} />.</p>
        </div>
    );
}

export default function OpeningStockPage() {
    const { locale } = useI18n();
    const t = (key, params) => openingStockT(locale, key, params);
    const gate = useCanCreate('inventory.manage_opening_stock');
    const toast = useToast();
    const qc = useQueryClient();
    const fileRef = useRef(null);
    const [importState, setImportState] = useState({ warehouse_id: null, post: false });
    const [busy, setBusy] = useState(false);
    const { data, isMock } = useApiQuery(['opening'], () => api.openingStock({ per_page: 50 }), { fallback: mockOpening });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockOpening);

    async function importCsv(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file || !importState.warehouse_id) {
            toast.push(t('openingStock.chooseWarehouse'), 'error');
            return;
        }
        setBusy(true);
        try {
            const res = await api.importOpeningStock(file, { warehouse_id: importState.warehouse_id, post: importState.post ? '1' : '0' });
            await qc.invalidateQueries({ queryKey: ['opening'] });
            const key = importState.post ? 'openingStock.importedPosted' : 'openingStock.importedDraft';
            toast.push(t(key, { count: res.data?.line_count ?? 0 }), 'success');
        } catch (err) {
            toast.push(err.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('openingStock.title') }]} />
            <header className="page-head"><h1>{t('openingStock.title')}</h1>{isMock && <span className="badge badge--warn">{t('openingStock.sampleData')}</span>}
                <Link to="/opening-stock/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('openingStock.newEntry')}</Link></header>
            <p className="muted">{t('openingStock.description')}</p>
            {gate.allowed && <div className="panel">
                <h2>{t('openingStock.bulkImport')}</h2>
                <div className="fg2">
                    <Field label={t('openingStock.warehouse')}><WarehousePicker value={importState.warehouse_id} onChange={(v) => setImportState({ ...importState, warehouse_id: v })} /></Field>
                    <Field label={t('openingStock.posting')}><label className="check-inline"><input type="checkbox" checked={importState.post} onChange={(e) => setImportState({ ...importState, post: e.target.checked })} /> {t('openingStock.postAfterImport')}</label></Field>
                </div>
                <input ref={fileRef} type="file" accept=".csv,text/csv" aria-label={t('openingStock.csvInput')} hidden onChange={importCsv} />
                <button className="btn btn--primary" disabled={busy || !importState.warehouse_id} onClick={() => fileRef.current?.click()}>{busy ? t('openingStock.importing') : t('openingStock.importCsv')}</button>
                <CsvColumnsHelp t={t} />
            </div>}
            {rows.length === 0 ? <EmptyState title={t('openingStock.emptyTitle')} hint={t('openingStock.emptyHint')} /> : (
                <table className="data-table"><thead><tr><th>{t('openingStock.number')}</th><th>{t('openingStock.date')}</th><th>{t('openingStock.warehouseShort')}</th><th>{t('openingStock.status')}</th><th>{t('openingStock.value')}</th></tr></thead>
                <tbody>{rows.map((e) => (<tr key={e.id}><td><Link to={`/opening-stock/${e.id}`}><bdi>{e.entry_number}</bdi></Link></td><td>{e.opening_date}</td><td>{e.warehouse_name ?? <bdi>#{e.warehouse_id}</bdi>}</td><td><DocumentStatusBadge status={e.status} /></td><td>{e.total_value}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
