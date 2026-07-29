import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { mockBalances } from '../services/mockData.js';
import { t } from '../i18n/index.js';

export default function BalancesPage() {
    const { data, isMock } = useApiQuery(['balances'], () => api.balances({ per_page: 50 }), { fallback: mockBalances });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockBalances);
    return (
        <section className="page">
            <header className="page-head"><h1>{t('currentStock')}</h1>{isMock && <span className="badge badge--warn">{t('warehouses.sampleData')}</span>}</header>
            <table className="data-table"><thead><tr><th>{t('item')}</th><th>{t('warehouse')}</th><th>{t('balances.bin')}</th><th>{t('onHand')}</th><th>{t('reserved')}</th><th>{t('available')}</th><th>{t('balances.sellable')}</th><th>{t('warehouses.status')}</th><th>{t('balances.averageCost')}</th><th>{t('balances.value')}</th></tr></thead>
            <tbody>{rows.length === 0 ? <tr><td colSpan="10" className="muted">{t('balances.empty')}</td></tr> :
              rows.map((b) => (<tr key={b.id}>
                <td>{b.item_name ?? `#${b.item_id}`}{b.item_sku && <span className="muted"> · {b.item_sku}</span>}</td>
                <td>{b.warehouse_name ?? `#${b.warehouse_id}`}</td>
                <td>{b.bin_code ?? (b.bin_id ? `#${b.bin_id}` : '—')}</td>
                <td>{b.on_hand_qty}</td><td>{b.reserved_qty}</td><td>{b.available_qty}</td><td>{b.sellable_available_qty ?? b.available_qty}</td><td>{t(`availability.${b.availability_status ?? 'available'}`, b.availability_status ?? t('available'))}</td><td>{b.average_cost}</td><td>{b.total_value}</td></tr>))}</tbody></table>
        </section>
    );
}
