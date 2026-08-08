import React, { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, FulfillmentProgressStepper, ReservationStatusBadge, PickPackShipTimeline } from '../components/document.jsx';
import { SerialSelector } from '../components/traceability.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SalesOrderDetailPage() {
    const { t } = useI18n();
    const { id } = useParams();
    const nav = useNavigate(); const toast = useToast(); const qc = useQueryClient();
    const canSO = useCanCreate('inventory.manage_sales_orders');
    const canRes = useCanCreate('inventory.manage_reservations');
    const canPick = useCanCreate('inventory.manage_picking');
    const canShip = useCanCreate('inventory.manage_shipments');
    const [tab, setTab] = useState('lines');
    const [busy, setBusy] = useState(false);
    const [reservationOptions, setReservationOptions] = useState({ expires_at: '', priority: '100' });
    const [serialSelections, setSerialSelections] = useState({});

    const { data, isLoading, isMock } = useApiQuery(['sales-order', id], () => api.salesOrder(id), { fallback: null });
    const so = data?.sales_order;

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!so) return <section className="page"><Breadcrumbs items={[{ label: t('salesOrders.common.title', 'Sales Orders'), to: '/sales-orders' }, { label: t('salesOrders.common.notFound', 'Not found') }]} /><EmptyState title={t('salesOrders.common.unavailable', 'Unavailable')} hint={t('salesOrders.common.selectOrganization', 'Select an organization to load real data.')} /></section>;

    const lines = so.lines ?? [];
    const reservations = so.reservations ?? [];
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
            toast.push(t('salesOrders.messages.pickListCreated', 'Pick list created.'), 'success');
            nav(`/pick-lists/${res?.data?.id}`);
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setBusy(false); }
    }

    async function createShipment() {
        setBusy(true);
        try {
            const draft = await api.shipmentDraftFromSo(so.id);
            const draftLines = draft?.data?.lines ?? [];
            if (draftLines.length === 0) { toast.push(t('salesOrders.messages.nothingToShip', 'Nothing remains to ship for this order.'), 'error'); setBusy(false); return; }
            const res = await api.createShipment({
                shipment_number: `SHIP-${so.order_number}`, sales_order_id: so.id,
                warehouse_id: so.warehouse_id, carrier: 'SolaShip', carrier_service: 'standard',
                ship_to: { name: so.customer_name ?? '', address: '', city: '', country: '' },
                package_weight: draftLines.reduce((sum, l) => sum + Number(l.quantity || 0), 0),
                warranty_months: 12,
                lines: draftLines,
            });
            toast.push(t('salesOrders.messages.shipmentCreated', 'Draft shipment created — review it, then post it to ship.'), 'success');
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
            <Breadcrumbs items={[{ label: t('salesOrders.common.title', 'Sales Orders'), to: '/sales-orders' }, { label: so.order_number }]} />
            <header className="page-head">
                <h1>{so.order_number}</h1><DocumentStatusBadge status={so.status} />
                {isMock && <span className="badge badge--warn">{t('salesOrders.common.sampleData', 'Sample data')}</span>}
                {isDraft && <Link to={`/sales-orders/${id}/edit`} className="btn" style={{ marginInlineStart: 'auto', opacity: canSO.allowed ? 1 : 0.5, pointerEvents: canSO.allowed ? 'auto' : 'none' }}>{t('salesOrders.common.edit', 'Edit')}</Link>}
            </header>

            <div className="panel"><FulfillmentProgressStepper status={so.status} /></div>

            <div className="panel"><dl className="kv">
                <dt>{t('salesOrders.common.customer', 'Customer')}</dt><dd>{so.customer_name ?? '—'}</dd>
                <dt>{t('salesOrders.detail.subtotal', 'Subtotal')}</dt><dd>{so.subtotal ?? '0.00'}</dd>
                <dt>{t('salesOrders.common.discount', 'Discount')}</dt><dd>{so.discount_total ?? '0.00'}</dd>
                <dt>{t('salesOrders.common.tax', 'Tax')}</dt><dd>{so.tax_total ?? '0.00'}</dd>
                <dt>{t('salesOrders.common.total', 'Total')}</dt><dd>{so.total ?? '0.00'}</dd>
                <dt>{t('salesOrders.detail.orderDate', 'Order date')}</dt><dd>{so.order_date}</dd>
                <dt>{t('salesOrders.detail.requestedShipDate', 'Requested ship date')}</dt><dd>{so.requested_ship_date ?? '—'}</dd>
                <dt>{t('salesOrders.common.warehouse', 'Warehouse')}</dt><dd>{so.warehouse_name ?? `#${so.warehouse_id}`}</dd>
                <dt>{t('salesOrders.detail.reservation', 'Reservation')}</dt><dd><ReservationStatusBadge reserved={sum('reserved_qty')} ordered={sum('ordered_qty')} /></dd>
                <dt>{t('salesOrders.detail.progress', 'Progress')}</dt><dd><PickPackShipTimeline picked={sum('picked_qty')} packed={sum('packed_qty')} shipped={sum('shipped_qty')} ordered={sum('ordered_qty')} /></dd>
                <dt>{t('salesOrders.common.notes', 'Notes')}</dt><dd>{so.notes ?? '—'}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: t('salesOrders.common.lines', 'Lines') }, { key: 'reservations', label: t('salesOrders.detail.reservationsTab', 'Reservations') }]} active={tab} onChange={setTab} />
            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>{t('salesOrders.common.item', 'Item')}</th><th>{t('salesOrders.detail.ordered', 'Ordered')}</th><th>{t('salesOrders.detail.reserved', 'Reserved')}</th><th>{t('salesOrders.detail.picked', 'Picked')}</th><th>{t('salesOrders.detail.packed', 'Packed')}</th><th>{t('salesOrders.detail.shipped', 'Shipped')}</th><th>{t('salesOrders.detail.unitPrice', 'Unit price')}</th><th>{t('salesOrders.common.discount', 'Discount')}</th><th>{t('salesOrders.common.tax', 'Tax')}</th><th>{t('salesOrders.common.total', 'Total')}</th></tr></thead>
                <tbody>{lines.map((l) => <tr key={l.id}><td>{l.item?.name ?? `#${l.item_id}`}{l.item?.sku && <span className="muted"> · {l.item.sku}</span>}</td><td>{l.ordered_qty}</td><td>{l.reserved_qty}</td><td>{l.picked_qty}</td><td>{l.packed_qty}</td><td>{l.shipped_qty}</td><td>{l.unit_price}</td><td>{l.discount_amount ?? '0.00'}</td><td>{l.tax_amount ?? '0.00'}</td><td>{l.line_total ?? '0.00'}</td></tr>)}</tbody>
            </table></div>}
            {tab === 'reservations' && <div className="panel">
                <div className="form-grid">
                    <label className="field"><span>{t('salesOrders.detail.expiresAt', 'Expires at')}</span><input className="input" type="datetime-local" value={reservationOptions.expires_at} onChange={(e) => setReservationOptions({ ...reservationOptions, expires_at: e.target.value })} /></label>
                    <label className="field"><span>{t('salesOrders.detail.priority', 'Priority')}</span><input className="input" type="number" min="1" max="999" value={reservationOptions.priority} onChange={(e) => setReservationOptions({ ...reservationOptions, priority: e.target.value })} /></label>
                </div>
                {lines.filter((line) => ['serial', 'lot_serial'].includes(line.item?.tracking_type)).map((line) => (
                    <div key={line.id} className="panel panel--subtle">
                        <strong><bdi dir="ltr">{line.item?.sku}</bdi> · {t('salesOrders.detail.selectSerials', 'Select :count serial number(s)', { count: Number(line.ordered_qty) - Number(line.reserved_qty) })}</strong>
                        <SerialSelector
                            itemId={line.item_id}
                            warehouseId={line.warehouse_id ?? so.warehouse_id}
                            value={serialSelections[line.id] ?? []}
                            expectedQty={Number(line.ordered_qty) - Number(line.reserved_qty)}
                            onChange={(ids) => setSerialSelections((current) => ({ ...current, [line.id]: ids }))}
                        />
                    </div>
                ))}
                {reservations.length === 0 ? <EmptyState title={t('salesOrders.detail.noReservations', 'No reservations')} hint={t('salesOrders.detail.noReservationsHint', 'Confirmed orders can reserve available stock.')} /> : (
                    <table className="data-table">
                        <thead><tr><th>{t('salesOrders.common.item', 'Item')}</th><th>{t('salesOrders.common.warehouse', 'Warehouse')}</th><th>{t('salesOrders.detail.quantity', 'Quantity')}</th><th>{t('salesOrders.detail.priority', 'Priority')}</th><th>{t('salesOrders.detail.expires', 'Expires')}</th><th>{t('salesOrders.common.status', 'Status')}</th></tr></thead>
                        <tbody>{reservations.map((r) => <tr key={r.id}>
                            <td>{r.item?.name ?? `#${r.item_id}`}{r.item?.sku && <span className="muted"> · {r.item.sku}</span>}</td>
                            <td>{r.warehouse?.name ?? `#${r.warehouse_id}`}</td>
                            <td>{r.qty}{r.serial?.serial ? ` · ${r.serial.serial}` : ''}</td><td>{r.priority ?? 100}</td><td>{r.expires_at ?? '—'}</td>
                            <td>{r.expired_at ? t('salesOrders.detail.expired', 'Expired') : t(`salesOrders.reservationStatus.${r.status}`, r.status)}</td>
                        </tr>)}</tbody>
                    </table>
                )}
            </div>}

            <div className="doc-actions">
                {isDraft && <button className="btn btn--primary" disabled={!canSO.allowed || busy} onClick={() => act(() => api.confirmSalesOrder(id), t('salesOrders.messages.confirmed', 'Sales order confirmed.'))}>{t('salesOrders.actions.confirm', 'Confirm')}</button>}
                {isConfirmed && <button className="btn btn--primary" disabled={!canRes.allowed || busy} onClick={() => act(() => api.reserveSalesOrder(id, { ...reservationOptions, serial_ids: serialSelections }), t('salesOrders.messages.stockReserved', 'Stock reserved.'))}>{t('salesOrders.actions.reserveStock', 'Reserve stock')}</button>}
                {isReserved && <button className="btn" disabled={!canRes.allowed || busy} onClick={() => act(() => api.releaseSalesOrderReservation(id), t('salesOrders.messages.reservationReleased', 'Reservation released.'))}>{t('salesOrders.actions.releaseReservation', 'Release reservation')}</button>}
                {isReserved && <button className="btn" disabled={!canPick.allowed || busy} onClick={createPickList}>{t('salesOrders.actions.createPickList', 'Create pick list')}</button>}
                {canShipNow && <button className="btn btn--primary" disabled={!canShip.allowed || busy} onClick={createShipment}>{t('salesOrders.actions.createShipment', 'Create shipment')}</button>}
                {!isDraft && !closed && <button className="btn btn--danger" disabled={!canSO.allowed || busy} onClick={() => act(() => api.cancelSalesOrder(id), t('salesOrders.messages.cancelled', 'Sales order cancelled.'))}>{t('salesOrders.actions.cancelOrder', 'Cancel order')}</button>}
            </div>
            <p className="muted">{t('salesOrders.detail.shippingNoticeBeforeEvent', 'Shipping posts stock OUT through the canonical ledger and records a')} <code dir="ltr">{'shipment.posted'}</code> {t('salesOrders.detail.shippingNoticeAfterEvent', 'outbox event for SolaCount. No invoice or journal entry is created here.')}</p>
        </section>
    );
}
