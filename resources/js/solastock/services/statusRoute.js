/** Read-only status destinations. Never point uncertain mutations back at a creation form. */
export function statusRoute(requestUrl, basePath='/inventory') {
    const path=new URL(requestUrl,'https://status.invalid').pathname.split('/api/v1/')[1]||'';
    if(path.startsWith('cash-sales/requests'))return `${basePath}/cash-fulfillment-requests`;
    const [family,id]=path.split('/');
    const documents=['items','customers','suppliers','warehouses','opening-stock','adjustments','transfers','counts','purchase-orders','goods-receipts','sales-orders','pick-lists','packs','shipments','sales-returns','recalls'];
    if(documents.includes(family))return `${basePath}/${family}${/^\d+$/.test(id||'')?`/${id}`:''}`;
    if(['lots','serials'].includes(family))return `${basePath}/traceability/${family}${/^\d+$/.test(id||'')?`/${id}`:''}`;
    if(family==='integration')return `${basePath}/integrations/solacount`;
    if(family==='settings'||family==='custom-roles')return `${basePath}/settings`;
    if(['item-images','item-attachments'].includes(family))return `${basePath}/items`;
    if(family==='warehouse-images')return `${basePath}/warehouses`;
    return `${basePath}/dashboard`;
}
