import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, Skeleton } from '../components/ui.jsx';
import {useCan} from '../stores/meta.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityExpiredText, traceabilityPageText, traceabilityRiskText } from '../i18n/traceabilityPages.js';

/** Traceability hub: entry points + an at-a-glance expiry-risk summary. */
export default function TraceabilityPage() {
    const can=useCan();
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const risk = useApiQuery(['expiry-risk'], () => api.expiryRisk({ within_days: 90 }), { fallback: null });
    const r = risk.data;

    const cards = [
        { icon:0, permission:'inventory.view_traceability', label: text('traceabilityPages.common.lots'), to: '/traceability/lots', hint: text('traceabilityPages.hub.lotsHint') },
        { icon:1, permission:'inventory.view_traceability', label: text('traceabilityPages.common.serials'), to: '/traceability/serials', hint: text('traceabilityPages.hub.serialsHint') },
        { icon:2, permission:'inventory.view_traceability', label: text('traceabilityPages.hub.recalls'), to: '/recalls', hint: text('traceabilityPages.hub.recallsHint') },
        { icon:3, permission:'inventory.view_reports', label: text('traceabilityPages.hub.expiryReport'), to: '/reports', hint: text('traceabilityPages.hub.expiryReportHint') },
    ];

    return (
        <section className="page traceability-hub">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability') }]} />
            <header className="page-head"><div><h1>{text('traceabilityPages.hub.title')}</h1><p className="traceability-intro">{text('traceabilityPages.hub.intro')}</p></div>{risk.isMock && <span className="badge badge--warn">{text('traceabilityPages.common.sampleData')}</span>}</header>

            <section className="card traceability-summary" aria-busy={risk.isFetching}>
                <div className="card-head"><h2>{text('traceabilityPages.hub.expiryTitle')}</h2><span className="badge badge--muted">{text('traceabilityPages.hub.period')}</span></div>
                <div className="card-body">
                {risk.isLoading ? <Skeleton rows={2} /> : risk.isError ? <div className="traceability-load-error" role="status"><div className="traceability-error-icon" aria-hidden="true">!</div><div><strong>{text('traceabilityPages.hub.expiryLoadError')}</strong><p>{text('traceabilityPages.hub.retryHint')}</p></div><button type="button" className="btn" disabled={risk.isFetching} onClick={()=>risk.refetch()}>{text('traceabilityPages.common.retry')}</button></div> : r && (
                <div className="traceability-risk">
                    <span>{traceabilityRiskText(locale, r.at_risk ?? 0, r.within_days ?? 90)}</span>
                    {r.expired ? <> · <span className="badge badge--warn">{traceabilityExpiredText(locale, r.expired)}</span></> : null}
                    <Link to="/traceability/lots?expiring=1" className="btn btn--sm" style={{ marginInlineStart: 'auto' }}>{text('traceabilityPages.hub.viewLots')}</Link>
                </div>
            )}</div></section>

            <div className="traceability-sections-heading"><h2>{text('traceabilityPages.hub.tools')}</h2></div>
            <div className="traceability-tools">
                {cards.filter(c=>can(c.permission)).map((c) => (
                    <Link key={c.to} to={c.to} className="card traceability-tool">
                        <span className="traceability-tool-icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"><path d={['M3 7l9-4 9 4v10l-9 4-9-4V7zm0 0 9 4 9-4M12 11v10','M9 3 7 21M17 3l-2 18M4 9h17M3 15h17','M12 3 2 21h20L12 3zM12 9v5m0 3v1','M3 3v18h18M7 16v-4m5 4V8m5 8V5'][c.icon]} /></svg></span><div><h3>{c.label}</h3><p>{c.hint}</p><span className="traceability-tool-open">{text('traceabilityPages.hub.open')} <span aria-hidden="true">↗</span></span></div>
                    </Link>
                ))}
            </div>
        </section>
    );
}
