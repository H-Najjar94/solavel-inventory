// Quantities are stored/validated to 6 dp max; trim bcmath/DECIMAL padding ("10.000000000000000000" -> "10").
export const normalizeQty = (v) => {
    if (v === null || v === undefined || v === '') return v ?? '';
    const n = Number(v);
    if (!Number.isFinite(n)) return String(v);
    const s = n.toFixed(6);
    return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
};
