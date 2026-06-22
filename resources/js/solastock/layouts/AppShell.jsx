import React, { useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { visibleNav } from '../router/nav.js';
import { useMeta } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { getTheme, toggleTheme } from '../stores/theme.js';

// Group nav items by their `group` key, preserving first-seen order (Finance-style
// sectioned sidebar).
function groupNav(items) {
    const out = {};
    for (const n of items) {
        const g = n.group || 'General';
        (out[g] ||= []).push(n);
    }
    return out;
}

// Top-bar organization switcher. Lists the signed-in user's organizations and
// switches the active org/client in the SSO session (POST /tenant/select-org),
// then refetches everything so the whole app reloads in the new org's context.
function OrgSwitcher({ tenant }) {
    const [open, setOpen] = useState(false);
    const [busyId, setBusyId] = useState(0);
    const orgs = tenant.organizations || [];
    const currentName = tenant.loading
        ? ' '
        : (tenant.organization_name
            || (tenant.organization_id ? `Organization #${tenant.organization_id}` : 'No organization'));

    async function pick(org) {
        if (org.current || busyId) return;
        setBusyId(org.id);
        try { await tenant.selectOrg(org.id); setOpen(false); }
        finally { setBusyId(0); }
    }

    // Single (or zero) org → no need for a menu; just show the name.
    if (orgs.length <= 1) {
        return <span className="org-name" title="Active organization">{currentName}</span>;
    }

    return (
        <div className="org-switcher" style={{ position: 'relative' }}>
            <button
                type="button"
                className="org-switcher__btn"
                onClick={() => setOpen((o) => !o)}
                title="Switch organization"
                style={{
                    display: 'inline-flex', alignItems: 'center', gap: 8, cursor: 'pointer',
                    background: 'transparent', border: 'none', font: 'inherit', color: 'inherit',
                    padding: '4px 6px', borderRadius: 8, maxWidth: 320,
                }}
            >
                <i className="fa-solid fa-building" style={{ opacity: 0.6, fontSize: 13 }} aria-hidden="true" />
                <span className="org-name" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{currentName}</span>
                <i className={`fa-solid fa-chevron-${open ? 'up' : 'down'}`} style={{ fontSize: 10, opacity: 0.55 }} aria-hidden="true" />
            </button>

            {open && (
                <>
                    <div onClick={() => setOpen(false)} style={{ position: 'fixed', inset: 0, zIndex: 40 }} />
                    <div
                        role="menu"
                        style={{
                            position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 50,
                            minWidth: 280, maxHeight: 360, overflowY: 'auto',
                            background: 'var(--surface-1,#fff)', border: '1px solid var(--line-soft,#e6e1d8)',
                            borderRadius: 12, boxShadow: '0 12px 32px rgba(0,0,0,0.16)', padding: 6,
                        }}
                    >
                        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--ink-soft,#9a9384)', padding: '6px 10px' }}>
                            Your organizations
                        </div>
                        {orgs.map((org) => (
                            <button
                                key={org.id}
                                type="button"
                                role="menuitem"
                                onClick={() => pick(org)}
                                disabled={!!busyId}
                                style={{
                                    display: 'flex', alignItems: 'center', gap: 10, width: '100%',
                                    textAlign: 'left', background: org.current ? 'var(--surface-2,#faf9f7)' : 'transparent',
                                    border: 'none', borderRadius: 8, padding: '9px 10px', cursor: org.current ? 'default' : 'pointer',
                                    font: 'inherit', color: 'inherit',
                                }}
                            >
                                <i
                                    className={`fa-solid ${org.current ? 'fa-circle-check' : 'fa-building'}`}
                                    style={{ fontSize: 13, color: org.current ? '#e09921' : 'var(--ink-soft,#9a9384)', width: 16 }}
                                    aria-hidden="true"
                                />
                                <span style={{ flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: org.current ? 600 : 500 }}>
                                    {org.name}
                                </span>
                                {busyId === org.id
                                    ? <i className="fa-solid fa-spinner fa-spin" style={{ fontSize: 12, opacity: 0.6 }} aria-hidden="true" />
                                    : <span style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', color: org.inventory_enabled ? '#2f7a4f' : 'var(--ink-soft,#9a9384)' }}>
                                        {org.inventory_enabled ? 'Active' : 'Set up'}
                                      </span>}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

// Authenticated SolaStock shell: sidebar (permission-aware, icon nav), top bar
// with the real org + account, light/dark toggle.
export default function AppShell() {
    const meta = useMeta();
    const tenant = useTenant();
    const nav = visibleNav(meta.permissions);
    const [theme, setTheme] = useState(getTheme());
    const [collapsed, setCollapsed] = useState(false);

    const badgeClass = tenant.isLive ? 'badge--live'
        : tenant.isDemo ? 'badge--demo'
        : tenant.isSetup ? 'badge--warn' : 'badge--warn';

    const dataChip = {
        real: ['Real data', 'badge--live'],
        demo: ['Demo data', 'badge--demo'],
        setup: ['Setup required', 'badge--warn'],
        sample: ['Sample preview', 'badge--warn'],
        no_organization: ['No organization', 'badge--warn'],
        no_access: ['No access', 'badge--warn'],
    }[tenant.dataState] ?? ['Sample preview', 'badge--warn'];

    return (
        <div className="app">
            <aside className={`sidebar ${collapsed ? 'sidebar--collapsed' : ''}`}>
                <div className="side-brand">
                    <img className="side-logo-img" src="/inventory/imgs/favicon-solastock.svg" alt="SolaStock"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                    {!collapsed && <span className="side-name">SolaStock</span>}
                </div>
                {/* The nav is only usable once the tenant is ready. While the
                    status is still loading, show a quiet skeleton (no flash). In
                    setup / no-org / no-access states the modules don't exist yet,
                    so we hide them and surface only the relevant action. */}
                {tenant.loading ? (
                    <nav className="side-nav side-nav--loading">
                        {Array.from({ length: 6 }).map((_, i) => <span className="side-skeleton" key={i} />)}
                    </nav>
                ) : tenant.ready ? (
                    <nav className="side-nav">
                        {Object.entries(groupNav(nav)).map(([group, items]) => (
                            <div className="side-group" key={group}>
                                {!collapsed && <div className="side-group__label">{group}</div>}
                                {items.map((n) => (
                                    <NavLink
                                        key={n.key}
                                        to={n.path}
                                        end={n.path === '/dashboard'}
                                        className={({ isActive }) => `side-link ${isActive ? 'is-active' : ''}`}
                                        title={n.label}
                                    >
                                        <i className={`side-link-icon ${n.icon || 'fa-solid fa-circle'}`} aria-hidden="true" />
                                        {!collapsed && <span className="side-link-label">{n.label}</span>}
                                    </NavLink>
                                ))}
                            </div>
                        ))}
                    </nav>
                ) : (
                    <nav className="side-nav side-nav--locked">
                        {!collapsed && (
                            <div className="side-locked">
                                <i className="fa-solid fa-lock side-locked__icon" aria-hidden="true" />
                                <span>{tenant.isSetup ? 'Finish setup to use SolaStock' : tenant.isNoOrg ? 'No organization' : tenant.isNoAccess ? 'No access' : 'Sign in to continue'}</span>
                            </div>
                        )}
                    </nav>
                )}
                <button className="side-collapse" onClick={() => setCollapsed((c) => !c)}>
                    {collapsed ? '»' : '« Collapse'}
                </button>
            </aside>

            <div className="main">
                <header className="topbar">
                    <div className="topbar-left">
                        <OrgSwitcher tenant={tenant} />
                    </div>
                    <div className="topbar-right">
                        {tenant.loading ? (
                            <span className="badge badge--muted">Loading…</span>
                        ) : (<>
                            <span className={`badge ${dataChip[1]}`}
                                title={`Data mode: ${tenant.dataState}`}>{dataChip[0]}</span>
                            <span className={`badge ${badgeClass}`} title="Active tenant">{tenant.badge}</span>
                        </>)}
                        {/* Demo is a SECONDARY preview option, only offered when there is no live org. */}
                        {!tenant.loading && tenant.mode === 'none' && !tenant.authenticated && tenant.demo_available && (
                            <button className="btn btn--sm" onClick={() => tenant.selectDemo()}>
                                Use SolaStock Demo Tenant
                            </button>
                        )}
                        {!tenant.loading && tenant.isDemo && (
                            <button className="btn btn--sm" onClick={() => tenant.clear()}>Exit demo</button>
                        )}
                        <button
                            className="theme-toggle"
                            onClick={() => setTheme(toggleTheme())}
                            title="Toggle light / dark"
                        >
                            {theme === 'dark' ? '☾' : '☀'}
                        </button>
                        {!tenant.loading && (
                            <span className="user-menu" title={tenant.user?.email || 'Account'}>
                                {tenant.user?.name || tenant.user?.email || 'Account'}
                            </span>
                        )}
                    </div>
                </header>

                <main className="content">
                    <TenantContent tenant={tenant} />
                </main>
            </div>
        </div>
    );
}

/**
 * Decides what the content area shows based on the tenant state — the Solavel
 * way. For non-real states (no-org / no-access / setup) the page content is
 * REPLACED by a full state screen (never shown alongside sample data). The
 * onboarding route is always allowed through (it IS the setup flow). Real and
 * explicit-sample modes render the page; a small banner labels sample preview.
 */
function TenantContent({ tenant }) {
    const location = useLocation();
    const onOnboarding = location.pathname.startsWith('/onboarding');

    // While the tenant state is still resolving, show a quiet loader — never the
    // sample/setup fallback. This prevents the flash of dashboard cards before
    // the app snaps to "Setup required".
    if (tenant.loading) {
        return (
            <div className="app-loading">
                <i className="fa-solid fa-circle-notch fa-spin app-loading__spin" aria-hidden="true" />
                <span>Loading SolaStock…</span>
            </div>
        );
    }

    // Onboarding pages render regardless of state (that's where setup happens).
    if (onOnboarding) {
        return <Outlet />;
    }

    // Decisive non-real states REPLACE the page — no sample data underneath.
    if (tenant.isNoOrg || tenant.isNoAccess || tenant.isSetup || tenant.needs_setup) {
        return <TenantStateBanner tenant={tenant} fullPage />;
    }

    // Real (live/demo) or explicit sample preview → render the page. A labeled
    // banner is shown only in sample preview so it's never mistaken for live data.
    return (
        <>
            {tenant.dataState === 'sample' && <SamplePreviewBanner tenant={tenant} />}
            <Outlet />
        </>
    );
}

function SamplePreviewBanner({ tenant }) {
    return (
        <div className="setup-hint">
            <strong>Sample Preview — not your organization data.</strong>{' '}
            {tenant.authenticated
                ? 'Open SolaStock from the Solavel launcher to load your organization’s real data.'
                : (tenant.demo_available ? 'Or click “Use SolaStock Demo Tenant” above to preview with demo data.' : 'Sign in via Solavel to load your real data.')}
        </div>
    );
}

/**
 * Renders the Solavel-style tenant state banner above page content:
 *   no_organization → "no organization" screen
 *   no_access       → "no access" screen
 *   setup (live)    → "setup required" with an admin Provision button
 *   setup (demo)    → demo setup instructions
 *   sample          → sample-preview hint (offer demo)
 * Live + demo (real data) render nothing.
 */
function TenantStateBanner({ tenant }) {
    if (tenant.dataState === 'real' || tenant.dataState === 'demo') return null;

    if (tenant.isNoOrg) {
        return (
            <div className="state-screen">
                <h2>No organization active</h2>
                <p>{tenant.state_message || 'You are signed in but no Solavel organization is active.'}</p>
                <p className="muted">Open SolaStock from the Solavel app launcher to select an organization.</p>
            </div>
        );
    }
    if (tenant.isNoAccess) {
        return (
            <div className="state-screen">
                <h2>No SolaStock access</h2>
                <p>{tenant.state_message || 'Your account does not have access to SolaStock for this organization.'}</p>
                <p className="muted">Ask an administrator to grant you inventory permissions.</p>
            </div>
        );
    }
    if (tenant.isSetup || tenant.needs_setup) {
        return <SetupHero tenant={tenant} />;
    }
    // Sample preview is rendered by SamplePreviewBanner alongside the page; the
    // banner here only appears if invoked directly without a decisive state.
    return null;
}

/**
 * Product-style "Get started with SolaStock" screen for an org whose inventory
 * workspace isn't provisioned yet. Provisions inline (no central round-trip), so
 * the CTA always works; shows progress + a precise admin command if the app
 * process lacks DB privileges.
 */
function SetupHero({ tenant }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [adminCmd, setAdminCmd] = useState('');

    // Two distinct setup stages, distinct copy:
    //  - needs_activation        → SolaStock isn't enabled for this org yet
    //                              ("Activate SolaStock").
    //  - tenant_missing/unmigrated/unreachable → SolaStock IS enabled (e.g. the
    //                              user just finished the central onboarding
    //                              wizard); this is the final one-time workspace
    //                              initialization, NOT another activation
    //                              ("Finish setup" / "Initialize your workspace").
    const needsActivation = tenant.state === 'needs_activation';

    const features = [
        ['fa-boxes-stacked', 'Items & warehouses', 'Catalog, multi-warehouse, zones & bins'],
        ['fa-layer-group', 'Real-time stock', 'One immutable ledger, live balances & costing'],
        ['fa-truck-fast', 'Purchasing & fulfillment', 'PO → GRN, sales orders, pick/pack/ship'],
        ['fa-barcode', 'Traceability', 'Lots, serials, expiry & recalls'],
    ];

    // When SolaStock isn't enabled yet (needs_activation), the user MUST go
    // through the central onboarding wizard (pick organization → choose plan →
    // enable) — we never enable the app inline. Apache routes /inventory/onboarding
    // to the central app; a full navigation (not React Router) takes them there.
    function startOnboarding() {
        window.location.assign('/inventory/onboarding');
    }

    // INITIALIZE only — used for the post-onboarding "Finish setup" stage where
    // SolaStock is already enabled but the tenant tables aren't provisioned yet.
    // POST /api/v1/tenant/provision creates the tables; on success the refetched
    // status (live_ready) re-renders the shell. If the app process can't run the
    // migration (no DB privileges), the endpoint returns an admin command.
    async function activate() {
        if (busy) return;
        setBusy(true); setError(''); setAdminCmd('');
        try {
            const res = await tenant.provision();
            if (res && res.provisioned === false) {
                setError(res.message || 'Activation could not finish automatically.');
                if (res.admin_command) setAdminCmd(res.admin_command);
            }
            // On success the refetched status (live_ready) re-renders the shell.
        } catch (e) {
            setError(
                e?.response?.data?.message
                || e?.message
                || 'Could not activate SolaStock right now. Please try again.'
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="setup-hero">
            <div className="setup-hero__card">
                <div className="setup-hero__brand">
                    <img src="/inventory/imgs/favicon-solastock.svg" alt="" className="setup-hero__logo"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                    <span className="setup-hero__eyebrow">SolaStock · Inventory</span>
                </div>

                <h1 className="setup-hero__title">
                    {needsActivation
                        ? `Activate SolaStock for ${tenant.organization_name || 'your organization'}`
                        : `Finish setting up SolaStock for ${tenant.organization_name || 'your organization'}`}
                </h1>
                <p className="setup-hero__sub">
                    {needsActivation
                        ? 'Your organization is connected. Set up SolaStock to start managing stock, purchasing, fulfillment and traceability — choose your organization and plan in a quick guided setup.'
                        : 'SolaStock is enabled for your organization. This last step initializes your workspace — it creates your inventory tables so you can start managing stock. One click and you’re in.'}
                </p>

                <div className="setup-hero__features">
                    {features.map(([icon, title, desc]) => (
                        <div className="setup-feature" key={title}>
                            <i className={`fa-solid ${icon} setup-feature__icon`} aria-hidden="true" />
                            <div>
                                <div className="setup-feature__title">{title}</div>
                                <div className="setup-feature__desc">{desc}</div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="setup-hero__cta">
                    {/* needs_activation → guided onboarding wizard (never enable
                        inline). Already-enabled-but-unprovisioned → inline init. */}
                    <button
                        type="button"
                        className="btn btn--primary btn--lg"
                        onClick={needsActivation ? startOnboarding : activate}
                        disabled={needsActivation ? false : busy}
                    >
                        {needsActivation
                            ? 'Set up SolaStock'
                            : (busy ? 'Initializing…' : 'Finish setup')}
                    </button>

                    {error && (
                        <div className="setup-error" role="alert" style={{ color: '#e05151', fontSize: 13, maxWidth: 480 }}>
                            <i className="fa-solid fa-triangle-exclamation" /> {error}
                        </div>
                    )}
                    {adminCmd && (
                        <pre style={{
                            textAlign: 'left', background: 'var(--surface-2,#faf9f7)',
                            border: '1px solid var(--line-soft,#e6e1d8)', borderRadius: 10,
                            padding: 12, fontSize: 12, overflowX: 'auto', maxWidth: 520, margin: 0,
                        }}>{adminCmd}</pre>
                    )}
                </div>
            </div>
        </div>
    );
}
