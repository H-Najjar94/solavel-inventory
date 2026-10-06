// Accept only server field messages, never machine codes or unrelated validation keys.
export function decisionFieldErrors(error) {
    const fields = error?.payload?.errors || {};
    return Object.fromEntries(Object.entries(fields).flatMap(([key, value]) => {
        if (!key.startsWith('decisions.')) return [];
        const fingerprint = key.slice('decisions.'.length);
        const message = Array.isArray(value) ? value[0] : value;
        return fingerprint && typeof message === 'string' && message.trim()
            ? [[fingerprint, message]] : [];
    }));
}

export function actionableDecisionError(errors, sections, pageSize) {
    for (const [section, rows] of sections) {
        const index = rows.findIndex(row => errors[row.fingerprint]
            && [row.solastock_record_ids, row.solabooks_record_ids].some(ids => Array.isArray(ids) && ids.length > 0));
        if (index >= 0) return { section, fingerprint: rows[index].fingerprint, page: Math.floor(index / pageSize) + 1 };
    }
    return null;
}
