import React from 'react';
import { Link } from 'react-router-dom';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { Breadcrumbs } from '../components/ui.jsx';

/** Traceability hub: entry points + an at-a-glance expiry-risk summary. */
export default function TraceabilityPage() {
    const risk = useApiQuery(['expiry-risk'], () => api.expiryRisk({ within_days: 90 }), { fallback: null });
    const r = risk.data;

    const cards = [
        { label: 'Lots / Batches', to: '/traceability/lots', hint: 'Batch tracking, expiry & where-used' },
        { label: 'Serial Numbers', to: '/traceability/serials', hint: 'Per-unit lifecycle & history' },
        { label: 'Recalls', to: '/recalls', hint: 'Recall cases & impact' },
        { label: 'Expiry Report', to: '/reports', hint: 'Expiry risk & aging reports' },
    ];

    return (
        <section className="page">
            <Breadcrumbs items={[{ label: 'Traceability' }]} />
            <header className="page-head"><h1>Traceability</h1>{risk.isMock && <span className="badge badge--warn">sample data</span>}</header>

            {r && (
                <div className="panel panel--accent">
                    <strong>{r.at_risk ?? 0}</strong> lots expiring within {r.within_days ?? 90} days
                    {r.expired ? <> · <span className="badge badge--warn">{r.expired} already expired</span></> : null}
                    <Link to="/traceability/lots?expiring=1" className="btn btn--sm" style={{ marginLeft: 'auto' }}>View lots</Link>
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
