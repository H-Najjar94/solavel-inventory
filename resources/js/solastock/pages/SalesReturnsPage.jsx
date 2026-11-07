import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { useTableSort } from '../hooks/useTableSort.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, SortTh } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SalesReturnsPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_returns');
    const sort = useTableSort();
    const { data, isMock } = useApiQuery(['sales-returns', sort.key, sort.dir], () => api.salesReturns({ ...sort.params, per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page index-page">
            <Breadcrumbs items={[{ label: t('returns.list.title', 'Sales Returns') }]} />
            <header className="page-head"><h1>{t('returns.list.title', 'Sales Returns')}</h1>{isMock && <span className="badge badge--warn">{t('returns.common.sampleData', 'Sample data')}</span>}
                {gate.allowed && <Link to="/sales-returns/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('returns.actions.new', 'New return')}</Link>}</header>
            <p className="muted">{t('returns.list.description', 'Posting a return puts resellable or quarantined units back into stock through the ledger. Damaged and retired units are received at their original cost and immediately written off by a DMG- adjustment, so they never become sellable stock.')}</p>
            {rows.length === 0 ? <EmptyState title={t('returns.list.emptyTitle', 'No sales returns')} hint={t('returns.list.emptyHint', 'Record a customer return to bring eligible units back into stock.')} /> : (
                <div className="index-table-scroll"><table className="data-table"><thead><tr><SortTh sort={sort} col="number">{t('returns.list.number', 'Return #')}</SortTh><SortTh sort={sort} col="customer">{t('returns.common.customer', 'Customer')}</SortTh><SortTh sort={sort} col="date">{t('returns.common.date', 'Date')}</SortTh><SortTh sort={sort} col="warehouse">{t('returns.common.warehouse', 'Warehouse')}</SortTh><SortTh sort={sort} col="status">{t('returns.common.status', 'Status')}</SortTh></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}><td><Link to={`/sales-returns/${r.id}`}>{r.return_number}</Link></td><td>{r.customer_name ?? '—'}</td><td>{r.return_date}</td><td>{r.warehouse_name ?? <bdi>#{r.warehouse_id}</bdi>}</td><td><DocumentStatusBadge status={r.status} /></td></tr>))}</tbody></table></div>
            )}
        </section>
    );
}
