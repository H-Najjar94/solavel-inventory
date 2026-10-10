import React, {useState,useRef,useEffect} from 'react';
import {Link,useNavigate,useParams,useSearchParams} from 'react-router-dom';
import {useQuery} from '@tanstack/react-query';
import {api} from '../services/api.js';
import {useCan} from '../stores/meta.jsx';
import {useToast} from '../stores/toast.jsx';
import {Field,Skeleton,EmptyState} from '../components/ui.jsx';
import {DocumentLinesTable} from '../components/document.jsx';
import {WarehousePicker,UnitPicker,BinPicker} from '../components/pickers.jsx';
import {LotSelector as LotPicker,SerialSelector} from '../components/traceability.jsx';
import {t} from '../i18n/index.js';
const label=(key)=>t(`consumption.${key}`);
const body=(r)=>r?.data??r;
const rows=(r)=>Array.isArray(body(r))?body(r):(body(r)?.data??[]);
const today=()=>new Date().toLocaleDateString('en-CA');

function Pages({response,page,onChange}) {
    const meta=response?.meta??body(response)??{};
    return Number(meta.last_page)>1?<nav className="focus-pagination" aria-label={label('pages')}><button className="btn" disabled={page<=1} onClick={()=>onChange(page-1)}>{label('previous')}</button><bdi>{page} / {meta.last_page}</bdi><button className="btn" disabled={page>=Number(meta.last_page)} onClick={()=>onChange(page+1)}>{label('next')}</button></nav>:null;
}
function SearchItem({value,onChange}) {
    const [search,setSearch]=useState('');
    const q=useQuery({queryKey:['consumption-items',search],queryFn:()=>api.items({search,is_active:true,item_type:'inventory',per_page:100})});
    return <div><input className="input" aria-label={label('search')} placeholder={label('search')} value={search} onChange={e=>setSearch(e.target.value)}/>
        <select className="input" aria-label={label('item')} value={value??''} onChange={e=>onChange(Number(e.target.value)||null)}><option value="">—</option>{rows(q.data).map(i=><option key={i.id} value={i.id}>{i.sku} · {i.name}</option>)}</select></div>;
}
function DimensionFields({value,onChange}) {
    const q=useQuery({queryKey:['consumption-options'],queryFn:api.consumptionOptions});const options=body(q.data)??{};
    return <>{[['department_id','departments','department'],['project_id','projects','project']].map(([key,source,labelKey])=>(options[source]?.length>0?<Field key={key} label={label(labelKey)}><select className="input" value={value[key]??''} onChange={e=>onChange({...value,[key]:e.target.value?Number(e.target.value):null})}><option value="">—</option>{options[source].map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select></Field>:null))}</>;
}
function AccountChoice({value,onChange}) {
    const q=useQuery({queryKey:['consumption-options'],queryFn:api.consumptionOptions});
    return <select className="input" value={value??''} onChange={e=>onChange(e.target.value?Number(e.target.value):null)}><option value="">{label('automatic')}</option>{(body(q.data)?.accounts??[]).map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select>;
}
function LinePreview({line,warehouse}) {
    const q=useQuery({queryKey:['consumption-preview',line.item_id,warehouse,line.account_override_id,line.quantity,line.entered_unit_id,line.bin_id,line.lot_id],queryFn:()=>api.consumptionPreview({item_id:line.item_id,warehouse_id:warehouse,account_override_id:line.account_override_id||undefined,quantity:line.quantity,entered_unit_id:line.entered_unit_id,bin_id:line.bin_id,lot_id:line.lot_id}),enabled:!!(line.item_id&&warehouse),retry:false});
    const d=body(q.data);
    if(q.error)return <span className="field-error">{q.error.message} <Link to="/integrations/solabooks">{label('settings')}</Link></span>;
    return <div>{label('available')}: {d?.available_quantity??'—'}<br/>{label('cost')}: {d?.total_cost??'—'}<br/>{label('account')}: {d?.account?.account_name??d?.account?.account_id??(d?.account?.connected===false?label('standalone'):'—')}</div>;
}
export function ConsumptionForm() {
    const [params]=useSearchParams();const sourceId=params.get('issue');const nav=useNavigate();const can=useCan();const toast=useToast();
    const [form,setForm]=useState({document_date:today(),warehouse_id:null,reason:'',notes:'',recipient:''});
    const [lines,setLines]=useState([{item_id:null,quantity:'1'}]);const [busy,setBusy]=useState(false);const pending=useRef(false);
    const submission=useRef(crypto.randomUUID());const [error,setError]=useState('');const [uncertain,setUncertain]=useState(false);
    const original=useQuery({queryKey:['consumption',sourceId],queryFn:()=>api.consumption(sourceId),enabled:!!sourceId});
    useEffect(()=>{const doc=body(original.data)?.document;if(doc){setForm(f=>({...f,warehouse_id:doc.warehouse_id}));setLines(doc.lines.filter(l=>Number(l.quantity)>Number(l.returned_quantity??0)).map(l=>({original_line_id:l.id,item_id:l.item_id,item_name:l.item?.name,quantity:String((Number(l.quantity)-Number(l.returned_quantity??0))/Number(l.conversion?.unit_conversion_factor??1)),entered_unit_id:l.conversion?.entered_unit_id})));}},[original.data]);
    const patch=(i,data)=>setLines(ls=>ls.map((l,n)=>n===i?{...l,...data}:l));
    async function save(e){e.preventDefault();if(pending.current||uncertain)return;pending.current=true;setBusy(true);setError('');
        try{const doc=body(await api.createConsumption({...form,submission_key:submission.current,...(sourceId?{original_issue_id:Number(sourceId)}:{}),lines}));nav(`/internal-consumptions/${doc.id}`);}
        catch(err){setError(err.message);if(err.outcomeUnknown)setUncertain(true);toast.failure(err,err.message);}finally{pending.current=false;setBusy(false);}}
    const columns=[{key:'item',label:label('item'),render:(l,i)=>sourceId?l.item_name:<SearchItem value={l.item_id} onChange={v=>patch(i,{item_id:v})}/>},
        {key:'quantity',label:label('quantity'),render:(l,i)=><input className="input" type="number" min="0.0001" step="0.0001" value={l.quantity} onChange={e=>patch(i,{quantity:e.target.value})}/>},
        {key:'unit',label:label('uom'),render:(l,i)=><UnitPicker disabled={!!sourceId} value={l.entered_unit_id} onChange={v=>patch(i,{entered_unit_id:v})}/>},
        ...(!sourceId?[{key:'bin',label:label('bin'),render:(l,i)=><BinPicker warehouseId={form.warehouse_id} value={l.bin_id} onChange={v=>patch(i,{bin_id:v})}/>},
        {key:'lot',label:label('lot'),render:(l,i)=><LotPicker itemId={l.item_id} warehouseId={form.warehouse_id} value={l.lot_id} onChange={v=>patch(i,{lot_id:v})}/>},
        {key:'serial',label:label('serial'),render:(l,i)=><SerialSelector itemId={l.item_id} warehouseId={form.warehouse_id} value={l.serial_id?[l.serial_id]:[]} expectedQty={1} onChange={v=>patch(i,{serial_id:v[v.length-1]??null,quantity:'1'})}/>},
        {key:'preview',label:label('cost'),render:l=><LinePreview line={l} warehouse={form.warehouse_id}/>}]:[]),
        ...(can('inventory.consumption.override_account')&&!sourceId?[{key:'override',label:label('override'),render:(l,i)=><AccountChoice value={l.account_override_id} onChange={v=>patch(i,{account_override_id:v})}/>}]:[])];
    return <section className="page"><h1>{sourceId?label('return'):label('new')}</h1><form onSubmit={save}>
        <div className="form-grid"><Field label={label('date')}><input className="input" type="date" required value={form.document_date} onChange={e=>setForm({...form,document_date:e.target.value})}/></Field>
        <Field label={label('warehouse')}><WarehousePicker autoSelectDefault disabled={!!sourceId} value={form.warehouse_id} onChange={v=>setForm({...form,warehouse_id:v})}/></Field>
        <Field label={label('reason')}><input className="input" required minLength={3} maxLength={500} value={form.reason} onChange={e=>setForm({...form,reason:e.target.value})}/></Field>
        {!sourceId&&<DimensionFields value={form} onChange={setForm}/>}<Field label={label('recipient')}><input className="input" value={form.recipient} onChange={e=>setForm({...form,recipient:e.target.value})}/></Field>
        <Field label={label('notes')}><textarea className="input" value={form.notes} onChange={e=>setForm({...form,notes:e.target.value})}/></Field></div>
        <DocumentLinesTable columns={columns} lines={lines} onAdd={sourceId?undefined:()=>setLines([...lines,{item_id:null,quantity:'1'}])} onRemove={i=>setLines(lines.filter((_,n)=>n!==i))} addLabel={label('add')}/>
        {error&&<p role="alert" className="field-error">{error}</p>}{uncertain&&<p role="alert">{label('unknown')} <Link target="_blank" to="/internal-consumptions">{label('retry')}</Link></p>}
        <button className="btn btn--primary" disabled={busy||uncertain||!lines.length}>{label('save')}</button>
    </form></section>;
}
export function ConsumptionDetail() {
    const {id}=useParams();const can=useCan();const toast=useToast();const [busy,setBusy]=useState(false);const [error,setError]=useState('');
    const q=useQuery({queryKey:['consumption',id],queryFn:()=>api.consumption(id),refetchInterval:15000});
    const d=body(q.data)?.document;const accounting=body(q.data)?.accounting;
    async function action(name){if(busy)return;setBusy(true);try{await api[name](id);await q.refetch();}catch(e){setError(e.message);toast.failure(e,e.message);}finally{setBusy(false);}}
    if(q.isLoading)return <Skeleton/>;if(!d)return <EmptyState title={q.error?.message}/>;
    const cols=[{key:'item',label:label('item'),render:l=>l.item?.name??l.item_id},{key:'quantity',label:label('quantity'),render:l=>l.quantity},
        {key:'returned',label:label('returned_quantity'),render:l=>l.returned_quantity??0},{key:'cost',label:label('cost'),render:l=>l.total_cost??'—'},
        {key:'account',label:label('account'),render:l=>l.expense_account_id??label('standalone')}];
    return <section className="page"><h1>{label('title')} · <bdi>{d.document_number}</bdi></h1><p>{label(d.status)} · {d.document_date?.slice(0,10)} · {d.warehouse?.name}</p>
        <p>{d.reason}</p><p>{d.notes}</p>{d.original_issue_id&&<Link to={`/internal-consumptions/${d.original_issue_id}`}>{label('original')}</Link>}
        <DocumentLinesTable readOnly columns={cols} lines={d.lines}/>
        <p role="status">{label(accounting?.status??'pending')}</p>{accounting?.last_error&&<p role="alert">{accounting.last_error}</p>}
        {d.accounting_connected&&<Link to="/integrations/events">{label('retry')}</Link>}
        {accounting?.journal_url&&<a href={accounting.journal_url} target="_blank" rel="noreferrer">{label('journal')} · {accounting.external_document_id}</a>}
        {d.status==='draft'&&can('inventory.consumption.approve')&&<button className="btn" disabled={busy} onClick={()=>action('approveConsumption')}>{label('approve')}</button>}
        {['draft','approved'].includes(d.status)&&can(`inventory.consumption.${d.kind==='return'?'return':'post'}`)&&<button className="btn btn--primary" disabled={busy} onClick={()=>action('postConsumption')}>{label('post')}</button>}
        {d.status==='posted'&&d.kind==='issue'&&can('inventory.consumption.return')&&<Link className="btn" to={`/internal-consumptions/new?issue=${d.id}`}>{label('return')}</Link>}
        {error&&<p role="alert" className="field-error">{error}</p>}
    </section>;
}
export default function InternalConsumptionPage() {
    const can=useCan();const [kind,setKind]=useState('issue');const [page,setPage]=useState(1);
    const q=useQuery({queryKey:['consumptions',kind,page],queryFn:()=>api.consumptions({kind,page})});
    return <section className="page"><h1>{label('title')}</h1><div className="toolbar"><button className="btn" onClick={()=>{setKind('issue');setPage(1);}}>{label('issues')}</button><button className="btn" onClick={()=>{setKind('return');setPage(1);}}>{label('returns')}</button>
        {can('inventory.consumption.create')&&<Link className="btn btn--primary" to="/internal-consumptions/new">{label('new')}</Link>}<Link className="btn" to="/internal-consumptions/report">{label('report')}</Link></div>
        {q.error&&<p role="alert">{q.error.message}</p>}
        <DocumentLinesTable readOnly lines={rows(q.data)} columns={[{key:'number',label:label('number'),render:d=><Link to={`/internal-consumptions/${d.id}`}>{d.document_number}</Link>},{key:'date',label:label('date'),render:d=>d.document_date?.slice(0,10)},{key:'warehouse',label:label('warehouse'),render:d=>d.warehouse?.name},{key:'status',label:label('status'),render:d=>label(d.status)}]}/><Pages response={q.data} page={page} onChange={setPage}/>
    </section>;
}
export function ConsumptionReport() {
    const [filters,setFilters]=useState({from:'',to:'',warehouse_id:null,item_id:null});const [applied,setApplied]=useState({});const [page,setPage]=useState(1);
    const q=useQuery({queryKey:['consumption-report',applied,page],queryFn:()=>api.consumptionReport({...applied,page})});
    return <section className="page"><h1>{label('report')}</h1><form className="form-grid" onSubmit={e=>{e.preventDefault();setApplied({...filters});setPage(1);}}>
        {['from','to'].map(k=><Field key={k} label={label(k)}><input className="input" type="date" value={filters[k]} onChange={e=>setFilters({...filters,[k]:e.target.value})}/></Field>)}
        <Field label={label('warehouse')}><WarehousePicker value={filters.warehouse_id} onChange={v=>setFilters({...filters,warehouse_id:v})}/></Field>
        <DimensionFields value={filters} onChange={setFilters}/><Field label={label('item')}><SearchItem value={filters.item_id} onChange={v=>setFilters({...filters,item_id:v})}/></Field><button className="btn">{label('filter')}</button>
    </form>{q.error&&<p role="alert">{q.error.message}</p>}<DocumentLinesTable readOnly lines={rows(q.data)} columns={[{key:'item',label:label('item'),render:r=>r.name},...['issued_quantity','returned_quantity','net_quantity','issued_cost','returned_cost','net_cost'].map(k=>({key:k,label:label(k),render:r=>r[k]}))]}/><Pages response={q.data} page={page} onChange={setPage}/></section>;
}
