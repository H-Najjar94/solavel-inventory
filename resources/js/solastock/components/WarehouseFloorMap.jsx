import React, { useState } from 'react';
import { EmptyState } from './ui.jsx';
import { t } from '../i18n/index.js';

// Simple 2D floor map: zones → bins grid. Bin color reflects occupancy status.
// Clicking a bin opens a side panel. Falls back to a visual placeholder grid when
// no real bins exist. (Deliberately not 3D yet.)
const STATUS_COLOR = {
    empty: '#e8ece4',
    occupied: '#9fd3b8',
    full: '#e09921',
    low: '#f4b94a',
    blocked: '#e05151',
};

function binStatus(bin, balances) {
    const onHand = balances
        .filter((b) => Number(b.bin_id) === Number(bin.id))
        .reduce((s, b) => s + Number(b.on_hand_qty || 0), 0);
    const cap = Number(bin.capacity || 0);
    const type = bin.coords?.bin_type;
    if (type === 'quarantine' || type === 'damaged') return 'blocked';
    if (onHand <= 0) return 'empty';
    if (cap > 0 && onHand >= cap) return 'full';
    if (cap > 0 && onHand >= cap * 0.8) return 'low';
    return 'occupied';
}

export default function WarehouseFloorMap({ zones = [], bins = [], balances = [] }) {
    const [selected, setSelected] = useState(null);
    const knownStatuses = ['empty', 'occupied', 'full', 'low', 'blocked'];
    const knownBinTypes = ['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'damaged'];
    const statusLabel = (status) => knownStatuses.includes(status)
        ? t(`warehouseDetail.floor.status.${status}`)
        : t('status.unknown', undefined, { value: status });
    const binTypeLabel = (type) => knownBinTypes.includes(type)
        ? t(`warehouseDetail.binType.${type}`)
        : t('status.unknown', undefined, { value: type });

    if (bins.length === 0) {
        // Visual placeholder grid so the floor view is never blank.
        return (
            <div>
                <EmptyState title={t('warehouseDetail.floor.noBins')} hint={t('warehouseDetail.floor.noBinsHint')} />
                <div className="floor-grid floor-grid--placeholder">
                    {Array.from({ length: 24 }).map((_, i) => (
                        <div key={i} className="floor-bin" style={{ background: STATUS_COLOR.empty }} />
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="floor-wrap">
            <div className="floor-zones">
                {zones.map((z) => (
                    <div key={z.id} className="floor-zone">
                        <div className="floor-zone-title">{z.name} <span className="muted">({z.code})</span></div>
                        <div className="floor-grid">
                            {bins.filter((b) => Number(b.zone_id) === Number(z.id)).map((b) => {
                                const st = binStatus(b, balances);
                                return (
                                    <button key={b.id} className="floor-bin floor-bin--btn" title={t('warehouseDetail.floor.binStatus', undefined, { code: b.code, status: statusLabel(st) })}
                                        aria-label={t('warehouseDetail.floor.binStatus', undefined, { code: b.code, status: statusLabel(st) })}
                                        style={{ background: STATUS_COLOR[st] }} onClick={() => setSelected({ ...b, status: st })}>
                                        <bdi>{b.code}</bdi>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ))}
                {/* bins without a known zone */}
                {bins.some((b) => !zones.find((z) => Number(z.id) === Number(b.zone_id))) && (
                    <div className="floor-zone">
                        <div className="floor-zone-title muted">{t('warehouseDetail.floor.unzoned')}</div>
                        <div className="floor-grid">
                            {bins.filter((b) => !zones.find((z) => Number(z.id) === Number(b.zone_id))).map((b) => {
                                const st = binStatus(b, balances);
                                return <button key={b.id} className="floor-bin floor-bin--btn"
                                    title={t('warehouseDetail.floor.binStatus', undefined, { code: b.code, status: statusLabel(st) })}
                                    aria-label={t('warehouseDetail.floor.binStatus', undefined, { code: b.code, status: statusLabel(st) })}
                                    style={{ background: STATUS_COLOR[st] }} onClick={() => setSelected({ ...b, status: st })}><bdi>{b.code}</bdi></button>;
                            })}
                        </div>
                    </div>
                )}
            </div>

            <div className="floor-legend">
                {Object.entries(STATUS_COLOR).map(([k, c]) => (
                    <span key={k} className="floor-legend-item"><span className="floor-swatch" style={{ background: c }} /> {statusLabel(k)}</span>
                ))}
            </div>

            {selected && (
                <aside className="floor-panel">
                    <div className="floor-panel-head"><strong>{t('warehouseDetail.floor.bin', undefined, { code: selected.code })}</strong><button className="btn btn--sm" aria-label={t('common.close')} onClick={() => setSelected(null)}>×</button></div>
                    <dl className="kv">
                        <dt>{t('warehouseDetail.floor.status')}</dt><dd>{statusLabel(selected.status)}</dd>
                        <dt>{t('warehouseDetail.floor.type')}</dt><dd>{binTypeLabel(selected.coords?.bin_type ?? 'storage')}</dd>
                        <dt>{t('warehouseDetail.floor.capacity')}</dt><dd>{selected.capacity ?? '—'}</dd>
                    </dl>
                </aside>
            )}
        </div>
    );
}
