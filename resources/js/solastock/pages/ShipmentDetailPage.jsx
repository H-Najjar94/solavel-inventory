import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { useToast } from '../stores/toast.jsx';
import { Breadcrumbs, Skeleton, Tabs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge, LedgerPreview, ConfirmPostModal } from '../components/document.jsx';
import { LotSelector, SerialSelector, FefoHint, TraceabilityRequiredBadge } from '../components/traceability.jsx';
import { useItemTracking } from '../hooks/useItemTracking.js';

export default function ShipmentDetailPage() {
    const { id } = useParams();
    const toast = useToast(); const qc = useQueryClient();
    const gate = useCanCreate('inventory.manage_shipments');
    const canReturn = useCanCreate('inventory.manage_returns');
    const canOverrideExpired = useCanCreate('inventory.override_expired_lot');
    const canOverrideQuarantine = useCanCreate('inventory.override_quarantine');
    const nav = useNavigate();
    const [tab, setTab] = useState('lines');
    const [confirmPost, setConfirmPost] = useState(false);
    const [picks, setPicks] = useState({}); // lineId -> {lot_id, serial_ids}
    const [overrides, setOverrides] = useState({ allow_expired_lot: false, allow_quarantined_lot: false });
    const [savingPicks, setSavingPicks] = useState(false);
    const [shipMeta, setShipMeta] = useState({});
    const [label, setLabel] = useState(null);

    const { data, isLoading, isMock } = useApiQuery(['shipment', id], () => api.shipment(id), { fallback: null });
    const s = data?.shipment;
    const ledger = data?.ledger ?? [];
    const tracking = useItemTracking();
    const { data: ratesData } = useApiQuery(['shipment-rates', id], () => api.shipmentRates(id), { fallback: null, enabled: !!id });
    const { data: trackingData } = useApiQuery(['shipment-tracking', id], () => api.shipmentTracking(id), { fallback: null, enabled: !!id });
    const rates = ratesData?.rates ?? [];
    const carrierTracking = trackingData?.tracking ?? null;

    useEffect(() => {
        if (s?.lines) {
            setPicks(Object.fromEntries(s.lines.map((l) => [l.id, { lot_id: l.lot_id ?? null, serial_ids: l.serial_id ? [l.serial_id] : [] }])));
            setShipMeta({
                carrier: s.carrier ?? 'SolaShip',
                carrier_service: s.carrier_service ?? 'standard',
                tracking_number: s.tracking_number ?? '',
                package_weight: s.package_weight ?? '',
                package_length: s.package_length ?? '',
                package_width: s.package_width ?? '',
                package_height: s.package_height ?? '',
                warranty_months: s.warranty_months ?? 12,
                ship_to: s.ship_to ?? { name: '', address: '', city: '', country: '' },
            });
            setLabel(s.label_payload ? { label: s.label_payload } : null);
        }
    }, [s]);

    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (!s) return <section className="page"><Breadcrumbs items={[{ label: 'Shipments', to: '/shipments' }, { label: 'Not found' }]} /><EmptyState title="Unavailable" hint="Select a tenant to load real data." /></section>;

    const isDraft = s.status === 'draft';
    const lines = s.lines ?? [];

    // Capture completeness gate: every tracked line must have its lot/serial chosen.
    const captureComplete = lines.every((l) => {
        const p = picks[l.id] ?? {};
        if (tracking.tracksSerial(l.item_id)) return (p.serial_ids ?? []).length === Number(l.quantity);
        if (tracking.tracksLot(l.item_id)) return !!p.lot_id;
        return true;
    });

    async function savePicks() {
        setSavingPicks(true);
        try {
            const payload = {
                shipment_number: s.shipment_number, sales_order_id: s.sales_order_id, warehouse_id: s.warehouse_id,
                ship_date: s.ship_date,
                carrier: shipMeta.carrier, carrier_service: shipMeta.carrier_service,
                tracking_number: shipMeta.tracking_number,
                ship_to: shipMeta.ship_to,
                package_weight: shipMeta.package_weight || undefined,
                package_length: shipMeta.package_length || undefined,
                package_width: shipMeta.package_width || undefined,
                package_height: shipMeta.package_height || undefined,
                warranty_months: shipMeta.warranty_months || undefined,
                lines: lines.map((l) => {
                    const p = picks[l.id] ?? {};
                    const serial = tracking.tracksSerial(l.item_id) && (p.serial_ids ?? []).length > 0;
                    return {
                        sales_order_line_id: l.sales_order_line_id, item_id: l.item_id, warehouse_id: l.warehouse_id, bin_id: l.bin_id,
                        quantity: serial ? String(p.serial_ids.length) : l.quantity,
                        lot_id: p.lot_id || undefined,
                        serial_id: serial ? p.serial_ids[0] : undefined,
                    };
                }),
            };
            await api.updateShipment(id, payload);
            toast.push('Lot/serial selection saved.', 'success');
            qc.invalidateQueries({ queryKey: ['shipment', id] });
        } catch (e) { toast.push(e.message, 'error'); }
        finally { setSavingPicks(false); }
    }

    async function saveShipping() {
        try {
            await api.updateShipment(id, {
                shipment_number: s.shipment_number, sales_order_id: s.sales_order_id, warehouse_id: s.warehouse_id,
                ship_date: s.ship_date,
                ...shipMeta,
                lines: lines.map((l) => ({
                    sales_order_line_id: l.sales_order_line_id, item_id: l.item_id, warehouse_id: l.warehouse_id, bin_id: l.bin_id,
                    quantity: l.quantity, lot_id: l.lot_id || undefined, serial_id: l.serial_id || undefined,
                })),
            });
            toast.push('Shipping details saved.', 'success');
            qc.invalidateQueries({ queryKey: ['shipment', id] });
            qc.invalidateQueries({ queryKey: ['shipment-rates', id] });
        } catch (e) { toast.push(e.message, 'error'); }
    }

    async function generateLabel(serviceCode = null) {
        try {
            const res = await api.shipmentLabel(id, { service_code: serviceCode || shipMeta.carrier_service });
            setLabel(res.data);
            toast.push('Shipping label generated.', 'success');
            qc.invalidateQueries({ queryKey: ['shipment', id] });
            qc.invalidateQueries({ queryKey: ['shipment-tracking', id] });
        } catch (e) { toast.push(e.message, 'error'); }
    }

    async function post() {
        try { await api.postShipment(id, overrides); toast.push('Shipment posted — stock shipped OUT.', 'success'); qc.invalidateQueries({ queryKey: ['shipment', id] }); }
        catch (e) { toast.push(e.message, 'error'); }
    }

    const setPick = (lineId, patch) => setPicks((p) => ({ ...p, [lineId]: { ...(p[lineId] ?? {}), ...patch } }));

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Shipments', to: '/shipments' }, { label: s.shipment_number }]} />
            <header className="page-head"><h1>{s.shipment_number}</h1><DocumentStatusBadge status={s.status} />{isMock && <span className="badge badge--warn">sample data</span>}</header>

            <div className="panel"><dl className="kv">
                <dt>Sales order</dt><dd><Link to={`/sales-orders/${s.sales_order_id}`}>#{s.sales_order_id}</Link></dd>
                <dt>Ship date</dt><dd>{s.ship_date}</dd>
                <dt>Warehouse</dt><dd>#{s.warehouse_id}</dd>
                <dt>Carrier</dt><dd>{s.carrier ?? '—'} {s.carrier_service && <span className="muted">· {s.carrier_service}</span>}</dd>
                <dt>Tracking #</dt><dd>{s.tracking_number ?? '—'}</dd>
                <dt>Label</dt><dd>{s.label_number ?? '—'} {s.label_status && <span className="badge badge--demo">{s.label_status}</span>}</dd>
            </dl></div>

            <Tabs tabs={[{ key: 'lines', label: 'Lines' }, { key: 'shipping', label: 'Carrier' }, { key: 'tracking', label: 'Tracking' }, { key: 'ledger', label: 'Ledger result' }]} active={tab} onChange={setTab} />
            {tab === 'lines' && <div className="panel"><table className="data-table">
                <thead><tr><th>Item</th><th>Ship qty</th><th>Lot / Serial</th><th>Selected</th></tr></thead>
                <tbody>{lines.map((l) => {
                    const p = picks[l.id] ?? {};
                    const t = tracking.trackingOf(l.item_id);
                    const selCount = tracking.tracksSerial(l.item_id) ? (p.serial_ids ?? []).length : (p.lot_id ? 1 : 0);
                    return (
                        <tr key={l.id}>
                            <td>#{l.item_id} <TraceabilityRequiredBadge trackingType={t.tracking_type} tracksExpiry={t.tracks_expiry} /></td>
                            <td>{l.quantity}</td>
                            <td>
                                {(!t.tracking_type || t.tracking_type === 'none') && <span className="muted">—</span>}
                                {isDraft ? (
                                    <div className="trace-cell">
                                        {tracking.tracksLot(l.item_id) && <>
                                            <LotSelector itemId={l.item_id} warehouseId={s.warehouse_id} value={p.lot_id} onChange={(v) => setPick(l.id, { lot_id: v })} />
                                            {tracking.tracksExpiry(l.item_id) && <FefoHint itemId={l.item_id} warehouseId={s.warehouse_id} quantity={l.quantity} selectedLotId={p.lot_id} onApply={(sg) => setPick(l.id, { lot_id: sg.lot_id })} />}
                                        </>}
                                        {tracking.tracksSerial(l.item_id) && <SerialSelector itemId={l.item_id} warehouseId={s.warehouse_id} value={p.serial_ids ?? []} expectedQty={Number(l.quantity)} onChange={(ids) => setPick(l.id, { serial_ids: ids })} />}
                                    </div>
                                ) : (
                                    <span>{l.lot_id ? `lot #${l.lot_id}` : ''}{l.serial_id ? ` serial #${l.serial_id}` : ''}{!l.lot_id && !l.serial_id ? '—' : ''}</span>
                                )}
                            </td>
                            <td>{selCount}</td>
                        </tr>
                    );
                })}</tbody>
            </table></div>}
            {tab === 'ledger' && <div className="panel"><LedgerPreview rows={ledger} /></div>}
            {tab === 'shipping' && <div className="panel">
                <div className="fg4">
                    <label className="field"><span className="field-label">Carrier</span><input className="input" value={shipMeta.carrier ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, carrier: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Service</span><select className="input" value={shipMeta.carrier_service ?? 'standard'} onChange={(e) => setShipMeta({ ...shipMeta, carrier_service: e.target.value })} disabled={!isDraft}>
                        <option value="standard">Standard ground</option><option value="express">Express</option><option value="freight">Freight</option>
                    </select></label>
                    <label className="field"><span className="field-label">Tracking #</span><input className="input" value={shipMeta.tracking_number ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, tracking_number: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Warranty months</span><input className="input" type="number" min="0" max="120" value={shipMeta.warranty_months ?? 12} onChange={(e) => setShipMeta({ ...shipMeta, warranty_months: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Weight</span><input className="input" type="number" step="0.0001" value={shipMeta.package_weight ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, package_weight: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Length</span><input className="input" type="number" step="0.0001" value={shipMeta.package_length ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, package_length: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Width</span><input className="input" type="number" step="0.0001" value={shipMeta.package_width ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, package_width: e.target.value })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Height</span><input className="input" type="number" step="0.0001" value={shipMeta.package_height ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, package_height: e.target.value })} disabled={!isDraft} /></label>
                </div>
                <div className="fg2">
                    <label className="field"><span className="field-label">Ship to</span><input className="input" value={shipMeta.ship_to?.name ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, ship_to: { ...(shipMeta.ship_to ?? {}), name: e.target.value } })} disabled={!isDraft} /></label>
                    <label className="field"><span className="field-label">Address</span><input className="input" value={shipMeta.ship_to?.address ?? ''} onChange={(e) => setShipMeta({ ...shipMeta, ship_to: { ...(shipMeta.ship_to ?? {}), address: e.target.value } })} disabled={!isDraft} /></label>
                </div>
                {isDraft && <button className="btn" disabled={!gate.allowed} onClick={saveShipping}>Save shipping details</button>}
                <h3>Rates</h3>
                <div className="report-grid">
                    {rates.map((r) => <div className="widget-card" key={r.service_code}>
                        <div className="widget-card-label">{r.service_name}</div>
                        <div className="widget-card-value">{r.amount} {r.currency}</div>
                        <div className="muted">{r.estimated_days} days</div>
                        <button className="btn btn--sm" disabled={!gate.allowed} onClick={() => generateLabel(r.service_code)}>Use rate & label</button>
                    </div>)}
                </div>
                {(label?.label || s.label_payload) && <div className="label-card shipping-label">
                    <strong>{(label?.label ?? s.label_payload).carrier} {(label?.label ?? s.label_payload).service_name}</strong>
                    <span>{s.shipment_number}</span>
                    <code>{(label?.label ?? s.label_payload).tracking_number}</code>
                    <span className="qr-preview" dangerouslySetInnerHTML={{ __html: (label?.label ?? s.label_payload).qr_svg }} />
                </div>}
            </div>}
            {tab === 'tracking' && <div className="panel">
                {!carrierTracking ? <EmptyState title="No tracking yet" hint="Generate a label to create tracking." /> : <>
                    <dl className="kv"><dt>Carrier</dt><dd>{carrierTracking.carrier}</dd><dt>Tracking #</dt><dd>{carrierTracking.tracking_number}</dd><dt>Status</dt><dd>{carrierTracking.status}</dd></dl>
                    <table className="data-table"><thead><tr><th>Time</th><th>Status</th><th>Event</th></tr></thead>
                    <tbody>{(carrierTracking.events ?? []).map((e, i) => <tr key={i}><td>{e.occurred_at}</td><td>{e.status}</td><td>{e.message}</td></tr>)}</tbody></table>
                </>}
            </div>}

            {isDraft && (
                <div className="panel">
                    <h3>Override controls</h3>
                    <label className="trace-override"><input type="checkbox" disabled={!canOverrideExpired.allowed}
                        checked={overrides.allow_expired_lot} onChange={(e) => setOverrides({ ...overrides, allow_expired_lot: e.target.checked })} />
                        Allow shipping expired lots {!canOverrideExpired.allowed && <span className="muted">(no permission)</span>}</label>
                    <label className="trace-override"><input type="checkbox" disabled={!canOverrideQuarantine.allowed}
                        checked={overrides.allow_quarantined_lot} onChange={(e) => setOverrides({ ...overrides, allow_quarantined_lot: e.target.checked })} />
                        Allow shipping quarantined/recalled lots {!canOverrideQuarantine.allowed && <span className="muted">(no permission)</span>}</label>
                </div>
            )}

            <div className="doc-actions">
                {isDraft && <button className="btn" disabled={!gate.allowed || savingPicks} onClick={savePicks}>{savingPicks ? 'Saving…' : 'Save lot/serial'}</button>}
                {isDraft && <button className="btn btn--primary" disabled={!gate.allowed || !captureComplete} title={captureComplete ? '' : 'Complete lot/serial capture first'} onClick={() => setConfirmPost(true)}>Post shipment</button>}
                {s.reversed_at && <span className="badge badge--demo">Reversed by return #{s.reversal_sales_return_id}</span>}
                {s.status === 'posted' && !s.reversed_at && <button className="btn" disabled={!canReturn.allowed} onClick={() => nav(`/sales-returns/new?shipment_id=${s.id}`)}>Create source reversal</button>}
            </div>
            {isDraft && !captureComplete && <p className="muted">Select lot/serial for every tracked line before posting.</p>}
            <ConfirmPostModal open={confirmPost} name="shipment"
                onConfirm={() => { setConfirmPost(false); post(); }} onCancel={() => setConfirmPost(false)} />
        </section>
    );
}
