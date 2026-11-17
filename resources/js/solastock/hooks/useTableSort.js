import { useCallback, useMemo, useState } from 'react';
import { nextSort, sortRows } from '../utils/tableSort.js';
import { getLocale } from '../i18n/index.js';

/**
 * Column sort state for a table.
 * Server lists: spread `sort.params` into the API call and add `sort.key, sort.dir` to the
 * query key / page-reset effect. Client lists: `sort.apply(rows, accessors)`.
 */
export function useTableSort() {
    const [state, setState] = useState({ key: null, dir: 'asc' });
    const toggle = useCallback((key) => setState((s) => nextSort(s, key)), []);
    const reset = useCallback(() => setState({ key: null, dir: 'asc' }), []);
    const params = useMemo(() => (state.key ? { sort: state.key, dir: state.dir } : {}), [state]);
    const apply = useCallback((rows, accessors) => sortRows(rows, state, accessors, getLocale()), [state]);
    return { key: state.key, dir: state.dir, toggle, reset, params, apply };
}
