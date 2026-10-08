<?php
namespace App\Services\Integration;

use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\{DB,Http,Schema};
use Illuminate\Support\Str;

/** Separate durable notification intent. Never changes document delivery, journals or stock. */
final class DocumentIncidentNotificationPublisher
{
 public function changed(string $kind,int $id,int $org):void {
  DB::connection('tenant')->afterCommit(function()use($kind,$id,$org){try{$this->queue($kind,$id,$org);}catch(\Throwable $e){report($e);}});
 }
 private function queue(string $kind,int $id,int $org):void {
  $db=DB::connection('tenant');if(!Schema::connection('tenant')->hasTable('integration_document_notification_outbox'))return;
  abort_unless(in_array($kind,['receipt','shipment'],true),422);
  $table=$kind==='receipt'?'purchasing_document_outbox':'sales_document_outbox';
  if(!Schema::connection('tenant')->hasTable($table))return;
  $row=$db->table($table)->where('organization_id',$org)->where('id',$id)->first();if(!$row)return;
  if((int)$row->attempts<1 || ($row->status==='sent' && (int)$row->attempts===1))return;
  $payload=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);
  if(($payload['event_type']??null)!==($kind==='receipt'?'purchasing.receipt.confirmed':'sales.shipment.confirmed'))return;
  $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',data_get($payload,'identity.organization_mapping_uuid'))
   ->where('solastock_organization_id',$org)->where('tenant_database_identity',$db->getDatabaseName())
   ->where('status','verified')->where('activation_state','active')->first();if(!$mapping)return;
  if(!hash_equals($row->payload_hash,hash('sha256',SolaStockJournalContract::canonicalJson($payload))))return;
  $native=$db->table($kind==='receipt'?'goods_receipts':'shipments')->where('organization_id',$org)
   ->where('id',data_get($payload,$kind==='receipt'?'receipt.id':'shipment.id'))->first();if(!$native)return;
  $facts=DocumentIncidentFacts::fromRows($kind,$row,$native,$mapping->mapping_uuid);
  $db->table('integration_document_notification_outbox')->insertOrIgnore(['organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid,
   'document_kind'=>$kind,'document_outbox_id'=>$id,'transition_fingerprint'=>DocumentIncidentFacts::fingerprint($facts),
   'facts'=>json_encode($facts,JSON_THROW_ON_ERROR),'state'=>'pending','attempts'=>0,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function process(int $limit=1):int {
  $org=(int)app(OrganizationContext::class)->idOrFail();$db=DB::connection('tenant');
  if(!Schema::connection('tenant')->hasTable('integration_document_notification_outbox'))return 0;
  $mapping=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('central_organization_id',$org)
   ->where('tenant_database_identity',$db->getDatabaseName())->where('integration','solabooks')->where('status','verified')->where('activation_state','active')->first();if(!$mapping)return 0;
  $secret=(string)config('solavel_sync.secret');$base=rtrim((string)config('sso.central_app_url'),'/');if($secret===''||$base==='')return 0;
  // Rotating bounded reconciliation recovers failed after-commit inserts; no source state is changed.
  foreach(['receipt'=>'purchasing_document_outbox','shipment'=>'sales_document_outbox'] as $kind=>$table){
   if(!Schema::connection('tenant')->hasTable($table))continue;
   $key='document-incident-recovery:'.$db->getDatabaseName().':'.$org.':'.$kind;$cache=\Illuminate\Support\Facades\Cache::store('file');$cursor=(int)$cache->get($key,0);
   $rows=$db->table($table)->where('organization_id',$org)->where('id','>',$cursor)->where('attempts','>',0)->orderBy('id')->limit(10)->get();
   foreach($rows as $row)$this->queue($kind,(int)$row->id,$org);
   $cache->put($key,$rows->count()<10?0:(int)$rows->last()->id,86400);
  }
  $count=0;
  for($i=0;$i<min(2,max(1,$limit));$i++){
   $token=(string)Str::uuid();$row=$db->transaction(function()use($db,$org,$token){$q=$db->table('integration_document_notification_outbox');$r=$q->where('organization_id',$org)->where(fn($q)=>$q->where('state','pending')->orWhere(fn($q)=>$q->where('state','processing')->where('lease_until','<',now())))->where(fn($q)=>$q->whereNull('retry_at')->orWhere('retry_at','<=',now()))->orderBy('id')->lockForUpdate()->first();if(!$r)return null;$q->where('id',$r->id)->update(['state'=>'processing','lease_token'=>$token,'lease_until'=>now()->addSeconds(90),'attempts'=>$r->attempts+1,'updated_at'=>now()]);return$r;});if(!$row)break;
   $body=json_encode(['client_id'=>(int)$mapping->central_client_id,'organization_id'=>$org,'document_kind'=>$row->document_kind,'outbox_id'=>(int)$row->document_outbox_id,'transition_fingerprint'=>$row->transition_fingerprint],JSON_THROW_ON_ERROR);$ts=time();$path='/api/document-incident-notifications';$sig=hash_hmac('sha256',implode("\n",['POST',$path,(string)$ts,hash('sha256',$body)]),$secret);
   try{$response=Http::timeout(12)->connectTimeout(2)->withoutRedirecting()->acceptJson()->withHeaders(['X-Solavel-App'=>'inventory','X-Solavel-Timestamp'=>(string)$ts,'X-Solavel-Signature'=>$sig])->withBody($body,'application/json')->post($base.$path);$answer=$response->json();$ok=$response->successful()&&is_array($answer)&&(int)($answer['organization_id']??0)===$org&&($answer['document_kind']??null)===$row->document_kind&&(int)($answer['outbox_id']??0)===(int)$row->document_outbox_id&&($answer['fingerprint']??null)===$row->transition_fingerprint&&in_array($answer['state']??null,['published','superseded'],true);$error=$ok?null:(($answer['state']??null)==='no_recipients'?'no_eligible_recipients':'central_publish_http_'.$response->status());}catch(\Throwable){$ok=false;$answer=[];$error='central_publish_unavailable';}
   $values=['state'=>$ok?($answer['state']==='superseded'?'superseded':'sent'):($error==='no_eligible_recipients'||(int)$row->attempts+1>=40?'intervention':'pending'),'lease_token'=>null,'lease_until'=>null,'last_error'=>$error,'retry_at'=>$ok?null:now()->addSeconds(min(1800,15*2**min(7,(int)$row->attempts))),'updated_at'=>now()];
   if($ok){$values['delivered_at']=now();$values['notification_thread_id']=$answer['thread_ids'][0]??null;}
   $db->table('integration_document_notification_outbox')->where('organization_id',$org)->where('id',$row->id)->where('lease_token',$token)->update($values);
   if($values['state']==='intervention')\Illuminate\Support\Facades\Log::warning('integration.document_notification.intervention',['organization_id'=>$org,'organization_mapping_uuid'=>$mapping->mapping_uuid,'document_kind'=>$row->document_kind,'document_outbox_id'=>(int)$row->document_outbox_id,'notification_outbox_id'=>(int)$row->id,'attempt_count'=>(int)$row->attempts+1,'reason'=>$error]);
   $count++;
  }
  return$count;
 }
}
