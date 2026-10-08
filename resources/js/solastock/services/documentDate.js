/** Date-input default in the user’s calendar, without conversion to UTC. */
export function localDocumentDate(value = new Date()) {
    if (!Number.isFinite(value.getTime())) throw new RangeError('Invalid document date');
    return `${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`;
}
