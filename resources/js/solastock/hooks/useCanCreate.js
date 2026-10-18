import {useLocation} from 'react-router-dom';
import {routeAccess} from '../services/uiAccess.mjs';
import {useMeta} from '../stores/meta.jsx';
import { useCan } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';

/**
 * Whether a create/post action is allowed: requires BOTH the permission AND an
 * active tenant. Returns { allowed, reason } so buttons can be disabled with a
 * clear tooltip when there's no tenant.
 */
export function useCanCreate(permission) {
    const can = useCan();const meta=useMeta();const location=useLocation();
    const tenant = useTenant();

    if (!tenant.hasTenant) {
        return { allowed: false, reason: 'Select a tenant to create or post records.' };
    }
    if (permission && !can(permission)) {
        return { allowed: false, reason: 'You do not have permission for this action.' };
    }
    if(!routeAccess(location.pathname,meta,/\/(?:\d+)(?:\/edit)?$/.test(location.pathname)?'PUT':'POST').allowed)return {allowed:false,reason:'Your current access does not permit this action.'};
    return { allowed: true, reason: '' };
}
