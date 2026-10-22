export function pageRequest(path) {
    path=path.split('?')[0].replace(/\/$/,'')||'/';
    const method=path.endsWith('/new')?'POST':path.endsWith('/edit')?'PUT':'GET';
    path=path.replace(/\/(new|edit)$/,'');
    if(path==='/traceability')path='/traceability/expiry-risk';
    if(path==='/purchase-orders/reorder-suggestions')path='/supplier-terms/reorder';
    path=path.replace(/^\/traceability\/(lots|serials)/,'/$1').replace(/^\/integrations\/solacount/,'/integration/solabooks');
    return {method,path};
}
export function routeAccess(path,meta={},methodOverride) {
    const request=pageRequest(path);if(methodOverride)request.method=methodOverride;
    const rules=meta.ui_access?.routes;if(!rules)return {allowed:true};
    const match=rules.filter(rule=>rule.method===request.method).find(rule=>{
        const pattern=rule.pattern.split('/').map(part=>/^\{[^}]+\}$/.test(part)?'[^/]+':part.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')).join('/');
        return new RegExp('^'+pattern+'$').test(request.path);
    });
    return match||{allowed:true};
}
