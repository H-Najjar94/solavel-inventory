import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockAdjustments } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function AdjustmentsPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['adjustments'], () => api.adjustments({ per_page: 50 }), { fallback: mockAdjustments });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockAdjustments);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Adjustments' }]} />
            <header className="page-head"><h1>Stock Adjustments</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/adjustments/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New adjustment</Link></header>
            <p className="muted">Increase/decrease lines; posting/reversal delegate to StockAdjustmentService → the canonical ledger.</p>
            {rows.length === 0 ? <EmptyState title="No adjustments" hint="Create one to correct stock." /> : (
                <table className="data-table"><thead><tr><th>Number</th><th>Date</th><th>WH</th><th>Reason</th><th>Status</th><th>+ Value</th><th>− Value</th></tr></thead>
                <tbody>{rows.map((a) => (<tr key={a.id}><td><Link to={`/adjustments/${a.id}`}>{a.adjustment_number}</Link></td><td>{a.adjustment_date}</td><td>#{a.warehouse_id}</td><td>{a.reason_code ?? '—'}</td><td><DocumentStatusBadge status={a.status} /></td><td>{a.total_increase_value}</td><td>{a.total_decrease_value}</td></tr>))}</tbody></table>
            )}
        </section>
    );
}
