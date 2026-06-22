import React, { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, FulfillmentProgressStepper, ReservationStatusBadge, PickPackShipTimeline } from '../components/document.jsx';

export default function SalesOrderDetailPage() {
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const canSO = useCanCreate('inventory.manage_sales_orders');
    const canRes = useCanCreate('inventory.manage_reservations');
    const canPick = useCanCreate('inventory.manage_picking');
    const canShip = useCanCreate('inventory.manage_shipments');
    const [tab, setTab] = useState('lines');
    const [busy, setBusy] = useState(false);

    const { data, isLoading, isMock } = useApiQuery(['sales-order', id], () => api.salesOrder(id), { fallback: null });
    const so = data?.sales_order;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!so) return <section className="page"><Breadcrumbs items={[{ label: 'Sales Orders', to: '/sales-orders' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    const lines = so.lines ?? [];
    const sum = (k) => lines.reduce((a, l) => a + Number(l[k] ?? 0), 0);

    async function act(fn, msg) {
        setBusy(true);
        try { await fn(); toast.push(msg, 'success'); qc.invalidateQueries({ queryKey: ['sales-order', id] }); qc.invalidateQueries({ queryKey: ['sales-orders'] }); }
        catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function createPickList() {
        setBusy(true);
        try {
            const res = await api.createPickList({ sales_order_id: so.id, pick_number: `PICK-${so.order_number}`, warehouse_id: so.warehouse_id });
            toast.push('Pick list created.', 'success');
            nav(`/pick-lists/${res?.data?.id}`);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function createShipment() {
        setBusy(true);
        try {
            const draft = await api.shipmentDraftFromSo(so.id);
            const draftLines = draft?.data?.lines ?? [];
            if (draftLines.length === 0) { toast.push('Nothing left to ship on this order.', 'error'); setBusy(false); return; }
            const res = await api.createShipment({
                shipment_number: `SHIP-${so.order_number}`, sales_order_id: so.id,
                warehouse_id: so.warehouse_id, lines: draftLines,
            });
            toast.push('Draft shipment created — review then post to ship.', 'success');
            nav(`/shipments/${res?.data?.id}`);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    const isDraft = so.status === 'draft';
    const isConfirmed = ['confirmed', 'partially_reserved'].includes(so.status);
    const isReserved = ['reserved', 'partially_reserved'].includes(so.status);
    const canShipNow = ['reserved', 'partially_reserved', 'picked', 'partially_picked', 'packed', 'packing', 'partially_shipped'].includes(so.status);
    const closed = ['shipped', 'cancelled'].includes(so.status);

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Sales Orders', to: '/sales-orders' }, { label: so.order_number }]} />
            <header className="page-head">
                <h1>{so.order_number}</h1><DocumentStatusBadge status={so.status} />
                {isMock && <span className="badge badge--warn">sample data</span>}
                {isDraft && <Link to={`/sales-orders/${id}/edit`} className="btn" style={{ marginLeft: 'auto', opacity: canSO.allowed ? 1 : 0.5, pointerEvents: canSO.allowed ? 'auto' : 'none' }}>Edit</Link>}
            </header>

            <div className="panel"><FulfillmentProgressStepper status={so.status} /></div>

            <div className="panel"><dl className="kv">
                <dt>Customer</dt><dd>{so.customer_name ?? '—'}</dd>
                <dt>Order date</dt><dd>{so.order_date}</dd>
                <dt>Requested ship date</dt><dd>{so.requested_ship_date ?? '—'}</dd>
                <dt>Warehouse</dt><dd>#{so.warehouse_id}</dd>
                <dt>Reservation</dt><dd><ReservationStatusBadge reserved={sum('reserved_qty')} ordered={sum('ordered_qty')} /></dd>
                <dt>Progress</dt><dd><PickPackShipTimeline picked={sum('picked_qty')} packed={sum('packed_qty')} shipped={sum('shipped_qty')} ordered={sum('ordered_qty')} /></dd>
                <dt>Notes</dt><dd>{so.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }]} active={tab} onChange={setTab} />
            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Ordered</th><th>Reserved</th><th>Picked</th><th>Packed</th><th>Shipped</th><th>Unit price</th></tr></thead>
                <tbody>{lines.map((l) => <tr key={l.id}><td>#{l.item_id}</td><td>{l.ordered_qty}</td><td>{l.reserved_qty}</td><td>{l.picked_qty}</td><td>{l.packed_qty}</td><td>{l.shipped_qty}</td><td>{l.unit_price}</td></tr>)}</tbody>
            </table></div>}

            <div className="doc-actions">
                {isDraft && <button className="btn btn--primary" disabled={!canSO.allowed || busy} onClick={() => act(() => api.confirmSalesOrder(id), 'Sales order confirmed.')}>Confirm</button>}
                {isConfirmed && <button className="btn btn--primary" disabled={!canRes.allowed || busy} onClick={() => act(() => api.reserveSalesOrder(id), 'Stock reserved.')}>Reserve stock</button>}
                {isReserved && <button className="btn" disabled={!canRes.allowed || busy} onClick={() => act(() => api.releaseSalesOrderReservation(id), 'Reservation released.')}>Release reservation</button>}
                {isReserved && <button className="btn" disabled={!canPick.allowed || busy} onClick={createPickList}>Create pick list</button>}
                {canShipNow && <button className="btn btn--primary" disabled={!canShip.allowed || busy} onClick={createShipment}>Create shipment</button>}
                {!isDraft && !closed && <button className="btn btn--danger" disabled={!canSO.allowed || busy} onClick={() => act(() => api.cancelSalesOrder(id), 'Sales order cancelled.')}>Cancel order</button>}
            </div>
            <p className="muted">Shipping posts stock OUT through the canonical ledger and records a <code>shipment.posted</code> outbox event for SolaBooks. No invoice or journal entry is created here.</p>
        </section>
    );
}
