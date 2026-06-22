import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockLedger } from '../services/mockData.js';

export default function LedgerPage() {
    const { data, isMock } = useApiQuery(['ledger'], () => api.ledger({ per_page: 100 }), { fallback: mockLedger });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockLedger);
    return (
        <section className="page">
            <header className="page-head"><h1>Stock Ledger</h1>{isMock && <span className="badge badge--warn">sample data</span>}<span className="muted">read-only</span></header>
            <table className="data-table"><thead><tr><th>Date</th><th>Item</th><th>WH</th><th>Dir</th><th>Qty</th><th>Unit cost</th><th>Total</th><th>Running qty</th><th>Source</th></tr></thead>
            <tbody>{rows.length === 0 ? <tr><td colSpan="9" className="muted">No ledger entries.</td></tr> :
              rows.map((r) => (<tr key={r.id}><td>{r.moved_at}</td><td>#{r.item_id}</td><td>#{r.warehouse_id}</td><td>{r.direction}</td><td>{r.quantity}</td><td>{r.unit_cost}</td><td>{r.total_cost}</td><td>{r.balance_qty_after}</td><td>{r.source_type?.split('\\').pop()} #{r.source_id}</td></tr>))}</tbody></table>
        </section>
    );
}
