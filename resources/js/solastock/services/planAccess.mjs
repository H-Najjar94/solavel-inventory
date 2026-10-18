import {routeAccess} from './uiAccess.mjs';
// Read access and create access are deliberately separate: old records remain readable.
export const accessAreas=[
 ['items','/items'],['warehouses','/warehouses'],['transfers','/transfers'],
 ['suppliers','/suppliers'],['purchaseOrders','/purchase-orders'],['goodsReceipts','/goods-receipts'],
 ['customers','/customers'],['salesOrders','/sales-orders'],['counts','/counts'],
 ['openingStock','/opening-stock'],['recalls','/recalls'],['landedCosts','/landed-costs'],
];
export function accessKind(reason){
 if(reason==='permission_denied')return 'role';
 if(reason==='feature_not_in_plan')return 'plan';
 if(['entitlement_service_unavailable','entitlement_verification_stale'].includes(reason))return 'check';
 return 'subscription';
}
export function areaAccess(path,meta){
 if(!meta.ui_access?.routes?.some(r=>r.pattern===path&&r.method==='GET'))return null;
 const read=routeAccess(path,meta),create=routeAccess(path,meta,'POST');
 if(!read.allowed&&read.reason==='permission_denied')return null;
 return {read,create,allowed:read.allowed&&create.allowed,reason:!read.allowed?read.reason:!create.allowed?create.reason:null};
}
