import React, { useEffect, useMemo, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { visibleNav } from '../router/nav.js';
import { useMeta } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { getTheme, toggleTheme } from '../stores/theme.js';
import { getLocale, t } from '../i18n/index.js';

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

const NAV_LABELS = {
    dashboard: 'dashboard', items: 'items', warehouses: 'warehouses', balances: 'currentStock',
    ledger: 'stockLedger', opening: 'openingStock', adjustments: 'adjustments', transfers: 'transfers',
    counts: 'counts', scanner: 'scanner', suppliers: 'suppliers', 'purchase-orders': 'purchaseOrders',
    'goods-receipts': 'goodsReceipts', customers: 'customers', 'sales-orders': 'salesOrders',
    'pick-lists': 'picking', packs: 'packing', shipments: 'shipments', 'sales-returns': 'salesReturns',
    traceability: 'traceability', lots: 'lots', serials: 'serials', recalls: 'recalls', reports: 'reports',
    integration: 'solabooks', settings: 'settings',
};
const GROUP_LABELS = { Catalog: 'catalog', Stock: 'stock', Operations: 'operations', Purchasing: 'purchasing', 'Sales / Fulfillment': 'sales', Traceability: 'traceability', Insights: 'insights', Admin: 'admin' };

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
            || (tenant.organization_id ? t('shell.organizationNumber', undefined, { id: tenant.organization_id }) : t('shell.noOrganization')));

    async function pick(org) {
        if (org.current || busyId) return;
        setBusyId(org.id);
        try { await tenant.selectOrg(org.id); setOpen(false); }
        finally { setBusyId(0); }
    }

    // Single (or zero) org → no need for a menu; just show the name.
    if (orgs.length <= 1) {
        return <span className="org-name" title={t('shell.activeOrganization')}>{currentName}</span>;
    }

    return (
        <div className="org-switcher" style={{ position: 'relative' }}>
            <button
                type="button"
                className="org-switcher__btn"
                onClick={() => setOpen((o) => !o)}
                title={t('shell.switchOrganization')}
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
                            {t('shell.yourOrganizations')}
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
                                        {org.inventory_enabled ? t('shell.active') : t('shell.setup')}
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
    const location = useLocation();
    const nav = visibleNav(meta.permissions);
    const primaryNav = useMemo(() => nav.filter((item) => item.key === 'dashboard'), [nav]);
    const groupedNav = useMemo(() => groupNav(nav.filter((item) => item.key !== 'dashboard')), [nav]);
    const activeGroup = Object.entries(groupedNav).find(([, items]) => (
        items.some((item) => location.pathname === item.path
            || (item.path !== '/dashboard' && location.pathname.startsWith(`${item.path}/`)))
    ))?.[0] ?? null;
    const [theme, setTheme] = useState(getTheme());
    const [collapsed, setCollapsed] = useState(false);
    const [openGroup, setOpenGroup] = useState(activeGroup);
    const locale = getLocale();

    useEffect(() => {
        setOpenGroup(activeGroup);
    }, [activeGroup]);

    const badgeClass = tenant.isDemo ? 'badge--demo'
        : tenant.isSetup ? 'badge--warn' : 'badge--warn';

    const dataChip = {
        demo: [t('shell.demoData'), 'badge--demo'],
        setup: [t('shell.setupRequired'), 'badge--warn'],
        sample: [t('shell.samplePreview'), 'badge--warn'],
        no_organization: [t('shell.noOrganization'), 'badge--warn'],
        no_access: [t('shell.noAccess'), 'badge--warn'],
    }[tenant.dataState] ?? null;

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
                        <div className="side-primary">
                            {primaryNav.map((item) => (
                                <NavLink
                                    key={item.key}
                                    to={item.path}
                                    end
                                    className={({ isActive }) => `side-link side-link--primary ${isActive ? 'is-active' : ''}`}
                                    title={t(NAV_LABELS[item.key], item.label)}
                                >
                                    <i className={`side-link-icon ${item.icon || 'fa-solid fa-circle'}`} aria-hidden="true" />
                                    {!collapsed && <span className="side-link-label">{t(NAV_LABELS[item.key], item.label)}</span>}
                                </NavLink>
                            ))}
                        </div>
                        {Object.entries(groupedNav).map(([group, items]) => {
                            const isOpen = collapsed || openGroup === group;

                            return (
                            <div className={`side-group ${isOpen ? 'is-open' : ''}`} key={group}>
                                {!collapsed && (
                                    <button
                                        type="button"
                                        className="side-group__toggle"
                                        aria-expanded={isOpen}
                                        onClick={() => setOpenGroup((current) => current === group ? null : group)}
                                    >
                                        <span>{t(GROUP_LABELS[group], group)}</span>
                                        <i className={`fa-solid fa-chevron-${isOpen ? 'up' : 'down'}`} aria-hidden="true" />
                                    </button>
                                )}
                                <div className="side-group__items">
                                  {items.map((n) => (
                                    <NavLink
                                        key={n.key}
                                        to={n.path}
                                        end={n.path === '/dashboard'}
                                        className={({ isActive }) => `side-link ${isActive ? 'is-active' : ''}`}
                                        title={t(NAV_LABELS[n.key], n.label)}
                                    >
                                        <i className={`side-link-icon ${n.icon || 'fa-solid fa-circle'}`} aria-hidden="true" />
                                        {!collapsed && <span className="side-link-label">{t(NAV_LABELS[n.key], n.label)}</span>}
                                    </NavLink>
                                  ))}
                                </div>
                            </div>
                            );
                        })}
                    </nav>
                ) : (
                    <nav className="side-nav side-nav--locked">
                        {!collapsed && (
                            <div className="side-locked">
                                <i className="fa-solid fa-lock side-locked__icon" aria-hidden="true" />
                                <span>{tenant.isSetup ? t('shell.finishSetup') : tenant.isNoOrg ? t('shell.noOrganization') : tenant.isNoAccess ? t('shell.noAccess') : t('shell.signIn')}</span>
                            </div>
                        )}
                    </nav>
                )}
                <button className="side-collapse" onClick={() => setCollapsed((c) => !c)}
                    aria-label={collapsed ? t('shell.expandSidebar') : t('shell.collapseSidebar')}>
                    {collapsed ? '»' : `« ${t('shell.collapse')}`}
                </button>
            </aside>

            <div className="main">
                <header className="topbar">
                    <div className="topbar-left">
                        <OrgSwitcher tenant={tenant} />
                    </div>
                    <div className="topbar-right">
                        {tenant.loading ? (
                            <span className="badge badge--muted">{t('shell.loading')}</span>
                        ) : !tenant.isLive && (<>
                            {dataChip && (
                                <span className={`badge ${dataChip[1]}`}
                                    title={t('shell.dataMode', undefined, { mode: tenant.dataState })}>{dataChip[0]}</span>
                            )}
                            <span className={`badge ${badgeClass}`} title={t('shell.tenantStatus')}>{tenant.badge}</span>
                        </>)}
                        {/* Demo is a SECONDARY preview option, only offered when there is no live org. */}
                        {!tenant.loading && tenant.mode === 'none' && !tenant.authenticated && tenant.demo_available && (
                            <button className="btn btn--sm" onClick={() => tenant.selectDemo()}>
                                {t('shell.useDemo')}
                            </button>
                        )}
                        {!tenant.loading && tenant.isDemo && (
                            <button className="btn btn--sm" onClick={() => tenant.clear()}>{t('shell.exitDemo')}</button>
                        )}
                        <button
                            className="theme-toggle"
                            onClick={() => setTheme(toggleTheme())}
                            title={t('shell.toggleTheme')}
                        >
                            {theme === 'dark' ? '☾' : '☀'}
                        </button>
                        <button
                            type="button"
                            className="language-toggle"
                            onClick={() => {
                                const next = new URL(window.location.href);
                                next.searchParams.set('locale', locale === 'ar' ? 'en' : 'ar');
                                window.location.assign(next.toString());
                            }}
                            title={t('language')}
                        >
                            {locale === 'ar' ? 'EN' : 'عربي'}
                        </button>
                        {!tenant.loading && (
                            <span className="user-menu" title={tenant.user?.email || t('shell.account')}>
                                {tenant.user?.name || tenant.user?.email || t('shell.account')}
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
                <span>{t('shell.loadingApp')}</span>
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
            <strong>{t('shell.sampleWarning')}</strong>{' '}
            {tenant.authenticated
                ? t('shell.sampleLauncherHint')
                : (tenant.demo_available ? t('shell.sampleDemoHint') : t('shell.sampleSignInHint'))}
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
                <h2>{t('shell.noOrganizationTitle')}</h2>
                <p>{tenant.state_message || t('shell.noOrganizationMessage')}</p>
                <p className="muted">{t('shell.noOrganizationHint')}</p>
            </div>
        );
    }
    if (tenant.isNoAccess) {
        return (
            <div className="state-screen">
                <h2>{t('shell.noAccessTitle')}</h2>
                <p>{tenant.state_message || t('shell.noAccessMessage')}</p>
                <p className="muted">{t('shell.noAccessHint')}</p>
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
    //  - tenant_unmigrated       → SolaStock is enabled and the shared tenant DB
    //                              exists; an admin may initialize tables.
    //  - tenant_missing/schema_failed/unreachable → not ready for inline use.
    //                              These states require an owner-approved
    //                              provisioning/schema action outside the user
    //                              workflow.
    const needsActivation = tenant.state === 'needs_activation';
    const canInitialize = tenant.state === 'tenant_unmigrated' && tenant.can_provision;
    const readinessBlocked = ['tenant_missing', 'tenant_unreachable', 'schema_failed'].includes(tenant.state);
    const isAdminBlocked = readinessBlocked && tenant.can_access;

    const features = [
        ['fa-boxes-stacked', t('shell.featureCatalog'), t('shell.featureCatalogHint')],
        ['fa-layer-group', t('shell.featureStock'), t('shell.featureStockHint')],
        ['fa-truck-fast', t('shell.featureFulfillment'), t('shell.featureFulfillmentHint')],
        ['fa-barcode', t('shell.featureTraceability'), t('shell.featureTraceabilityHint')],
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
                setError(res.message || t('shell.activationAutomaticFailed'));
                if (res.admin_command) setAdminCmd(res.admin_command);
            }
            // On success the refetched status (live_ready) re-renders the shell.
        } catch (e) {
            setError(
                e?.response?.data?.message
                || e?.message
                || t('shell.activationFailed')
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
                    <span className="setup-hero__eyebrow">{t('shell.inventoryName')}</span>
                </div>

                <h1 className="setup-hero__title">
                    {needsActivation
                        ? t('shell.activateTitle', undefined, { organization: tenant.organization_name || t('shell.yourOrganization') })
                        : readinessBlocked
                            ? t('shell.notReadyTitle', undefined, { organization: tenant.organization_name || t('shell.thisWorkspace') })
                            : t('shell.finishTitle', undefined, { organization: tenant.organization_name || t('shell.yourOrganization') })}
                </h1>
                <p className="setup-hero__sub">
                    {needsActivation
                        ? t('shell.activateDescription')
                        : readinessBlocked
                            ? (isAdminBlocked
                                ? (tenant.state === 'schema_failed'
                                    ? t('shell.schemaBlockedDescription')
                                    : t('shell.databaseBlockedDescription'))
                                : t('shell.unavailableDescription'))
                            : t('shell.finishDescription')}
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
                    {readinessBlocked ? (
                        <div className="setup-error" role="status" style={{ color: '#6f4a00', fontSize: 13, maxWidth: 560 }}>
                            <i className="fa-solid fa-shield-halved" /> {isAdminBlocked
                                ? t('shell.adminNextStep')
                                : t('shell.adminReadiness')}
                        </div>
                    ) : (
                        <button
                            type="button"
                            className="btn btn--primary btn--lg"
                            onClick={needsActivation ? startOnboarding : activate}
                            disabled={needsActivation ? false : (busy || !canInitialize)}
                        >
                            {needsActivation
                                ? t('shell.startSetup')
                                : (busy ? t('shell.initializing') : t('shell.finishSetupButton'))}
                        </button>
                    )}

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
