import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function PickListsPage() {
    const { data, isMock } = useApiQuery(['pick-lists'], () => api.pickLists({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Picking' }]} />
            <header className="page-head"><h1>Pick Lists</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <p className="muted">Pick lists are generated from reserved sales orders. Picking stages stock; the stock OUT happens at shipment.</p>
            {rows.length === 0 ? <EmptyState title="No pick lists" hint="Create a pick list from a reserved sales order." /> : (
                <table className="data-table"><thead><tr><th>Pick #</th><th>Sales order</th><th>Warehouse</th><th>Status</th></tr></thead>
                <tbody>{rows.map((p) => (<tr key={p.id}><td><Link to={`/pick-lists/${p.id}`}>{p.pick_number}</Link></td><td>#{p.sales_order_id}</td><td>#{p.warehouse_id}</td><td><DocumentStatusBadge status={p.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
