import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';

export default function ScannerPage() {
    const [code, setCode] = useState('');
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const inputRef = useRef(null);

    useEffect(() => { inputRef.current?.focus(); }, []);

    async function lookup(e) {
        e.preventDefault();
        if (!code.trim()) return;
        setBusy(true); setError('');
        try {
            const res = await api.scannerLookup(code.trim());
            setResult(res.data);
            setCode('');
            setTimeout(() => inputRef.current?.focus(), 0);
        } catch (err) {
            setResult(null);
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Scanner' }]} />
            <header className="page-head"><h1>Scanner</h1></header>
            <form className="panel scan-workbench" onSubmit={lookup}>
                <input ref={inputRef} className="input input--scan" value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="Scan barcode, bin label, shipment number or tracking number"
                    aria-label="Scanner lookup" />
                <button className="btn btn--primary" disabled={busy}>{busy ? 'Looking up...' : 'Lookup'}</button>
            </form>
            {error && <div className="panel"><EmptyState title="No match" hint={error} /></div>}
            {result && <div className="panel">
                <h3>{result.type === 'item' ? 'Item' : result.type === 'bin' ? 'Bin' : 'Shipment'}</h3>
                {result.type === 'item' && <dl className="kv">
                    <dt>Item</dt><dd><Link to={`/items/${result.item.id}`}>{result.item.name}</Link></dd>
                    <dt>SKU</dt><dd>{result.item.sku}</dd>
                    <dt>Tracking</dt><dd>{result.item.tracking_type}</dd>
                    <dt>Barcode type</dt><dd>{result.barcode.type}</dd>
                </dl>}
                {result.type === 'bin' && <dl className="kv">
                    <dt>Bin</dt><dd><Link to={`/warehouses/${result.bin.warehouse_id}`}>{result.bin.code}</Link></dd>
                    <dt>Warehouse</dt><dd>{result.bin.warehouse_name} · {result.bin.warehouse_code}</dd>
                    <dt>Type</dt><dd>{result.bin.bin_type}</dd>
                    <dt>Barcode</dt><dd>{result.bin.barcode ?? result.code}</dd>
                </dl>}
                {result.type === 'shipment' && <dl className="kv">
                    <dt>Shipment</dt><dd><Link to={`/shipments/${result.shipment.id}`}>{result.shipment.shipment_number}</Link></dd>
                    <dt>Carrier</dt><dd>{result.shipment.carrier ?? '—'} {result.shipment.carrier_service && <span className="muted">· {result.shipment.carrier_service}</span>}</dd>
                    <dt>Tracking</dt><dd>{result.shipment.tracking_number ?? '—'}</dd>
                    <dt>Status</dt><dd>{result.shipment.tracking_status ?? result.shipment.status}</dd>
                </dl>}
            </div>}
        </section>
    );
}
