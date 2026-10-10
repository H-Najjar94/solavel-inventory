import PurchasingNotificationBell from '../components/PurchasingNotificationBell.jsx';
import {FeedbackNavigation} from '../components/FeedbackNavigation';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { visibleNav } from '../router/nav.js';
import { useMeta } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';
import { getTheme, toggleTheme } from '../stores/theme.js';
import { getLocale, t } from '../i18n/index.js';
import { api } from '../services/api.js';

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
    'internal-consumptions':'consumption.title', 'goods-receipts': 'goodsReceipts', 'landed-costs': 'nav.landedCosts', 'receiving-requests':'receiving.requests.nav', customers: 'customers', 'sales-orders': 'salesOrders', 'fulfillment-requests': 'fulfillmentRequests', 'cash-fulfillment-requests':'cashFulfillment',
    'pick-lists': 'picking', packs: 'packing', shipments: 'shipments', 'sales-returns': 'salesReturns',
    traceability: 'traceability', lots: 'lots', serials: 'serials', recalls: 'recalls', reports: 'reports',
    integration: 'solabooks', 'team-access': 'nav.teamAccess', settings: 'settings',
};
const GROUP_LABELS = { Catalog: 'catalog', Stock: 'stock', Operations: 'operations', Purchasing: 'purchasing', 'Sales / Fulfillment': 'sales', Traceability: 'traceability', Insights: 'insights', Admin: 'admin' };

// Quick add: only real create pages, each behind the same permission its route requires.
const QUICK_ACTIONS = [
    { to: '/items/new', label: 'shell.quickItem', icon: 'fa-boxes-stacked', perm: 'inventory.manage_items' },
    { to: '/purchase-orders/new', label: 'shell.quickPurchaseOrder', icon: 'fa-file-invoice', perm: 'inventory.manage_purchase_orders' },
    { to: '/goods-receipts/new', label: 'shell.quickGoodsReceipt', icon: 'fa-dolly', perm: 'inventory.receive_goods' },
    { to: '/sales-orders/new', label: 'shell.quickSalesOrder', icon: 'fa-cart-shopping', perm: 'inventory.manage_sales_orders' },
    { to: '/transfers/new', label: 'shell.quickTransfer', icon: 'fa-right-left', perm: 'inventory.transfer_stock' },
    { to: '/adjustments/new', label: 'shell.quickAdjustment', icon: 'fa-sliders', perm: 'inventory.manage_adjustments' },
    { to: '/counts/new', label: 'shell.quickCount', icon: 'fa-list-check', perm: 'inventory.manage_adjustments' },
];

// Below this width the sidebar is an off-canvas drawer opened from the top bar (same as SolaHR).
const DRAWER_QUERY = '(max-width: 1024px)';

function useMediaQuery(query) {
    const get = () => typeof window !== 'undefined' && !!window.matchMedia && window.matchMedia(query).matches;
    const [matches, setMatches] = useState(get);
    useEffect(() => {
        if (!window.matchMedia) return undefined;
        const mql = window.matchMedia(query);
        const update = () => setMatches(mql.matches);
        update();
        mql.addEventListener ? mql.addEventListener('change', update) : mql.addListener(update);
        return () => { mql.removeEventListener ? mql.removeEventListener('change', update) : mql.removeListener(update); };
    }, [query]);
    return matches;
}

/** Two-letter initials for avatars and the organization pill (works for Arabic names too). */
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : parts[0]?.[1] ?? '')).toUpperCase() || '·';
}

function basePath() {
    return window.SOLASTOCK_BASE_PATH ?? '/inventory';
}

function signOut() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `${basePath()}/logout`;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
}

function switchLanguage(locale) {
    const next = new URL(window.location.href);
    next.searchParams.set('locale', locale === 'ar' ? 'en' : 'ar');
    window.location.assign(next.toString());
}

