<?php
namespace App\Services\Integration;
use App\Models\Tenant\{Supplier,Customer,IntegrationMasterDataMapping};
use App\Services\Purchasing\ReceivingRequestService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
/** Closed signed party dependency; no interactive Stock assignment is implied. */
final class ContinuousPartySync {
 /** Closed metadata dependency for a real saved SR/Expense, never a physical operation. */
 public function dispatchFinancialOrigin(array $input,object $organization):array {
  abort_unless(($input['authority_kind']??null)==='continuous_party_sync' && (int)$input['actor_id']===0 && $input['action']==='financial-origin.party.ensure',403);
  $central=DB::connection((string)config('tenancy.central_connection','mysql'));
  foreach([['finance','accounting','construction'],['inventory']] as $slugs)
   abort_unless($central->table('organization_projects as a')->join('projects','projects.id','=','a.project_id')->where('a.organization_id',$organization->id)->where('a.is_active',true)->where('projects.is_active',true)->whereIn('projects.slug',$slugs)->exists(),403);
  $tenants=app(TenantManager::class);$tenants->useTenant((int)$organization->id,$tenants->resolveDatabaseName((int)$organization->client_id));
  $mapping=app(ReceivingRequestService::class)->mapping();
  abort_unless((int)$mapping->finance_organization_id===(int)$input['finance_organization_id'] && (int)$mapping->central_client_id===(int)$organization->client_id && (int)$mapping->central_organization_id===(int)$organization->id,403);
  app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
  $facts=validator((array)($input['data']??[]),[
   'entity_type'=>'required|in:customer,supplier','source_app'=>'required|in:finance','source_id'=>'required|integer|min:1',
   'source_document_type'=>'required|in:sales_receipt,expense','source_document_id'=>'required|integer|min:1','source_journal_id'=>'required|integer|min:1',
   'request_uuid'=>'required|uuid','document_revision'=>'required|string|size:64','source_revision'=>'required|string|size:64','native_actor_id'=>'required|integer|min:1',
  ])->validate();
  $origin=\App\Services\FinancialOrigins\FinancialOrigin::fromPayload($facts);
  $type=$origin->type==='expense'?'supplier':'customer';abort_unless($type===$facts['entity_type'],403);
  $authority=app(SolaBooksOutboxDeliveryService::class)->authorizeOrigin((int)$facts['native_actor_id'],$origin,'post',[
   'request_uuid'=>$facts['request_uuid'],'source_revision'=>$facts['document_revision'],
  ]);
  $payload=(array)($authority['canonical_payload']??[]);
  abort_unless(($authority['request_uuid']??null)===$facts['request_uuid'] && ($authority['request_revision']??null)===$facts['document_revision']
   && ($payload['source_document_type']??null)===$origin->type && (int)($payload['source_document_id']??0)===$origin->documentId
   && (int)($payload['source_journal_id']??0)===$origin->journalId
   && (int)($payload[$type==='supplier'?'supplier_external_id':'customer_external_id']??0)===(int)$facts['source_id'],403);
  $ledger=app(PartySyncLedger::class);$fields=$ledger->fields($mapping,'finance',$type,(int)$facts['source_id']);
  abort_unless($fields && hash_equals($ledger->revision($fields),$facts['source_revision']),409);
  return $this->materialize($mapping,'finance',$type,(int)$facts['source_id'],$facts['source_revision']);
 }
 public function dispatch(array $input,object $organization):array {
  abort_unless(($input['authority_kind']??null)==='continuous_party_sync' && (int)$input['actor_id']===0
   && in_array($input['action'],['purchasing.party.ensure','purchasing.party.status','purchasing.party.choices','purchasing.party.resolve','sales.party.ensure','sales.party.status','sales.party.choices','sales.party.resolve'],true),403);
  $central=DB::connection((string)config('tenancy.central_connection','mysql'));
  foreach([['finance','accounting','construction'],['inventory']]as$slugs)
   abort_unless($central->table('organization_projects as a')->join('projects','projects.id','=','a.project_id')->where('a.organization_id',$organization->id)->where('a.is_active',true)->where('projects.is_active',true)->whereIn('projects.slug',$slugs)->exists(),403);
  $tenants=app(TenantManager::class);$tenants->useTenant((int)$organization->id,$tenants->resolveDatabaseName((int)$organization->client_id));
  $mapping=app(ReceivingRequestService::class)->mapping();
  abort_unless((int)$mapping->finance_organization_id===(int)$input['finance_organization_id'] && (int)$mapping->central_client_id===(int)$organization->client_id && (int)$mapping->central_organization_id===(int)$organization->id,403);
  app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($mapping);
  $sales=str_starts_with($input['action'],'sales.party.');
  $type=$sales?'customer':'supplier';
  $sourceKey=$sales?'source_invoice_id':'source_bill_id';
  $facts=validator((array)($input['data']??[]),['entity_type'=>'required|in:'.$type,'source_app'=>'required|in:finance','source_id'=>'required|integer|min:1',$sourceKey=>'required|integer|min:1','native_actor_id'=>'required|integer|min:1','source_revision'=>'required|string|size:64'])->validate();
  $review=in_array($input['action'],['purchasing.party.choices','purchasing.party.resolve','sales.party.choices','sales.party.resolve'],true);
  $reviewFacts=$review?['party_command'=>$input['action'],$sales?'party_customer_id':'party_supplier_id'=>(int)$facts['source_id']]:[];
  $authority=$sales?$this->authorizeInvoiceDependency($mapping,$facts,$reviewFacts):$this->authorizeBillDependency($mapping,$facts,$reviewFacts);
  if($review){
   $owner=$central->table('user_organizations')->where('organization_id',$organization->id)->where('user_id',$facts['native_actor_id'])->whereIn('role',['owner','client_owner'])->where(fn($q)=>$q->whereNull('status')->orWhere('status','active'))->exists();
   abort_unless($owner || ($authority['party_link_allowed']??false)===true,403);
  }
  $ledger=app(PartySyncLedger::class);$fields=$ledger->fields($mapping,'finance',$type,(int)$facts['source_id']);
  abort_unless($fields && hash_equals($ledger->revision($fields),$facts['source_revision']),409);
  if(in_array($input['action'],['purchasing.party.choices','sales.party.choices'],true))return app(PartyIdentityReview::class)->choices($mapping,$facts);
  if(in_array($input['action'],['purchasing.party.resolve','sales.party.resolve'],true)){
   $choice=validator((array)$input['data'],['target_id'=>'required|integer|min:1','selection_fingerprint'=>'required|string|size:64'])->validate();
   return app(PartyIdentityReview::class)->resolve($mapping,$facts,(int)$choice['target_id'],$choice['selection_fingerprint']);
  }
  if(in_array($input['action'],['purchasing.party.status','sales.party.status'],true)){
   $row=DB::connection('tenant')->table('integration_party_sync_states')->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('entity_type',$type)->where('source_app','finance')->where('source_id',$facts['source_id'])->first();
   return app(PartySyncMaterializer::class)->result($row?->status??'pending',(int)$facts['source_id'],$row?->target_id?(int)$row->target_id:null,$row?->mapping_uuid,$row?->last_error??($row?null:'party_connection_pending'));
  }
  return $this->materialize($mapping,'finance',$type,(int)$facts['source_id'],$facts['source_revision']);
 }
 public function authorizeInvoiceDependency(object $mapping,array $facts,array $review=[]):array {
  $invoice=DB::connection('tenant')->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$facts['source_invoice_id'])->first();
  abort_unless($invoice && (int)$invoice->customer_id===(int)$facts['source_id'],403);
  // Finance invoices identify their journal by native source identity, not a journal_entry_id column.
  $posted=DB::connection('tenant')->table('journal_entries')->where('organization_id',$mapping->finance_organization_id)
   ->where('source','AR')->where('source_type','App\\Models\\Invoice')->where('source_id',$invoice->id)
   ->where('status','posted')->whereNotNull('posted_at')->whereNull('voided_at')->whereNull('deleted_at')->exists();
  abort_unless($posted ? $invoice->status!=='draft' : $invoice->status==='draft',409);
  return app(SolaBooksOutboxDeliveryService::class)->authorizeSales((int)$facts['native_actor_id'],(int)$invoice->id,$posted?'post':'edit_draft',$review);
 }
 public function authorizeBillDependency(object $mapping,array $facts,array $review=[]):array {
  $bill=DB::connection('tenant')->table('bills')->where('organization_id',$mapping->finance_organization_id)->where('id',$facts['source_bill_id'])->first();
  abort_unless($bill && (int)$bill->supplier_id===(int)$facts['source_id'],403);
  // Paid/unpaid/partial are native posted bill states. The independently signed
  // Finance authority proves its active JE and permission; status text is not proof.
  return app(SolaBooksOutboxDeliveryService::class)->authorizePurchasing((int)$facts['native_actor_id'],(int)$bill->id,(int)($bill->journal_entry_id??0)>0?'post':'edit_draft',$review);
 }
 public function process(object $mapping,int $limit=2):int {
  if(!\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('integration_party_sync_states'))return 0;
  $ledger=app(PartySyncLedger::class);$ledger->reconcile($mapping);$count=0;
  $rows=DB::connection('tenant')->table('integration_party_sync_states')->where('organization_mapping_uuid',$mapping->mapping_uuid)->whereIn('status',['pending','held'])->where('attempts','<',40)->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now()))->orderBy('id')->limit(max(1,min(5,$limit)))->get();
  foreach($rows as$row){
   try{
    if($row->source_app==='finance')$result=$this->materialize($mapping,'finance',$row->entity_type,(int)$row->source_id,$row->source_revision);
    else $result=app(SolaBooksOutboxDeliveryService::class)->sendPartyChange($mapping,$row);
    if(in_array($result['status']??null,['synced','held','pending','intervention'],true))
     DB::connection('tenant')->table('integration_party_sync_states')->where('id',$row->id)->where('source_revision',$row->source_revision)->where('state_version',$row->state_version)->where('attempts',$row->attempts)->update(['status'=>$row->attempts+1>=40 && in_array($result['status'],['held','pending'],true)?'intervention':$result['status'],'last_error'=>$row->attempts+1>=40 && in_array($result['status'],['held','pending'],true)?'party_retry_exhausted':($result['reason']??null),'attempts'=>$row->attempts+1,'next_attempt_at'=>in_array($result['status'],['held','pending'],true)?now()->addMinute():null,'updated_at'=>now()]);
    app(SyncIncidentNotificationPublisher::class)->changed('party',(int)$row->id,(string)$mapping->mapping_uuid);
   }catch(\Throwable $e){
    DB::connection('tenant')->table('integration_party_sync_states')->where('id',$row->id)->where('source_revision',$row->source_revision)->where('state_version',$row->state_version)->where('attempts',$row->attempts)->update(['status'=>$row->attempts+1>=40?'intervention':'pending','attempts'=>$row->attempts+1,'last_error'=>$row->attempts+1>=40?'party_retry_exhausted':'party_connection_pending','next_attempt_at'=>now()->addSeconds(min(3600,30*(2**min(7,$row->attempts)))),'updated_at'=>now()]);
    app(SyncIncidentNotificationPublisher::class)->changed('party',(int)$row->id,(string)$mapping->mapping_uuid);
    report($e);
   }
   $count++;
  }
  return $count;
 }
 public function materialize(object $mapping,string $sourceApp,string $type,int $sourceId,?string $revision=null):array {
  abort_unless($sourceApp==='finance' && in_array($type,['supplier','customer'],true),403);
  $model=$type==='supplier'?Supplier::class:Customer::class;
  return app(PartySyncMaterializer::class)->apply($mapping,$sourceApp,$type,$sourceId,
   fn(array $fields,int $id)=>PartySyncLedger::withoutTracking(fn()=>$model::query()->create(['organization_id'=>$mapping->solastock_organization_id,'code'=>($type==='supplier'?'FIN-S-':'FIN-C-').$id,'name'=>$fields['name'],'contact'=>$fields['contact'],'is_active'=>true])->id),
   fn(int $id,array $fields)=>PartySyncLedger::withoutTracking(fn()=>$model::query()->where('organization_id',$mapping->solastock_organization_id)->findOrFail($id)->update(['name'=>$fields['name'],'contact'=>$fields['contact']])),
   fn(array $fields)=>IntegrationMasterDataMapping::query()->create($fields),$revision);
 }
}
