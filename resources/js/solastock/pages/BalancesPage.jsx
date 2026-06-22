import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockBalances } from '../services/mockData.js';

export default function BalancesPage() {
    const { data, isMock } = useApiQuery(['balances'], () => api.balances({ per_page: 50 }), { fallback: mockBalances });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockBalances);
    return (
        <section className="page">
            <header className="page-head"><h1>Current Stock</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <table className="data-table"><thead><tr><th>Item</th><th>Warehouse</th><th>On hand</th><th>Reserved</th><th>Available</th><th>Avg cost</th><th>Value</th></tr></thead>
            <tbody>{rows.length === 0 ? <tr><td colSpan="7" className="muted">No stock balances.</td></tr> :
              rows.map((b) => (<tr key={b.id}><td>#{b.item_id}</td><td>#{b.warehouse_id}</td><td>{b.on_hand_qty}</td><td>{b.reserved_qty}</td><td>{b.available_qty}</td><td>{b.average_cost}</td><td>{b.total_value}</td></tr>))}</tbody></table>
        </section>
    );
}
