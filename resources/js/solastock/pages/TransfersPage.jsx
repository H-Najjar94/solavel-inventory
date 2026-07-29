import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function TransfersPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_adjustments');
    const { data, isLoading, isError, isMock, refetch } = useApiQuery(['transfers'], () => api.transfers({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isLoading) return <section className="page"><Skeleton /></section>;
    if (isError) return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('transfers.breadcrumb', 'Transfers') }]} />
            <EmptyState title={t('transfers.error.title', 'Transfers could not be loaded')}
                hint={t('transfers.error.hint', 'Try loading the transfers again.')}
                action={<button className="btn" onClick={() => refetch()}>{t('transfers.error.retry', 'Try again')}</button>} />
        </section>
    );
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('transfers.breadcrumb', 'Transfers') }]} />
            <header className="page-head"><h1>{t('transfers.title', 'Stock Transfers')}</h1>{isMock && <span className="badge badge--warn">{t('transfers.sampleData', 'Sample data')}</span>}
                <Link to="/transfers/new" className="btn btn--primary"
                    style={{ marginLeft: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('transfers.new', 'New transfer')}</Link></header>
            <p className="muted">{t('transfers.description', 'Move stock between warehouses through the inventory ledger.')}</p>
            {rows.length === 0 ? <EmptyState title={t('transfers.empty.title', 'No transfers')} hint={t('transfers.empty.hint', 'Create a transfer to move stock between warehouses.')} /> : (
                <table className="data-table"><thead><tr><th>{t('transfers.table.number', 'Transfer #')}</th><th>{t('transfers.table.from', 'From')}</th><th>{t('transfers.table.to', 'To')}</th><th>{t('transfers.table.date', 'Date')}</th><th>{t('transfers.table.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((t) => (<tr key={t.id}><td><Link to={`/transfers/${t.id}`}>{t.transfer_number}</Link></td><td>{t.from_warehouse_name ?? `#${t.from_warehouse_id}`}</td><td>{t.to_warehouse_name ?? `#${t.to_warehouse_id}`}</td><td>{t.transfer_date}</td><td><DocumentStatusBadge status={t.status} /></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
