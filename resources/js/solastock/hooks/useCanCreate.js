import { useCan } from '../stores/meta.jsx';
import { useTenant } from '../stores/tenant.jsx';

/**
 * Whether a create/post action is allowed: requires BOTH the permission AND an
 * active tenant. Returns { allowed, reason } so buttons can be disabled with a
 * clear tooltip when there's no tenant.
 */
export function useCanCreate(permission) {
    const can = useCan();
    const tenant = useTenant();

    if (!tenant.hasTenant) {
        return { allowed: false, reason: 'Select a tenant to create or post records.' };
    }
    if (permission && !can(permission)) {
        return { allowed: false, reason: 'You do not have permission for this action.' };
    }
    return { allowed: true, reason: '' };
}
