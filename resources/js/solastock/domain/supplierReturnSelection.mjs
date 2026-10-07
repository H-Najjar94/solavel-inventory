export function returnLines(lines, quantities) {
    return lines.flatMap(line => {
        const value = String(quantities[line.source_stock_ledger_id] ?? '0').trim();
        if (!/^\d+(?:\.\d{1,4})?$/.test(value)) throw new Error('quantity');
        const quantity = Number(value);
        if (!Number.isFinite(quantity) || quantity > Number(line.remaining_entered_quantity)) throw new Error('quantity');
        return quantity > 0 ? [{goods_receipt_line_id: line.goods_receipt_line_id, source_stock_ledger_id: line.source_stock_ledger_id, entered_qty: value}] : [];
    });
}
