import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function ScannerPage() {
    const { t } = useI18n();
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
            setError(err?.code === 'scan_code_not_found'
                ? { title: t('scanner.noMatch', 'No match'), hint: t('scanner.noMatchHint', 'No item, bin, or shipment matches that scan.') }
                : { title: t('scanner.lookupError', 'Lookup failed'), hint: t('scanner.lookupFailed', 'The scan could not be looked up. Try again.') });
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('scanner.title', 'Scanner') }]} />
            <header className="page-head"><h1>{t('scanner.title', 'Scanner')}</h1></header>
            <form className="panel scan-workbench" onSubmit={lookup}>
                <input ref={inputRef} className="input input--scan" value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder={t('scanner.placeholder', 'Scan a barcode, bin label, shipment number, or tracking number')}
                    aria-label={t('scanner.lookupAria', 'Scanner lookup')} />
                <button className="btn btn--primary" disabled={busy}>{busy ? t('scanner.lookingUp', 'Looking up…') : t('scanner.lookup', 'Lookup')}</button>
            </form>
            {error && <div className="panel"><EmptyState title={error.title} hint={error.hint} /></div>}
            {result && <div className="panel">
                <h3>{t(`scanner.result.${result.type}`, result.type)}</h3>
                {result.type === 'item' && <dl className="kv">
                    <dt>{t('scanner.field.item', 'Item')}</dt><dd><Link to={`/items/${result.item.id}`}><bdi>{result.item.name}</bdi></Link></dd>
                    <dt>{t('scanner.field.sku', 'SKU')}</dt><dd><bdi>{result.item.sku}</bdi></dd>
                    <dt>{t('scanner.field.tracking', 'Tracking')}</dt><dd>{t(`scanner.tracking.${result.item.tracking_type}`, result.item.tracking_type)}</dd>
                    <dt>{t('scanner.field.barcodeType', 'Barcode type')}</dt><dd>{t(`scanner.barcode.${result.barcode.type}`, result.barcode.type)}</dd>
                </dl>}
                {result.type === 'bin' && <dl className="kv">
                    <dt>{t('scanner.field.bin', 'Bin')}</dt><dd><Link to={`/warehouses/${result.bin.warehouse_id}`}><bdi>{result.bin.code}</bdi></Link></dd>
                    <dt>{t('scanner.field.warehouse', 'Warehouse')}</dt><dd><bdi>{result.bin.warehouse_name} · {result.bin.warehouse_code}</bdi></dd>
                    <dt>{t('scanner.field.type', 'Type')}</dt><dd>{t(`scanner.bin.${result.bin.bin_type}`, result.bin.bin_type)}</dd>
                    <dt>{t('scanner.field.barcode', 'Barcode')}</dt><dd><bdi>{result.bin.barcode ?? result.code}</bdi></dd>
                </dl>}
                {result.type === 'shipment' && <dl className="kv">
                    <dt>{t('scanner.field.shipment', 'Shipment')}</dt><dd><Link to={`/shipments/${result.shipment.id}`}><bdi>{result.shipment.shipment_number}</bdi></Link></dd>
                    <dt>{t('scanner.field.carrier', 'Carrier')}</dt><dd><bdi>{result.shipment.carrier ?? '—'} {result.shipment.carrier_service && <span className="muted">· {result.shipment.carrier_service}</span>}</bdi></dd>
                    <dt>{t('scanner.field.tracking', 'Tracking')}</dt><dd><bdi>{result.shipment.tracking_number ?? '—'}</bdi></dd>
                    <dt>{t('scanner.field.status', 'Status')}</dt><dd>{t(`scanner.shipment.${result.shipment.tracking_status ?? result.shipment.status}`, result.shipment.tracking_status ?? result.shipment.status)}</dd>
                </dl>}
            </div>}
        </section>
    );
}
