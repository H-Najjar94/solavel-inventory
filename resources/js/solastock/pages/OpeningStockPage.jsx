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

export default function OpeningStockPage() {
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
            toast.push('Choose a warehouse before importing.', 'error');
            return;
        }
        setBusy(true);
        try {
            const res = await api.importOpeningStock(file, { warehouse_id: importState.warehouse_id, post: importState.post ? '1' : '0' });
            await qc.invalidateQueries({ queryKey: ['opening'] });
            toast.push(`Imported ${res.data?.line_count ?? 0} rows${importState.post ? ' and posted stock.' : '.'}`, 'success');
        } catch (err) {
            toast.push(err.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Opening Stock' }]} />
            <header className="page-head"><h1>Opening Stock</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/opening-stock/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New entry</Link></header>
            <p className="muted">Posting/reversal delegate to OpeningStockService → the canonical stock ledger. Posted entries are immutable.</p>
            {gate.allowed && <div className="panel">
                <h2>Bulk import</h2>
                <div className="fg2">
                    <Field label="Warehouse"><WarehousePicker value={importState.warehouse_id} onChange={(v) => setImportState({ ...importState, warehouse_id: v })} /></Field>
                    <Field label="Posting"><label className="check-inline"><input type="checkbox" checked={importState.post} onChange={(e) => setImportState({ ...importState, post: e.target.checked })} /> Post after import</label></Field>
                </div>
                <input ref={fileRef} type="file" accept=".csv,text/csv" hidden onChange={importCsv} />
                <button className="btn btn--primary" disabled={busy || !importState.warehouse_id} onClick={() => fileRef.current?.click()}>{busy ? 'Importing...' : 'Import CSV'}</button>
                <p className="muted">CSV columns: sku, name, quantity, unit_cost, optional lot_code, expiry_date, bin_id.</p>
            </div>}
            {rows.length === 0 ? <EmptyState title="No opening-stock entries" hint="Create one to set starting quantities." /> : (
                <table className="data-table"><thead><tr><th>Number</th><th>Date</th><th>WH</th><th>Status</th><th>Value</th></tr></thead>
                <tbody>{rows.map((e) => (<tr key={e.id}><td><Link to={`/opening-stock/${e.id}`}>{e.entry_number}</Link></td><td>{e.opening_date}</td><td>{e.warehouse_name ?? `#${e.warehouse_id}`}</td><td><DocumentStatusBadge status={e.status} /></td><td>{e.total_value}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
