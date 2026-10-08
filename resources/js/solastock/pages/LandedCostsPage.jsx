import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useCanCreate } from '../hooks/useCanCreate.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { DocumentStatusBadge } from '../components/document.jsx';
import LandedCostConnectionPanel from '../components/LandedCostConnectionPanel.jsx';
import LandedCostAvailabilityNotice from '../components/LandedCostAvailabilityNotice.jsx';
import { useMeta, useCan } from '../stores/meta.jsx';
import { t } from '../i18n/index.js';

export default function LandedCostsPage() {
    const gate = useCanCreate('inventory.manage_adjustments');
    const meta = useMeta(); const can = useCan();
    // Available by plan unless the organization opted out. Outside the plan the API
    // refuses everything; after an opt-out, existing documents stay readable.
    const available = meta.landed_costs?.available !== false;
    const entitled = meta.landed_costs?.entitled !== false;
    const { data, isLoading } = useApiQuery(['landed-costs'], () => api.landedCosts({ per_page: 50 }), { fallback: [], enabled: entitled });
    const connection = useApiQuery(['landed-cost-connection'], api.landedCostConnection, { fallback: null, enabled: available });
    const rows = Array.isArray(data) ? data : (data?.data ?? []);

    if (!entitled) {
        return <section className="page">
            <Breadcrumbs items={[{ label: t('landedCosts.title') }]} />
            <header className="page-head"><h1>{t('landedCosts.title')}</h1></header>
            <LandedCostAvailabilityNotice availability={meta.landed_costs} canManageSettings={can('inventory.manage_settings')} />
        </section>;
    }

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: t('landedCosts.title') }]} />
            <header className="page-head"><h1>{t('landedCosts.title')}</h1>
                {available && <Link to="/landed-costs/new" className="btn btn--primary"
                    style={{ marginInlineStart: 'auto', pointerEvents: gate.allowed ? 'auto' : 'none', opacity: gate.allowed ? 1 : 0.5 }}
                    title={gate.allowed ? '' : gate.reason}>{t('landedCosts.new')}</Link>}</header>
            <p className="muted">{t('landedCosts.hint')}</p>
            <LandedCostAvailabilityNotice availability={meta.landed_costs} canManageSettings={can('inventory.manage_settings')} />
            {available && <LandedCostConnectionPanel status={connection.data} />}
            {isLoading ? <Skeleton /> : rows.length === 0 ? <EmptyState title={t('landedCosts.empty')} hint={t('landedCosts.emptyHint')} /> : (
                <table className="data-table"><thead><tr>
                    <th>{t('landedCosts.number')}</th><th>{t('landedCosts.date')}</th><th>{t('landedCosts.reference')}</th><th>{t('landedCosts.method')}</th>
                    <th>{t('status')}</th><th>{t('landedCosts.totalBase')}</th><th>{t('landedCosts.onHand')}</th><th>{t('landedCosts.consumed')}</th></tr></thead>
                <tbody>{rows.map((r) => (<tr key={r.id}>
                    <td><Link to={`/landed-costs/${r.id}`}>{r.landed_cost_number}</Link></td><td>{r.landed_cost_date}</td>
                    <td>{r.supplier_reference ?? '—'}</td><td>{t(`landedCosts.method.${r.allocation_method}`, r.allocation_method)}</td>
                    <td><DocumentStatusBadge status={r.status} /></td><td>{r.total_base_amount}</td>
                    <td>{r.status === 'draft' ? '—' : r.inventory_base_amount}</td><td>{r.status === 'draft' ? '—' : r.consumed_base_amount}</td>
                </tr>))}</tbody></table>
            )}
        </section>
    );
}
