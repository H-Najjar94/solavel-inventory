import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';

export default function RecallsPage() {
    const { t } = useI18n();
    const gate = useCanCreate('inventory.manage_recalls');
    const { data, isError, isMock, refetch } = useApiQuery(['recalls'], () => api.recalls({ per_page: 50 }), { fallback: [] });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);
    if (isError) return <section className="page">
        <Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls') }]} />
        <EmptyState title={t('recalls.list.loadErrorTitle', 'Recalls could not be loaded')} hint={t('recalls.list.loadErrorHint', 'Check your connection and try again.')}
            action={<button className="btn" onClick={() => refetch()}>{t('recalls.list.retry', 'Try again')}</button>} />
    </section>;
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('recalls.common.title', 'Recalls') }]} />
            <header className="page-head"><h1>{t('recalls.common.title', 'Recalls')}</h1>{isMock && <span className="badge badge--warn">{t('recalls.common.sampleData', 'Sample data')}</span>}
                <Link to="/recalls/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('recalls.list.new', 'New recall')}</Link></header>
            <p className="muted">{t('recalls.list.description', 'A recall flags affected lots or serial numbers and calculates the impact from the canonical stock ledger. It does not notify customers or create accounting entries.')}</p>
            {rows.length === 0 ? <EmptyState title={t('recalls.list.emptyTitle', 'No recalls')} hint={t('recalls.list.emptyHint', 'Open a recall case to trace and quarantine affected stock.')} /> : (
                <table className="data-table"><thead><tr><th>{t('recalls.list.number', 'Recall #')}</th><th>{t('recalls.common.item', 'Item')}</th><th>{t('recalls.common.scope', 'Scope')}</th><th>{t('recalls.common.reason', 'Reason')}</th><th>{t('recalls.common.status', 'Status')}</th></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}><td><Link to={`/recalls/${r.id}`}><bdi>{r.recall_number}</bdi></Link></td><td><bdi>{r.item ? `${r.item.sku} · ${r.item.name}` : `#${r.item_id}`}</bdi></td><td>{t(`recalls.scope.${r.scope}`, r.scope)}</td><td>{r.reason ?? '—'}</td><td><span className="badge badge--muted">{t(`recalls.status.${r.status}`, r.status)}</span></td></tr>))}</tbody></table>
            )}
        </section>
    );
}
