import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function TransfersPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['transfers'], () => api.transfers({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Transfers' }]} />
            <header className="page-head"><h1>Stock Transfers</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/transfers/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New transfer</Link></header>
            <p className="muted">Posting moves stock OUT of source and IN to destination via StockTransferService → the canonical ledger.</p>
            {rows.length === 0 ? <EmptyState title="No transfers" hint="Move stock between warehouses." /> : (
                <table className="data-table"><thead><tr><th>Transfer #</th><th>From</th><th>To</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>{rows.map((t) => (<tr key={t.id}><td><Link to={`/transfers/${t.id}`}>{t.transfer_number}</Link></td><td>#{t.from_warehouse_id}</td><td>#{t.to_warehouse_id}</td><td>{t.transfer_date}</td><td><DocumentStatusBadge status={t.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
