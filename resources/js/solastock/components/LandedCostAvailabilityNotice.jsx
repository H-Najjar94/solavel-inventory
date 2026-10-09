import React from 'react';
import { Link } from 'react-router-dom';
import { EmptyState } from './ui.jsx';
import { t } from '../i18n/index.js';

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

    return <EmptyState title={t('landedCosts.availability.unavailable')} hint={availability.entitlement_reason ?? ''} />;
}
