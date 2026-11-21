import React from 'react';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { mockBalances } from '../services/mockData.js';
import { SortTh } from '../components/ui.jsx';
import { t } from '../i18n/index.js';

export default function BalancesPage() {
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['balances', sort.key, sort.dir], () => api.balances({ ...sort.params, per_page: 50 }), { fallback: mockBalances });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockBalances);
    return (
        <section className="page index-page">
            <header className="page-head"><h1>{t('currentStock')}</h1>{isMock && <span className="badge badge--warn">{t('warehouses.sampleData')}</span>}</header>
            <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="item">{t('item')}</SortTh><SortTh sort={sort} col="warehouse">{t('warehouse')}</SortTh><th>{t('balances.bin')}</th><SortTh sort={sort} col="onhand">{t('onHand')}</SortTh><SortTh sort={sort} col="reserved">{t('reserved')}</SortTh><SortTh sort={sort} col="available">{t('available')}</SortTh><th>{t('balances.sellable')}</th><th>{t('warehouses.status')}</th><SortTh sort={sort} col="cost">{t('balances.averageCost')}</SortTh><SortTh sort={sort} col="value">{t('balances.value')}</SortTh></tr></thead>
            <tbody>{rows.length === 0 ? <tr><td colSpan="10" className="muted">{t('balances.empty')}</td></tr> :
              rows.map((b) => (<tr key={b.id}>
                <td>{b.item_name ?? `#${b.item_id}`}{b.item_sku && <span className="muted"> · {b.item_sku}</span>}</td>
                <td>{b.warehouse_name ?? `#${b.warehouse_id}`}</td>
                <td>{b.bin_code ?? (b.bin_id ? `#${b.bin_id}` : '—')}</td>
                <td>{b.on_hand_qty}</td><td>{b.reserved_qty}</td><td>{b.available_qty}</td><td>{b.sellable_available_qty ?? b.available_qty}</td><td>{t(`availability.${b.availability_status ?? 'available'}`, b.availability_status ?? t('available'))}</td><td>{b.average_cost}</td><td>{b.total_value}</td></tr>))}</tbody></table></div>
        </section>
    );
}
