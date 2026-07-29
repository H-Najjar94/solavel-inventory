import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs, EmptyState, Skeleton } from '../components/ui.jsx';
import { useI18n } from '../i18n/context.jsx';
import { traceabilityExpiredText, traceabilityPageText, traceabilityRiskText } from '../i18n/traceabilityPages.js';

/** Traceability hub: entry points + an at-a-glance expiry-risk summary. */
export default function TraceabilityPage() {
    const { locale } = useI18n();
    const text = (key, params) => traceabilityPageText(locale, key, params);
    const risk = useApiQuery(['expiry-risk'], () => api.expiryRisk({ within_days: 90 }), { fallback: null });
    const r = risk.data;

    const cards = [
        { label: text('traceabilityPages.common.lots'), to: '/traceability/lots', hint: text('traceabilityPages.hub.lotsHint') },
        { label: text('traceabilityPages.common.serials'), to: '/traceability/serials', hint: text('traceabilityPages.hub.serialsHint') },
        { label: text('traceabilityPages.hub.recalls'), to: '/recalls', hint: text('traceabilityPages.hub.recallsHint') },
        { label: text('traceabilityPages.hub.expiryReport'), to: '/reports', hint: text('traceabilityPages.hub.expiryReportHint') },
    ];

    if (risk.isLoading) return <section className="page"><Skeleton /></section>;
    if (risk.isError) return <section className="page">
        <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability') }]} />
        <EmptyState title={text('traceabilityPages.common.loadError')}
            action={<button className="btn" onClick={() => risk.refetch()}>{text('traceabilityPages.common.retry')}</button>} />
    </section>;
    return (
        <section className="page">
            <Breadcrumbs items={[{ label: text('traceabilityPages.common.traceability') }]} />
            <header className="page-head"><h1>{text('traceabilityPages.hub.title')}</h1>{risk.isMock && <span className="badge badge--warn">{text('traceabilityPages.common.sampleData')}</span>}</header>

            {r && (
                <div className="panel panel--accent">
                    <span>{traceabilityRiskText(locale, r.at_risk ?? 0, r.within_days ?? 90)}</span>
                    {r.expired ? <> · <span className="badge badge--warn">{traceabilityExpiredText(locale, r.expired)}</span></> : null}
                    <Link to="/traceability/lots?expiring=1" className="btn btn--sm" style={{ marginInlineStart: 'auto' }}>{text('traceabilityPages.hub.viewLots')}</Link>
                </div>
            )}

            <div className="card-grid">
                {cards.map((c) => (
                    <Link key={c.to} to={c.to} className="panel panel--link">
                        <h3>{c.label}</h3><p className="muted">{c.hint}</p>
                    </Link>
                ))}
            </div>
        </section>
    );
}
