import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function ShipmentsPage() {
    const { data, isMock } = useApiQuery(['shipments'], () => api.shipments({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Shipments' }]} />
            <header className="page-head"><h1>Shipments</h1>{isMock && <span className="badge badge--warn">sample data</span>}</header>
            <p className="muted">Posting a shipment writes stock OUT through the canonical ledger and records a <code>shipment.posted</code> outbox event. No invoice/journal entry is created.</p>
            {rows.length === 0 ? <EmptyState title="No shipments" hint="Create a shipment from a reserved sales order." /> : (
                <table className="data-table"><thead><tr><th>Shipment #</th><th>Sales order</th><th>Date</th><th>Carrier</th><th>Status</th></tr></thead>
                <tbody>{rows.map((s) => (<tr key={s.id}><td><Link to={`/shipments/${s.id}`}>{s.shipment_number}</Link></td><td>#{s.sales_order_id}</td><td>{s.ship_date}</td><td>{s.carrier ?? '—'}</td><td><DocumentStatusBadge status={s.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
