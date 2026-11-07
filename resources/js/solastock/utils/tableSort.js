// Sort cycle + client-side comparator shared by every SolaStock table.
// Cycle: none -> ascending -> descending -> none (page default order).
export function nextSort(state, key) {
    if (state.key !== key) return { key, dir: 'asc' };
    if (state.dir === 'asc') return { key, dir: 'desc' };
    return { key: null, dir: 'asc' };
}

const isEmpty = (v) => v === null || v === undefined || v === '';

export function compareValues(a, b, locale) {
    const na = typeof a === 'number' ? a : (typeof a === 'string' && a.trim() !== '' && !isNaN(Number(a)) ? Number(a) : null);
    const nb = typeof b === 'number' ? b : (typeof b === 'string' && b.trim() !== '' && !isNaN(Number(b)) ? Number(b) : null);
    if (na !== null && nb !== null) return na - nb;
    if (a instanceof Date && b instanceof Date) return a - b;
    const sa = String(a), sb = String(b);
    // ISO-like dates compare correctly as strings, but localeCompare with numeric handles both.
    return sa.localeCompare(sb, locale || undefined, { numeric: true, sensitivity: 'base' });
}

/** Sort rows in the browser. accessors: { key: row => value }. Empty values always last. */
export function sortRows(rows, state, accessors = {}, locale) {
    if (!state?.key) return rows;
    const get = accessors[state.key] || ((r) => r?.[state.key]);
    const sign = state.dir === 'desc' ? -1 : 1;
    return rows
        .map((row, i) => ({ row, i, v: get(row) }))
        .sort((x, y) => {
            const ex = isEmpty(x.v), ey = isEmpty(y.v);
            if (ex || ey) return ex === ey ? x.i - y.i : (ex ? 1 : -1);
            return (compareValues(x.v, y.v, locale) * sign) || (x.i - y.i);
        })
        .map((e) => e.row);
}
