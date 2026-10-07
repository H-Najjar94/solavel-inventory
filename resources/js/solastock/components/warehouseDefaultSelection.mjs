// The list is already authorization-scoped by the native warehouses endpoint.
export function eligibleDefaultWarehouse(list, defaultId) {
 const active = list.filter(w => ![false, 0, '0'].includes(w.is_active));
 if (defaultId != null) return active.find(w => String(w.id) === String(defaultId))?.id ?? null;
 const defaults = active.filter(w => [true, 1, '1'].includes(w.is_default));
 return defaults.length === 1 ? defaults[0].id : null;
}
export function defaultWarehouseDecision({enabled, disabled, loaded, value, attempted, list, defaultId}) {
 if (!enabled || disabled || !loaded || attempted) return {attempted, selected:null};
 if (value != null && value !== '') return {attempted:true, selected:null};
 return {attempted:true, selected:eligibleDefaultWarehouse(list,defaultId)};
}