// Top-bar organization switcher. Lists the signed-in user's organizations and
// switches the active org/client in the SSO session (POST /tenant/select-org),
// then refetches everything so the whole app reloads in the new org's context.
function OrgSwitcher({ tenant, open, onToggle, onClose }) {
    const [busyId, setBusyId] = useState(0);
    const orgs = tenant.organizations || [];
    const currentName = tenant.loading
        ? ' '
        : (tenant.organization_name
            || (tenant.organization_id ? t('shell.organizationNumber', undefined, { id: tenant.organization_id }) : t('shell.noOrganization')));

    async function pick(org) {
        if (org.current || busyId) return;
        setBusyId(org.id);
        try { await tenant.selectOrg(org.id); onClose(); }
        finally { setBusyId(0); }
    }

    // Single (or zero) org → no need for a menu; just show the name.
    if (orgs.length <= 1) {
        return (
            <div className="ss-org-switch">
                <span className="ss-org-pill ss-org-pill--static" title={t('shell.activeOrganization')}>
                    <span className="ss-org-pill__logo" aria-hidden="true">{initials(currentName)}</span>
                    <span className="ss-org-pill__name org-name">{currentName}</span>
                </span>
            </div>
        );
    }

    return (
        <div className="ss-org-switch org-switcher">
            <button type="button" className="ss-org-pill org-switcher__btn" aria-expanded={open} aria-haspopup="menu"
                onClick={onToggle} title={t('shell.switchOrganization')}>
                <span className="ss-org-pill__logo" aria-hidden="true">{initials(currentName)}</span>
                <span className="ss-org-pill__name org-name">{currentName}</span>
                <i className="fa-solid fa-chevron-down ss-org-pill__chevron" aria-hidden="true" />
            </button>
            {open && (
                <div className="ss-menu ss-menu--start" role="menu" style={{ minWidth: 280 }}>
                    <div className="ss-menu__label">{t('shell.yourOrganizations')}</div>
                    <div className="ss-menu__scroll">
                        {orgs.map((org) => (
                            <button key={org.id} type="button" role="menuitem" className={`ss-menu__item${org.current ? ' is-current' : ''}`}
                                onClick={() => pick(org)} disabled={!!busyId} aria-current={org.current ? 'true' : undefined}>
                                <i className={`fa-solid ${org.current ? 'fa-circle-check' : 'fa-building'} ss-menu__icon`} aria-hidden="true" />
                                <span className="ss-menu__text">{org.name}</span>
                                {busyId === org.id
                                    ? <i className="fa-solid fa-spinner fa-spin" aria-hidden="true" />
                                    : <span className={`ss-org-state${org.inventory_enabled ? ' is-on' : ''}`}>
                                        {org.inventory_enabled ? t('shell.active') : t('shell.setup')}
                                      </span>}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// Account menu: identity, Solavel portal card and the other apps (new tab), profile,
// settings, phone-only shortcuts, sign out. The app list loads on first open.
function UserMenu({ tenant, canManageSettings, theme, onTheme, locale, onClose }) {
    const launcher = useQuery({
        queryKey: ['tenant-launcher', tenant.organization_id ?? 0],
        queryFn: async () => (await api.tenantLauncher()).data,
        staleTime: 5 * 60_000,
        retry: false,
        enabled: !!tenant.authenticated,
    });
    const data = launcher.data ?? null;
    const portalUrl = data?.portal?.url ?? tenant.portal_url ?? null;
    const portalLogo = data?.portal?.logo ?? `${basePath()}/imgs/apps/solavel.svg`;
    const apps = data?.apps ?? [];
    const name = tenant.user?.name || tenant.user?.email || t('shell.account');

    return (
        <div className="ss-menu ss-menu--end ss-user-menu" role="menu" aria-label={t('shell.accountMenu')}>
            <div className="ss-user-menu__head">
                <span className="ss-avatar ss-avatar--lg" aria-hidden="true">{initials(name)}</span>
                <div style={{ minWidth: 0 }}>
                    <div className="ss-user-menu__name">{name}</div>
                    {tenant.user?.email && <div className="ss-user-menu__meta">{tenant.user.email}</div>}
                    {tenant.organization_name && <div className="ss-user-menu__meta">{tenant.organization_name}</div>}
                </div>
            </div>
            {portalUrl && (
                <a className="ss-portal-card" href={portalUrl} target="_blank" rel="noopener noreferrer" onClick={onClose}>
                    <span className="ss-portal-card__logo"><img src={portalLogo} alt="" /></span>
                    <span className="ss-portal-card__copy">
                        <strong>{t('shell.portalTitle')}</strong>
                        <small>{t('shell.portalDescription')}</small>
                    </span>
                    <i className="fa-solid fa-arrow-up-right-from-square ss-portal-card__arrow" aria-hidden="true" />
                    <span className="ss-visually-hidden">{t('shell.opensNewTab')}</span>
                </a>
            )}
            {launcher.isLoading && tenant.authenticated && (
                <div className="ss-app-tiles ss-app-tiles--loading" role="status">{t('shell.appsLoading')}</div>
            )}
            {apps.length > 0 && (
                <div className="ss-app-tiles">
                    <div className="ss-menu__label">{t('shell.yourApps')}</div>
                    <div className="ss-app-tiles__grid">
                        {apps.map((app) => (
                            <a key={app.key} className="ss-app-tile" href={app.url} target="_blank" rel="noopener noreferrer" onClick={onClose}
                                title={`${app.name} ${t('shell.opensNewTab')}`}>
                                <img src={app.logo} alt="" />
                                <span>{app.name}</span>
                            </a>
                        ))}
                    </div>
                </div>
            )}
            <div className="ss-menu__sep" />
            {data?.profile_url && (
                <a className="ss-menu__item" role="menuitem" href={data.profile_url} target="_blank" rel="noopener noreferrer" onClick={onClose}>
                    <i className="fa-solid fa-user ss-menu__icon" aria-hidden="true" /><span className="ss-menu__text">{t('shell.profile')}</span>
                    <i className="fa-solid fa-arrow-up-right-from-square ss-menu__trail" aria-hidden="true" />
                </a>
            )}
            {canManageSettings && tenant.ready && (
                <NavLink to="/settings" className="ss-menu__item" role="menuitem" onClick={onClose}>
                    <i className="fa-solid fa-gear ss-menu__icon" aria-hidden="true" /><span className="ss-menu__text">{t('shell.settings')}</span>
                </NavLink>
            )}
            {/* On phones the top bar hides these; on wider screens they live in the bar. */}
            <button type="button" role="menuitem" className="ss-menu__item ss-menu__item--phone" onClick={() => switchLanguage(locale)}>
                <i className="fa-solid fa-globe ss-menu__icon" aria-hidden="true" /><span className="ss-menu__text">{t('shell.language')}</span>
            </button>
            <button type="button" role="menuitem" className="ss-menu__item ss-menu__item--phone" onClick={onTheme}>
                <i className={`fa-solid ${theme === 'dark' ? 'fa-sun' : 'fa-moon'} ss-menu__icon`} aria-hidden="true" /><span className="ss-menu__text">{t('shell.toggleTheme')}</span>
            </button>
            {tenant.authenticated && (<>
                <div className="ss-menu__sep" />
                <button type="button" role="menuitem" className="ss-menu__item is-danger" onClick={signOut}>
                    <i className="fa-solid fa-arrow-right-from-bracket ss-menu__icon ss-flip-rtl" aria-hidden="true" /><span className="ss-menu__text">{t('shell.logout')}</span>
                </button>
            </>)}
        </div>
    );
}

// Ctrl/Cmd+K palette: jump to any page or create action the user can open.
function CommandPalette({ entries, onClose }) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const navigate = useNavigate();
    const inputRef = useRef(null);
    const restoreRef = useRef(typeof document !== 'undefined' ? document.activeElement : null);

    useEffect(() => {
        inputRef.current?.focus();
        const restore = restoreRef.current;
        return () => { if (restore && typeof restore.focus === 'function') restore.focus(); };
    }, []);

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return entries;
        return entries.filter((e) => e.label.toLowerCase().includes(q) || e.to.toLowerCase().includes(q));
    }, [entries, query]);

    useEffect(() => { setActive(0); }, [query]);

    function go(entry) {
        if (!entry) return;
        onClose();
        navigate(entry.to);
    }

    return (
        <div className="ss-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="ss-cmdk" role="dialog" aria-modal="true" aria-label={t('shell.commandHint')}>
                <div className="ss-cmdk__field">
                    <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                    <input
                        ref={inputRef}
                        className="ss-cmdk__input"
                        placeholder={t('shell.commandHint')}
                        aria-label={t('shell.commandHint')}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') { e.preventDefault(); onClose(); }
                            else if (e.key === 'ArrowDown') { e.preventDefault(); setActive((i) => Math.min(i + 1, results.length - 1)); }
                            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
                            else if (e.key === 'Enter') { e.preventDefault(); go(results[active]); }
                        }}
                    />
                    <kbd>Esc</kbd>
                </div>
                <div className="ss-cmdk__list" role="listbox">
                    {results.map((entry, i) => (
                        <button key={`${entry.kind}:${entry.to}`} type="button" role="option" aria-selected={i === active}
                            className={`ss-menu__item${i === active ? ' is-active' : ''}`}
                            onMouseEnter={() => setActive(i)} onClick={() => go(entry)}>
                            <i className={`${entry.icon} ss-menu__icon`} aria-hidden="true" />
                            <span className="ss-menu__text">{entry.label}</span>
                            <span className="ss-cmdk__hint">{entry.group}</span>
                        </button>
                    ))}
                    {results.length === 0 && <div className="ss-cmdk__empty">{t('shell.noResults')}</div>}
                </div>
            </div>
        </div>
    );
}

// Authenticated SolaStock shell: sidebar (permission-aware, icon nav; a drawer on
// narrow screens), and the Solavel top bar shared with SolaHR: organization pill,
// search / command palette, quick add, notifications, language, theme, account menu.
export default function AppShell() {
    const meta = useMeta();
    const tenant = useTenant();
    const location = useLocation();
    const nav = visibleNav(meta.permissions, meta);
    const primaryNav = useMemo(() => nav.filter((item) => item.key === 'dashboard'), [nav]);
    const groupedNav = useMemo(() => groupNav(nav.filter((item) => item.key !== 'dashboard')), [nav]);
    const activeGroup = Object.entries(groupedNav).find(([, items]) => (
        items.some((item) => location.pathname === item.path
            || (item.path !== '/dashboard' && location.pathname.startsWith(`${item.path}/`)))
    ))?.[0] ?? null;
    const [theme, setTheme] = useState(getTheme());
    const [collapsed, setCollapsed] = useState(false);
    const [openGroup, setOpenGroup] = useState(activeGroup);
    const [sideOpen, setSideOpen] = useState(false);
    const [menu, setMenu] = useState(null); // 'org' | 'quick' | 'user' | null
    const [cmdOpen, setCmdOpen] = useState(false);
    const locale = getLocale();
    const isDrawer = useMediaQuery(DRAWER_QUERY);
    const railCollapsed = collapsed && !isDrawer;
    const topbarRef = useRef(null);
    const sidebarRef = useRef(null);
    const burgerRef = useRef(null);
    const drawerCloseRef = useRef(null);
    const permissions = useMemo(() => new Set(meta.permissions ?? []), [meta.permissions]);

    useEffect(() => {
        setOpenGroup(activeGroup);
    }, [activeGroup]);

    const closeDrawer = useCallback((restoreFocus = true) => {
        setSideOpen(false);
        if (restoreFocus) requestAnimationFrame(() => burgerRef.current?.focus());
    }, []);

    // Close overlays on navigation.
    useEffect(() => {
        setSideOpen(false);
        setMenu(null);
        setCmdOpen(false);
    }, [location.pathname]);

    // Leaving drawer mode (rotate / resize) never leaves a stale open drawer.
    useEffect(() => { if (!isDrawer) setSideOpen(false); }, [isDrawer]);

    // Close top bar menus on an outside click or Escape; Escape also closes the drawer.
    useEffect(() => {
        const outside = (event) => { if (!topbarRef.current?.contains(event.target)) setMenu(null); };
        const escape = (event) => {
            if (event.key !== 'Escape') return;
            setMenu(null);
            setSideOpen((open) => {
                if (open) requestAnimationFrame(() => burgerRef.current?.focus());
                return false;
            });
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
    }, []);

    // Ctrl/Cmd+K command palette.
    useEffect(() => {
        const handler = (e) => {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setMenu(null);
                setCmdOpen((v) => !v);
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, []);

    // Drawer focus: move into the drawer when it opens; a closed drawer is inert.
    useEffect(() => {
        const el = sidebarRef.current;
        if (!el) return;
        const hidden = isDrawer && !sideOpen;
        if (hidden) { el.setAttribute('inert', ''); el.setAttribute('aria-hidden', 'true'); }
        else { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); }
        if (isDrawer && sideOpen) requestAnimationFrame(() => drawerCloseRef.current?.focus());
    }, [isDrawer, sideOpen]);

    // Keep Tab inside the open drawer.
    function trapDrawerFocus(event) {
        if (!isDrawer || !sideOpen || event.key !== 'Tab') return;
        const focusable = sidebarRef.current?.querySelectorAll('a[href], button:not([disabled])');
        if (!focusable || focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }

    const toggleMenu = (name) => setMenu((current) => (current === name ? null : name));
    const onTheme = () => setTheme(toggleTheme());

    const badgeClass = tenant.isDemo ? 'badge--demo'
        : tenant.isSetup ? 'badge--warn' : 'badge--warn';

    const dataChip = {
        demo: [t('shell.demoData'), 'badge--demo'],
        setup: [t('shell.setupRequired'), 'badge--warn'],
        sample: [t('shell.samplePreview'), 'badge--warn'],
        no_organization: [t('shell.noOrganization'), 'badge--warn'],
        no_access: [t('shell.noAccess'), 'badge--warn'],
    }[tenant.dataState] ?? null;

    const quickActions = tenant.ready && tenant.hasTenant
        ? QUICK_ACTIONS.filter((action) => permissions.has(action.perm))
        : [];

    const paletteEntries = useMemo(() => {
        if (!tenant.ready) return [];
        const pages = nav.map((n) => ({
            kind: 'page', to: n.path, icon: n.icon || 'fa-solid fa-circle',
            label: t(NAV_LABELS[n.key], n.label), group: t(GROUP_LABELS[n.group], n.group === 'Overview' ? '' : n.group),
        }));
        const actions = quickActions.map((a) => ({ kind: 'action', to: a.to, icon: `fa-solid ${a.icon}`, label: t(a.label), group: t('shell.quickAdd') }));
        return [...pages, ...actions];
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tenant.ready, nav.map((n) => n.key).join(','), quickActions.map((a) => a.to).join(','), locale]);

    return (
        <div className={`app${isDrawer ? ' app--drawer' : ''}`}>
            <a href="#solastock-main-content" className="ss-skip-link">{t('shell.skipToContent')}</a>
            <FeedbackNavigation/>
            <aside
                ref={sidebarRef}
                id="solastock-sidebar"
                className={`sidebar ${railCollapsed ? 'sidebar--collapsed' : ''} ${sideOpen ? 'mobile-open' : ''}`}
                aria-label={t('shell.primaryNavigation')}
                onKeyDown={trapDrawerFocus}
            >
                <div className="side-brand">
                    <img className="side-logo-img" src="/inventory/imgs/favicon-solastock-gradient.svg" alt="SolaStock"
                        onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                    {!railCollapsed && <span className="side-name">SolaStock</span>}
                    {isDrawer && (
                        <button ref={drawerCloseRef} type="button" className="ss-drawer-close" aria-label={t('shell.closeMenu')} onClick={() => closeDrawer()}>
                            <i className="fa-solid fa-xmark" aria-hidden="true" />
                        </button>
                    )}
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
                                    {!railCollapsed && <span className="side-link-label">{t(NAV_LABELS[item.key], item.label)}</span>}
                                </NavLink>
                            ))}
                        </div>
                        {Object.entries(groupedNav).map(([group, items]) => {
                            const isOpen = railCollapsed || openGroup === group;

                            return (
                            <div className={`side-group ${isOpen ? 'is-open' : ''}`} key={group}>
                                {!railCollapsed && (
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
                                        {!railCollapsed && <span className="side-link-label">{t(NAV_LABELS[n.key], n.label)}</span>}
                                    </NavLink>
                                  ))}
                                </div>
                            </div>
                            );
                        })}
                    </nav>
                ) : (
                    <nav className="side-nav side-nav--locked">
                        {!railCollapsed && (
                            <div className="side-locked">
                                <i className="fa-solid fa-lock side-locked__icon" aria-hidden="true" />
                                <span>{tenant.isSetup ? t('shell.finishSetup') : tenant.isNoOrg ? t('shell.noOrganization') : tenant.isNoAccess ? t('shell.noAccess') : t('shell.signIn')}</span>
                            </div>
                        )}
                    </nav>
                )}
                {!isDrawer && (
                    <button className="side-collapse" onClick={() => setCollapsed((c) => !c)}
                        aria-label={collapsed ? t('shell.expandSidebar') : t('shell.collapseSidebar')}>
                        {collapsed ? '»' : `« ${t('shell.collapse')}`}
                    </button>
                )}
            </aside>

            {isDrawer && sideOpen && <div className="ss-drawer-overlay" onMouseDown={() => closeDrawer()} aria-hidden="true" />}

            <div className="main">
                <header className="ss-topbar" ref={topbarRef}>
                    <div className="ss-topbar__start">
                        <button ref={burgerRef} type="button" className="ss-topbar__icon ss-burger"
                            aria-label={t('shell.openMenu')} aria-controls="solastock-sidebar" aria-expanded={sideOpen}
                            onClick={() => { setMenu(null); setSideOpen(true); }}>
                            <i className="fa-solid fa-bars" aria-hidden="true" />
                        </button>

                        <OrgSwitcher tenant={tenant} open={menu === 'org'} onToggle={() => toggleMenu('org')} onClose={() => setMenu(null)} />

                        {tenant.ready && (
                            <button type="button" className="ss-topbar__search" onClick={() => { setMenu(null); setCmdOpen(true); }}
                                aria-label={t('shell.searchPlaceholder')} title={t('shell.searchPlaceholder')}>
                                <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
                                <span className="ss-topbar__search-text">{t('shell.searchPlaceholder')}</span>
                                <kbd>Ctrl K</kbd>
                            </button>
                        )}
                    </div>

                    <div className="ss-topbar__end">
                        {tenant.loading ? (
                            <span className="badge badge--muted">{t('shell.loading')}</span>
                        ) : !tenant.isLive && (<>
                            {dataChip && (
                                <span className={`badge ${dataChip[1]} ss-topbar__chip`}
                                    title={t('shell.dataMode', undefined, { mode: tenant.dataState })}>{dataChip[0]}</span>
                            )}
                            <span className={`badge ${badgeClass} ss-topbar__chip`} title={t('shell.tenantStatus')}>{tenant.badge}</span>
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

                        {quickActions.length > 0 && (
                            <div className="ss-topbar__menu-anchor">
                                <button type="button" className="ss-topbar__icon" aria-label={t('shell.quickAdd')} title={t('shell.quickAdd')}
                                    aria-haspopup="menu" aria-expanded={menu === 'quick'} onClick={() => toggleMenu('quick')}>
                                    <i className="fa-solid fa-circle-plus" aria-hidden="true" />
                                </button>
                                {menu === 'quick' && (
                                    <div className="ss-menu ss-menu--end" role="menu" style={{ minWidth: 230 }}>
                                        <div className="ss-menu__label">{t('shell.quickAdd')}</div>
                                        {quickActions.map((action) => (
                                            <NavLink key={action.to} to={action.to} role="menuitem" className="ss-menu__item" onClick={() => setMenu(null)}>
                                                <i className={`fa-solid ${action.icon} ss-menu__icon`} aria-hidden="true" />
                                                <span className="ss-menu__text">{t(action.label)}</span>
                                            </NavLink>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}

                        {meta.permissions?.includes('inventory.receive_goods') && (
                            <div className="ss-topbar__bell"><PurchasingNotificationBell organizationId={tenant.organization_id} /></div>
                        )}

                        <button type="button" className="ss-topbar__icon ss-topbar__icon--secondary ss-language"
                            onClick={() => switchLanguage(locale)} aria-label={t('language')} title={t('language')}>
                            <i className="fa-solid fa-globe" aria-hidden="true" />
                            <span className="ss-language__text">{t('shell.language')}</span>
                        </button>

                        <button type="button" className="ss-topbar__icon ss-topbar__icon--secondary"
                            onClick={onTheme} aria-label={t('shell.toggleTheme')} title={t('shell.toggleTheme')}>
                            <i className={`fa-solid ${theme === 'dark' ? 'fa-sun' : 'fa-moon'}`} aria-hidden="true" />
                        </button>

                        {!tenant.loading && (<>
                            <span className="ss-topbar__divider" aria-hidden="true" />
                            <div className="ss-topbar__menu-anchor">
                                <button type="button" className="ss-user-pill" aria-label={t('shell.accountMenu')}
                                    title={tenant.user?.email || t('shell.account')}
                                    aria-haspopup="menu" aria-expanded={menu === 'user'} onClick={() => toggleMenu('user')}>
                                    <span className="ss-avatar" aria-hidden="true">{initials(tenant.user?.name || tenant.user?.email || t('shell.account'))}</span>
                                    <i className="fa-solid fa-chevron-down ss-user-pill__chevron" aria-hidden="true" />
                                </button>
                                {menu === 'user' && (
                                    <UserMenu tenant={tenant} canManageSettings={permissions.has('inventory.manage_settings')}
                                        theme={theme} onTheme={onTheme} locale={locale} onClose={() => setMenu(null)} />
                                )}
                            </div>
                        </>)}
                    </div>
                </header>

                <main className="content" id="solastock-main-content" tabIndex={-1}>
                    <TenantContent tenant={tenant} />
                </main>
            </div>

            {cmdOpen && <CommandPalette entries={paletteEntries} onClose={() => setCmdOpen(false)} />}
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
    const meta = useMeta();
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
            {meta.warehouse_scope_empty && <div className="alert alert-info" role="status">{document.documentElement.lang.startsWith('ar') ? 'لم يتم تعيين مستودعات لك بعد. اطلب من مسؤول المؤسسة تعيين المستودعات من صفحة صلاحيات العضو.' : 'No warehouses assigned yet. Ask your organization administrator to assign warehouses from your member permissions page.'}</div>}
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
                    <img src="/inventory/imgs/favicon-solastock-gradient.svg" alt="" className="setup-hero__logo"
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
                        <div className="setup-error" role="status" style={{ color: 'var(--warning)', fontSize: 13, maxWidth: 560 }}>
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
