export function transferValidation(header, lines, tracking, post, t) {
    const errors = {};
    if (!header.from_warehouse_id) errors.from_warehouse_id = t('transfers.form.sourceRequired');
    if (!header.to_warehouse_id) errors.to_warehouse_id = t('transfers.form.destinationRequired');
    if (header.from_warehouse_id && header.from_warehouse_id === header.to_warehouse_id) errors.to_warehouse_id = t('transfers.form.warehousesDiffer');
    if (!lines.length) errors.lines = t('transfers.form.lineRequired');
    lines.forEach((line,i) => {
        const add=(field,key)=>{ errors[`lines.${i}.${field}`]=t(key,undefined,{line:i+1}); };
        if (!line.item_id) { add('item_id','transfers.form.itemRequired'); return; }
        const serial=tracking.tracksSerial(line.item_id);
        if ((!serial || !(line.serial_ids??[]).length) && (!Number.isFinite(Number(line.quantity)) || Number(line.quantity)<=0)) add('quantity','transfers.form.quantityRequired');
        if (post && tracking.tracksLot(line.item_id) && !line.lot_id) add('lot_id','transfers.form.lotRequired');
        if (post && serial && !(line.serial_ids??[]).length) add('serial_id','transfers.form.serialRequired');
        if (serial && (line.serial_ids??[]).length>1) add('serial_id','transfers.form.serialSingle');
    });
    return errors;
}
