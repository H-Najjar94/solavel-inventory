import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useApiQuery } from '../hooks/useApiQuery.js';
import { api } from '../services/api.js';
import { useToast } from '../stores/toast.jsx';
import { Badge, Breadcrumbs, Drawer, EmptyState, Field, Skeleton } from '../components/ui.jsx';
import { t } from '../i18n/index.js';

// Role keys Central can grant for SolaStock, in display order.
const ROLE_ORDER = [
    'inventory_administrator', 'scoped_inventory_manager', 'warehouse_manager', 'warehouse_operator',
    'scoped_inventory_viewer', 'stock_manager', 'warehouse_user',
    'client_manager', 'client_member', 'client_viewer', 'client_accountant',
];

function roleLabel(member) {
    if (member.roles.includes('inventory_administrator')) return t('teamAccess.roleLabel.inventory_administrator');
    if (member.is_owner) return t('teamAccess.owner');
    const known = ROLE_ORDER.filter((key) => member.roles.includes(key));
    if (known.length === 0) return member.roles.length ? member.roles.join(', ') : t('teamAccess.noRole');
    return known.map((key) => t(`teamAccess.roleLabel.${key}`, key)).join(', ');
}

function WarehouseCell({ member }) {
    if (member.full_access) return <span>{t('teamAccess.allWarehouses')}</span>;
    const hidden = member.hidden_warehouse_count || 0;
    if (member.warehouses.length === 0 && hidden === 0) {
        return <Badge tone="warn"><i className="fa-solid fa-triangle-exclamation" aria-hidden="true" /> {t('teamAccess.noWarehouses')}</Badge>;
    }
    return (
        <span>
            {member.warehouses.map((w) => w.name).join(', ')}
            {hidden > 0 && <span className="muted"> {t('teamAccess.hiddenWarehouses', undefined, { count: hidden })}</span>}
        </span>
    );
}

function EditAccessDrawer({ member, warehouses, customRoles, onClose, onSaved }) {
    const toast = useToast();
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(() => new Set(member.warehouse_ids.map(Number)));
    const [roleId, setRoleId] = useState(member.custom_role ? String(member.custom_role.id) : '');
    const [busy, setBusy] = useState('');

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return warehouses;
        return warehouses.filter((w) => `${w.name} ${w.code ?? ''}`.toLowerCase().includes(q));
    }, [warehouses, search]);

    function toggle(id) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }

    async function saveWarehouses() {
        setBusy('warehouses');
        try {
            await api.teamSyncWarehouses(member.id, [...selected]);
            toast.push(t('teamAccess.warehousesSaved'), 'success');
            await onSaved();
        } catch (err) {
            toast.push(err.message || t('teamAccess.saveFailed'), 'error');
        } finally { setBusy(''); }
    }

    async function saveRole() {
        setBusy('role');
        try {
            if (roleId === '') {
                await api.teamUnassignCustomRole(member.id);
                toast.push(t('teamAccess.roleRemoved'), 'success');
            } else {
                await api.teamAssignCustomRole(member.id, Number(roleId));
                toast.push(t('teamAccess.roleSaved'), 'success');
            }
            await onSaved();
        } catch (err) {
            toast.push(err.message || t('teamAccess.saveFailed'), 'error');
        } finally { setBusy(''); }
    }

    const currentRoleMissing = member.custom_role && !customRoles.some((r) => r.id === member.custom_role.id);

    return (
        <Drawer open title={member.name} subtitle={member.email} onClose={onClose} width={480}>
            <p className="muted" style={{ marginTop: 0 }}>{roleLabel(member)}</p>

            <section className="team-access-section">
                <h4>{t('teamAccess.warehouses')}</h4>
                {warehouses.length === 0 ? <p className="muted">{t('teamAccess.noWarehousesYet')}</p> : (
                    <>
                        <input className="input team-access-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('teamAccess.search')} aria-label={t('teamAccess.search')} />
                        <div className="team-access-list" role="group" aria-label={t('teamAccess.warehouses')}>
                            {filtered.length === 0 && <p className="muted">{t('teamAccess.noMatches')}</p>}
                            {filtered.map((w) => (
                                <label key={w.id} className="team-access-option">
                                    <input type="checkbox" checked={selected.has(Number(w.id))} onChange={() => toggle(Number(w.id))} />
                                    <span>{w.name}</span>
                                    {w.code && <span className="muted">{w.code}</span>}
                                </label>
                            ))}
                        </div>
                        <div className="team-access-actions">
                            <span className="muted">{t('teamAccess.selectedCount', undefined, { count: selected.size })}</span>
                            <button type="button" className="btn btn--primary" disabled={busy !== ''} onClick={saveWarehouses}>{t('teamAccess.saveWarehouses')}</button>
                        </div>
                        {selected.size === 0 && <Badge tone="warn">{t('teamAccess.noWarehouses')}</Badge>}
                    </>
                )}
            </section>

            <section className="team-access-section">
                <h4>{t('teamAccess.customRole')}</h4>
                <p className="muted">{t('teamAccess.customRoleHint')}</p>
                <Field label={t('teamAccess.customRole')}>
                    <select className="input team-access-search" value={roleId} onChange={(e) => setRoleId(e.target.value)}>
                        <option value="">{t('teamAccess.noCustomRole')}</option>
                        {currentRoleMissing && <option value={String(member.custom_role.id)}>{member.custom_role.name ?? `#${member.custom_role.id}`} ({t('teamAccess.inactiveRole')})</option>}
                        {customRoles.map((r) => <option key={r.id} value={String(r.id)} disabled={!r.assignable}>{r.name}</option>)}
                    </select>
                </Field>
                <div className="team-access-actions">
                    <span />
                    <button type="button" className="btn btn--primary"
                        disabled={busy !== '' || roleId === (member.custom_role ? String(member.custom_role.id) : '')}
                        onClick={saveRole}>{t('teamAccess.saveRole')}</button>
                </div>
            </section>
        </Drawer>
    );
}

