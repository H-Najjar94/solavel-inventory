import React from 'react';
import { Link } from 'react-router-dom';
import { EmptyState } from './ui.jsx';
import { t } from '../i18n/index.js';

// Entitlement reason codes (EntitlementAccessDecision / InventoryCommercialEntitlementService) mapped to
// translated hints. Any other code gets the generic hint; the raw code is never shown.
const REASON_HINTS = {
    no_entitlement: 'landedCosts.availability.reason.noEntitlement',
    entitlement_unverified: 'landedCosts.availability.reason.unverified',
    entitlement_service_unavailable: 'landedCosts.availability.reason.unverified',
    subscription_expired: 'landedCosts.availability.reason.expired',
    subscription_revoked: 'landedCosts.availability.reason.revoked',
    subscription_not_access_eligible: 'landedCosts.availability.reason.notEligible',
};

// Explains why landed costs are unavailable (plan or explicit opt-out). Returns
// null while they are available or before /meta has said anything.
export default function LandedCostAvailabilityNotice({ availability, canManageSettings }) {
    if (!availability || availability.available !== false) return null;
    if (availability.reason === 'opted_out') {
        return <EmptyState title={t('landedCosts.availability.optedOutTitle')} hint={t('landedCosts.availability.optedOut')}
            action={canManageSettings ? <Link to="/settings" className="btn">{t('landedCosts.availability.openSettings')}</Link> : null} />;
    }
    if (availability.reason === 'not_in_plan') {
        return <EmptyState title={t('landedCosts.availability.notInPlanTitle')} hint={t('landedCosts.availability.notInPlan')} />;
    }

    const hint = REASON_HINTS[availability.entitlement_reason] ?? 'landedCosts.availability.reason.generic';
    return <EmptyState title={t('landedCosts.availability.unavailable')} hint={t(hint)} />;
}
