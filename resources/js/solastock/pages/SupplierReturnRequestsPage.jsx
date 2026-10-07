import React, {useRef, useState} from 'react';
import {Link} from 'react-router-dom';
import {api} from '../services/api';
import {useApiQuery} from '../hooks/useApiQuery';
import {useTenant} from '../stores/tenant';
import {useCan} from '../stores/meta';
import {useI18n} from '../i18n/context';
import {EmptyState, Skeleton} from '../components/ui';

export default function SupplierReturnRequestsPage() {
 const tenant=useTenant(), {locale}=useI18n(), ar=locale==='ar';
 const can=useCan(), mayPost=can('inventory.manage_purchase_returns');
 const query=useApiQuery(['supplier-return-requests',tenant.organization_id],()=>api.supplierReturnRequests(),{fallback:[]});
 const [confirmed,setConfirmed]=useState({}),[busy,setBusy]=useState(null),[error,setError]=useState(''),lock=useRef(false);
 const rows=Array.isArray(query.data)?query.data:[];
 const states={requested:ar?'بانتظار الإرجاع الفعلي':'Awaiting physical return',physical_pending:ar?'تأكيد الإرجاع قيد المتابعة':'Return confirmation pending',posted:ar?'تم تأكيد الإرجاع':'Return confirmed',cancelled:ar?'ملغى':'Cancelled',reversed:ar?'معكوس':'Reversed'};
 async function post(row) {
  if(lock.current||!confirmed[row.operation_uuid]||!row.can_post||!mayPost)return;
  lock.current=true;setBusy(row.operation_uuid);setError('');
  try {await api.postSupplierReturnRequest(row.operation_uuid,{arrival_confirmed:true});setConfirmed(v=>({...v,[row.operation_uuid]:false}));await query.refetch();}
  catch(e){setError(e.message);await query.refetch();}
  finally{lock.current=false;setBusy(null);}
 }
 return <section className="page"><header className="page-head"><h1>{ar?'طلبات إرجاع المورد':'Supplier return requests'}</h1></header>
 <p>{ar?'راجع الأصناف والكميات ثم أكّد تسليم البضاعة المرتجعة للمورد. إشعار الخصم والاسترداد المالي إجراءات منفصلة.':'Review the items and quantities, then confirm the goods were physically returned to the supplier. Supplier credit and refund are separate actions.'}</p>
 {error&&<div role="alert" className="banner banner--warn">{error}</div>}
 {query.isError?<div role="alert" className="banner banner--warn">{ar?'تعذر تحميل الطلبات. تحقق من صلاحياتك وأعد المحاولة.':'Requests could not be loaded. Check your access and retry.'}<button type="button" className="btn" onClick={()=>query.refetch()}>{ar?'إعادة المحاولة':'Retry'}</button></div>:query.isLoading?<Skeleton/>:rows.length===0?<EmptyState title={ar?'لا توجد طلبات إرجاع':'No supplier return requests'} hint={ar?'تظهر هنا طلبات المحاسب المصرح لك بمستودعاتها.':'Requests from the accountant appear here when you have access to their warehouses.'}/>:rows.map(row=><article className="card" key={row.operation_uuid} style={{padding:16,marginBottom:16,overflowWrap:'anywhere'}}>
 <h2>{ar?'فاتورة المورد':'Supplier bill'} {row.source_bill_number||`#${row.source_bill_id}`}</h2><p>{states[row.state]??(ar?'يلزم المراجعة':'Needs review')} · {row.return_date}</p>
 <p><Link to={`/goods-receipts/${row.goods_receipt_id}`}>{row.grn_number||`#${row.goods_receipt_id}`}</Link>{row.return_number&&<> · {row.return_number}</>}</p>
 {row.reason&&<p>{row.reason}</p>}
 <div style={{overflowX:'auto'}}><table className="data-table"><thead><tr><th>{ar?'الصنف':'Item'}</th><th>{ar?'كمية الإرجاع':'Return quantity'}</th><th>{ar?'الوحدة':'Unit'}</th></tr></thead><tbody>{row.lines.map((line,index)=><tr key={`${line.source_receipt_line_id}-${index}`}><td>{line.item_name||`#${line.source_receipt_line_id}`}</td><td>{line.entered_quantity}</td><td>{line.unit_name||`#${line.unit_id}`}</td></tr>)}</tbody></table></div>
 {row.can_post&&mayPost&&<div><label><input type="checkbox" checked={!!confirmed[row.operation_uuid]} disabled={busy!==null} onChange={event=>setConfirmed(v=>({...v,[row.operation_uuid]:event.target.checked}))}/>{ar?'أؤكد تسليم هذه الأصناف والكميات فعلياً للمورد.':'I confirm these items and quantities were physically returned to the supplier.'}</label><p><button type="button" className="btn btn--primary" disabled={busy!==null||!confirmed[row.operation_uuid]} onClick={()=>post(row)}>{busy===row.operation_uuid?(ar?'جارٍ التأكيد…':'Confirming…'):(ar?'تأكيد إرجاع البضاعة':'Confirm returned goods')}</button></p></div>}
 </article>)}
 </section>;
}
