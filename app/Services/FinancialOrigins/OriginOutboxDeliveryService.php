<?php
namespace App\Services\FinancialOrigins;
use App\Models\Tenant\FinancialOriginOutbox;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called by the existing entitled target worker; no HTTP inside native posting or DB locks. */
final class OriginOutboxDeliveryService
{
    public function deliverDue(int $limit=1):int
    {
        if(!DB::connection('tenant')->getSchemaBuilder()->hasTable('stock_financial_origin_outbox'))return 0;
        $count=0;
        for($i=0;$i<min(2,max(0,$limit));$i++){
            $event=DB::connection('tenant')->transaction(function(){
                $e=FinancialOriginOutbox::query()->where(function($q){$q->whereIn('status',['pending','retry'])->where(fn($q)=>$q->whereNull('next_attempt_at')->orWhere('next_attempt_at','<=',now()))
                    ->orWhere(fn($q)=>$q->where('status','processing')->where('lease_expires_at','<=',now()));})->orderBy('id')->lockForUpdate()->first();
                if(!$e)return null;if($e->attempts>=40){$e->update(['status'=>'intervention','last_error'=>__('inventory.purchasing.delivery_pending'),'lease_uuid'=>null,'lease_expires_at'=>null]);return null;}
                $e->update(['status'=>'processing','lease_uuid'=>(string)Str::uuid(),'lease_expires_at'=>now()->addSeconds(60),'attempts'=>$e->attempts+1]);return $e;
            });
            if(!$event)break;
            try{$result=app(SolaBooksOutboxDeliveryService::class)->sendOriginDocument($event);}catch(\Throwable $exception){report($exception);$result=['successful'=>false,'data'=>[]];}
            DB::connection('tenant')->transaction(function()use($event,$result){
                $e=FinancialOriginOutbox::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();if($e->lease_uuid!==$event->lease_uuid)return;
                $data=(array)($result['data']??[]);$ok=($result['successful']??false) && ($data['source_document_type']??null)===$e->source_document_type
                    && (int)($data['source_document_id']??0)===(int)$e->source_document_id && (int)($data['source_journal_id']??0)===(int)$e->source_journal_id
                    && (int)($data['physical_document_id']??0)===(int)$e->physical_document_id;
                $e->update(['status'=>$ok?'sent':($e->attempts>=40?'intervention':'retry'),'response'=>$ok?$data:null,'sent_at'=>$ok?now():null,
                    'lease_uuid'=>null,'lease_expires_at'=>null,'next_attempt_at'=>$ok?null:now()->addSeconds(min(3600,30*2**min(7,$e->attempts))),
                    'last_error'=>$ok?null:__('inventory.purchasing.delivery_pending')]);
            });$count++;
        }return $count;
    }
}
