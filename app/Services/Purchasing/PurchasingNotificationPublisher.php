<?php
namespace App\Services\Purchasing;

use App\Models\Tenant\{IntegrationOrganizationMapping, ReceivingRequest};
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;

/** Warehouse notifications have their own durable queue, never the financial outbox. */
final class PurchasingNotificationPublisher
{
    public function changed(ReceivingRequest $request): void
    {
        $org=(int)$request->organization_id;$id=(int)$request->id;
        DB::connection('tenant')->afterCommit(function()use($org,$id){try{$this->queue($org,$id);}catch(\Throwable){\Illuminate\Support\Facades\Log::warning('purchasing.notification.enqueue_failed',['organization_id'=>$org,'request_id'=>$id]);}});
    }

    private function queue(int $org,int $id): void {

            if (!Schema::connection('tenant')->hasTable('purchasing_notification_outbox')) return;
            $rr=ReceivingRequest::query()->where('organization_id',$org)->with('lines')->find($id);if(!$rr)return;
            $lines=$rr->lines->map(fn($line)=>[(int)$line->id,(string)$line->requested_qty,(string)$line->received_qty])->sortBy(0)->values()->all();
            $fingerprint=hash('sha256',json_encode([$rr->request_uuid,$rr->source_revision,$rr->status,$rr->warehouse_id,((bool)$rr->approved_at && hash_equals((string)$rr->source_revision,(string)$rr->approved_revision)),$lines],JSON_THROW_ON_ERROR));
            DB::connection('tenant')->table('purchasing_notification_outbox')->insertOrIgnore(['organization_id'=>$org,'request_id'=>$id,'transition_fingerprint'=>$fingerprint,'state'=>'pending','attempts'=>0,'created_at'=>now(),'updated_at'=>now()]);
    }

    public function process(int $limit=20): int
    {
        $org=(int)app(OrganizationContext::class)->idOrFail();
        if (!Schema::connection('tenant')->hasTable('purchasing_notification_outbox')) return 0;
        $mapping=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$org)->where('central_organization_id',$org)->where('tenant_database_identity',DB::connection('tenant')->getDatabaseName())->where('integration','solabooks')->where('status','verified')->where('activation_state','active')->first();if(!$mapping)return 0;
        $secret=(string)config('solavel_sync.secret');$base=rtrim((string)config('sso.central_app_url'),'/');if($secret===''||$base==='')return 0;
        // Bounded rotating projection recovers a committed request whose after-commit
        // queue insert failed. Persistent native requests remain the source of truth.
        $cursorKey='purchasing-notification-recovery:'.DB::connection('tenant')->getDatabaseName().':'.$org;
        $cache=\Illuminate\Support\Facades\Cache::store('file');$cursor=(int)$cache->get($cursorKey,0);
        $requests=ReceivingRequest::query()->where('organization_id',$org)->where('id','>',$cursor)->orderBy('id')->limit(20)->get();
        foreach($requests as$request)$this->queue($org,(int)$request->id);
        $cache->put($cursorKey,$requests->count()<20?0:(int)$requests->last()->id,86400);
        $count=0;$deadline=microtime(true)+15;
        for($i=0;$i<min(2,max(1,$limit));$i++) {
            if(microtime(true)>=$deadline)break;
            $token=(string)Str::uuid();
            $row=DB::connection('tenant')->transaction(function()use($org,$token){$q=DB::connection('tenant')->table('purchasing_notification_outbox');$row=$q->where('organization_id',$org)->where(fn($q)=>$q->where('state','pending')->orWhere(fn($q)=>$q->where('state','processing')->where('lease_until','<',now())))->where(fn($q)=>$q->whereNull('retry_at')->orWhere('retry_at','<=',now()))->orderBy('id')->lockForUpdate()->first();if(!$row)return null;$q->where('id',$row->id)->update(['state'=>'processing','lease_token'=>$token,'lease_until'=>now()->addSeconds(90),'attempts'=>$row->attempts+1,'updated_at'=>now()]);return$row;});
            if(!$row)break;
            $body=json_encode(['client_id'=>(int)$mapping->central_client_id,'organization_id'=>$org,'request_id'=>(int)$row->request_id],JSON_THROW_ON_ERROR);$ts=time();$path='/api/purchasing-notifications';$sig=hash_hmac('sha256',implode("\n",['POST',$path,(string)$ts,hash('sha256',$body)]),$secret);
            try{$response=Http::timeout(12)->connectTimeout(2)->withoutRedirecting()->acceptJson()->withHeaders(['X-Solavel-App'=>'inventory','X-Solavel-Timestamp'=>(string)$ts,'X-Solavel-Signature'=>$sig])->withBody($body,'application/json')->post($base.$path);$data=$response->json();$ok=$response->successful() && is_array($data) && (int)($data['organization_id']??0)===$org && (int)($data['request_id']??0)===(int)$row->request_id && isset($data['thread_id']);$error=$ok?null:'central_publish_http_'.(int)$response->status();}catch(\Throwable){$ok=false;$data=[];$error='central_publish_unavailable';}
            $retry=min(1800,15*(2**min(7,(int)$row->attempts)));$exhausted=!$ok&&((int)$row->attempts+1)>=40;if($exhausted)\Illuminate\Support\Facades\Log::warning('purchasing.notification.intervention',['organization_id'=>$org,'request_id'=>(int)$row->request_id,'reason'=>$error]);$values=['state'=>$ok?'sent':($exhausted?'intervention':'pending'),'lease_token'=>null,'lease_until'=>null,'last_error'=>$error,'retry_at'=>$ok?null:now()->addSeconds($retry),'updated_at'=>now()];if($ok){$values['delivered_at']=now();$values['notification_thread_id']=$data['thread_id'];}
            DB::connection('tenant')->table('purchasing_notification_outbox')->where('id',$row->id)->where('organization_id',$org)->where('lease_token',$token)->update($values);$count++;
        }
        return$count;
    }
}
