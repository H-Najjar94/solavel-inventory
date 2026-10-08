import React, { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '../services/api.js';
import { useMeta } from '../stores/meta.jsx';
import { useToast } from '../stores/toast.jsx';
import { t } from '../i18n/index.js';

// Settings → Landed costs. Availability follows the plan; this card records only
// an explicit organization opt-out (or withdraws it). Nothing is posted.
export default function LandedCostSettingsCard() {
    const meta = useMeta();
    const toast = useToast(); const qc = useQueryClient();
    const [busy, setBusy] = useState(false);
    const status = meta.landed_costs;
    if (!status) return null;

    async function toggle(optedOut) {
        if (busy) return;
        setBusy(true);
        try {
            await api.setLandedCostPreference(optedOut);
            toast.push(t('landedCosts.settings.saved'), 'success');
            await qc.invalidateQueries({ queryKey: ['meta'] });
            qc.invalidateQueries({ queryKey: ['landed-costs'] });
        } catch (e) { toast.failure(e, e.message); }
        finally { setBusy(false); }
    }

    const summary = !status.entitled ? t('landedCosts.settings.notInPlan')
        : status.opted_out ? t('landedCosts.settings.optedOut') : t('landedCosts.settings.available');

    return (
        <div className="panel">
            <h2>{t('landedCosts.settings.title')}</h2>
            <p role="status">{summary}</p>
            {status.entitled && (status.preference_supported ? (
                <label className="check-inline"><input type="checkbox" checked={Boolean(status.opted_out)} disabled={busy}
                    onChange={(e) => toggle(e.target.checked)} /> {t('landedCosts.settings.toggle')}</label>
            ) : <p className="muted">{t('landedCosts.settings.pending')}</p>)}
            <p className="muted">{t('landedCosts.settings.hint')}</p>
        </div>
    );
}
