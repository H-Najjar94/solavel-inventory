import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function SalesReturnsPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_returns');
    const { data, isMock } = useApiQuery(['sales-returns'], () => api.salesReturns({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('returns.list.title', 'Sales Returns') }]} />
            <header className="page-head"><h1>{t('returns.list.title', 'Sales Returns')}</h1>{isMock && <span className="badge badge--warn">{t('returns.common.sampleData', 'Sample data')}</span>}
                <Link to="/sales-returns/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('returns.actions.new', 'New return')}</Link></header>
            <p className="muted">{t('returns.list.description', 'Posting a return puts resellable or quarantined units back into stock through the ledger. Damaged units are recorded without being returned to stock.')}</p>
            {rows.length === 0 ? <EmptyState title={t('returns.list.emptyTitle', 'No sales returns')} hint={t('returns.list.emptyHint', 'Record a customer return to bring eligible units back into stock.')} /> : (
                <table className="data-table"><thead><tr><th>{t('returns.list.number', 'Return #')}</th><th>{t('returns.common.customer', 'Customer')}</th><th>{t('returns.common.date', 'Date')}</th><th>{t('returns.common.warehouse', 'Warehouse')}</th><th>{t('returns.common.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}><td><Link to={`/sales-returns/${r.id}`}>{r.return_number}</Link></td><td>{r.customer_name ?? '—'}</td><td>{r.return_date}</td><td>#{r.warehouse_id}</td><td><DocumentStatusBadge status={r.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
