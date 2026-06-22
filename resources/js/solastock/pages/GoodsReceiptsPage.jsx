import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';

export default function GoodsReceiptsPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isMock } = useApiQuery(['grns'], () => api.goodsReceipts({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Goods Receipts' }]} />
            <header className="page-head"><h1>Goods Receipts</h1>{isMock && <span className="badge badge--warn">sample data</span>}
                <Link to="/goods-receipts/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>New GRN</Link></header>
            <p className="muted">Posting a GRN receives stock IN through GoodsReceiptService → the canonical ledger, and rolls up the PO.</p>
            {rows.length === 0 ? <EmptyState title="No goods receipts" hint="Create one, or receive from an approved PO." /> : (
                <table className="data-table"><thead><tr><th>GRN #</th><th>PO</th><th>WH</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>{rows.map((g) => (<tr key={g.id}><td><Link to={`/goods-receipts/${g.id}`}>{g.grn_number}</Link></td><td>{g.purchase_order_id ? `#${g.purchase_order_id}` : '—'}</td><td>#{g.warehouse_id}</td><td>{g.receipt_date}</td><td><DocumentStatusBadge status={g.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
