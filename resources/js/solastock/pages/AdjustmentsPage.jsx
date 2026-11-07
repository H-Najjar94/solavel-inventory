import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { mockAdjustments } from '../services/mockData.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { t } from '../i18n/index.js';

export default function AdjustmentsPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['adjustments', sort.key, sort.dir], () => api.adjustments({ ...sort.params, per_page: 50 }), { fallback: mockAdjustments });
    const rows = Array.isArray(data) ? data : (data?.data ?? mockAdjustments);

    return (
        <section className="page index-page">
            <Breadcrumbs items={[{ label: t('adjustments') }]} />
            <header className="page-head"><h1>{t('adjustments.title')}</h1>{isMock && <span className="badge badge--warn">{t('warehouses.sampleData')}</span>}
                {gate.allowed && <Link to="/adjustments/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('adjustments.new')}</Link>}</header>
            <p className="muted">{t('adjustments.hint')}</p>
            {rows.length === 0 ? <EmptyState title={t('adjustments.empty')} hint={t('adjustments.emptyHint')} /> : (
                <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('adjustments.number')}</SortTh><SortTh sort={sort} col="date">{t('adjustments.date')}</SortTh><SortTh sort={sort} col="warehouse">{t('adjustments.warehouse')}</SortTh><SortTh sort={sort} col="reason">{t('adjustments.reason')}</SortTh><SortTh sort={sort} col="status">{t('status')}</SortTh><SortTh sort={sort} col="increase">{t('adjustments.increaseValue')}</SortTh><SortTh sort={sort} col="decrease">{t('adjustments.decreaseValue')}</SortTh></tr></thead>
                <tbody>{rows.map((a) => (<tr key={a.id}><td><Link to={`/adjustments/${a.id}`}>{a.adjustment_number}</Link></td><td>{a.adjustment_date}</td><td>{a.warehouse_name ?? `#${a.warehouse_id}`}</td><td>{a.reason_code ?? '—'}</td><td><DocumentStatusBadge status={a.status} /></td><td>{a.total_increase_value}</td><td>{a.total_decrease_value}</td></tr>))}</tbody></table></div>
            )}
        </section>
    );
}