export default function TeamAccessPage() {
    const qc = useQueryClient();
    const toast = useToast();
    const { data, isLoading, error } = useApiQuery(['team-access'], api.teamAccess, { fallback: null });
    const [openId, setOpenId] = useState(null);
    const [query, setQuery] = useState('');
    const deepLinkHandled = useRef(false);

    const members = data?.members ?? [];
    const warehouses = data?.warehouses ?? [];
    const customRoles = data?.custom_roles ?? [];

    // Central's "manage in SolaStock" link lands here with ?central_member=.
    useEffect(() => {
        if (deepLinkHandled.current || !data) return;
        deepLinkHandled.current = true;
        const target = Number(new URLSearchParams(window.location.search).get('central_member') || 0);
        if (!target) return;
        const member = members.find((m) => m.id === target);
        if (member && member.editable) setOpenId(member.id);
        else toast.push(t('teamAccess.memberNotFound'), 'error');
    }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return members;
        return members.filter((m) => `${m.name} ${m.email}`.toLowerCase().includes(q));
    }, [members, query]);

    const openMember = members.find((m) => m.id === openId && m.editable) ?? null;

    return (
        <div>
            <Breadcrumbs items={[{ label: t('teamAccess.title') }]} />
            <header className="page-head"><h1>{t('teamAccess.title')}</h1></header>
            <div className="panel team-access-note">
                <i className="fa-solid fa-circle-info" aria-hidden="true" />
                <p>{t('teamAccess.note')}</p>
            </div>

            {isLoading ? <Skeleton rows={6} /> : error ? (
                <EmptyState title={t('teamAccess.loadError')} hint={error.message} />
            ) : members.length === 0 ? (
                <EmptyState title={t('teamAccess.empty')} />
            ) : (
                <>
                    <div className="toolbar team-access-toolbar">
                        <input className="input" type="search" value={query} onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('teamAccess.searchMembers')} aria-label={t('teamAccess.searchMembers')} />
                    </div>
                    <div className="team-access-table-wrap">
                        <table className="data-table">
                            <thead><tr>
                                <th>{t('teamAccess.member')}</th>
                                <th>{t('teamAccess.role')}</th>
                                <th>{t('teamAccess.warehouses')}</th>
                                <th>{t('teamAccess.customRole')}</th>
                                <th />
                            </tr></thead>
                            <tbody>
                                {visible.map((m) => (
                                    <tr key={m.id}>
                                        <td>
                                            <div className="team-access-name">
                                                <strong>{m.name}</strong>
                                                {m.is_self && <Badge tone="info">{t('teamAccess.you')}</Badge>}
                                            </div>
                                            <div className="muted">{m.email}</div>
                                        </td>
                                        <td>{roleLabel(m)}</td>
                                        <td><WarehouseCell member={m} /></td>
                                        <td>
                                            {m.full_access || !m.custom_role ? <span className="muted">—</span> : (
                                                <span>{m.custom_role.name ?? `#${m.custom_role.id}`}{!m.custom_role.is_active && <span className="muted"> ({t('teamAccess.inactiveRole')})</span>}</span>
                                            )}
                                        </td>
                                        <td className="team-access-cta">
                                            {m.full_access ? <Badge tone="ok">{t('teamAccess.fullAccess')}</Badge>
                                                : m.editable ? <button type="button" className="btn btn--sm" onClick={() => setOpenId(m.id)}>{t('teamAccess.edit')}</button>
                                                    : m.is_self ? null
                                                        : <span className="muted" title={t('teamAccess.notEditable')}><i className="fa-solid fa-lock" aria-label={t('teamAccess.notEditable')} /></span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {data?.truncated && <p className="muted">{t('teamAccess.truncated')}</p>}
                </>
            )}

            {openMember && (
                <EditAccessDrawer
                    key={openMember.id}
                    member={openMember}
                    warehouses={warehouses}
                    customRoles={customRoles}
                    onClose={() => setOpenId(null)}
                    onSaved={() => qc.invalidateQueries({ queryKey: ['team-access'] })}
                />
            )}
        </div>
    );
}
