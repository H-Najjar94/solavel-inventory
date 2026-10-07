<?php
namespace App\Http\Controllers\Api\V1;

use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Authenticated server proxy: identity/org/app never come from browser input. */
final class PurchasingNotificationController
{
    public function index(Request $request,OrganizationContext $context){$items=$this->visible($request,$context);return response()->json(['success'=>true,'data'=>['items'=>$items,'unread'=>count(array_filter($items,fn($item)=>empty($item['read_at']))) ]])->header('Cache-Control','no-store');}
    public function read(Request $request,OrganizationContext $context,string $notification){abort_unless(preg_match('/^[a-f0-9-]{36}$/Di',$notification)===1,404);abort_unless(collect($this->visible($request,$context))->contains(fn($item)=>(string)$item['id']===$notification),404);$this->call($request,$context,'POST','/api/app-notifications/'.$notification.'/read');return response()->json(['success'=>true])->header('Cache-Control','no-store');}
    private function visible(Request $request,OrganizationContext $context):array {
        $permissions=app(\App\Services\Access\InventoryPermissionService::class);$user=$request->user();abort_unless($user&&$permissions->can($user,'inventory.receive_goods'),403);
        $approver=$permissions->can($user,'inventory.approve_purchase_orders')||$permissions->can($user,'inventory.manage_adjustments');
        $warehouses=app(\App\Services\Access\WarehouseAccessService::class)->allowedIds((int)$user->id);
        $data=$this->call($request,$context,'GET','/api/app-notifications');$items=(array)($data['data']??[]);$ids=[];
        foreach($items as$item){$url=parse_url((string)($item['action_url']??''));$id=0;if(str_ends_with((string)($url['path']??''),'/receiving-requests')){parse_str($url['query']??'',$query);$id=ctype_digit((string)($query['request']??''))?(int)$query['request']:0;}$ids[(string)($item['id']??'')]=$id;}
        $requests=\App\Models\Tenant\ReceivingRequest::query()->where('organization_id',$context->idOrFail())->whereIn('id',array_values($ids))->get()->keyBy('id');
        $active=\Illuminate\Support\Facades\DB::connection('tenant')->table('warehouses')->where('organization_id',$context->idOrFail())->where('is_active',true)->pluck('id')->map(fn($id)=>(int)$id)->all();
        return array_values(array_filter($items,function($item)use($ids,$requests,$approver,$warehouses,$active){$rr=$requests->get($ids[(string)($item['id']??'')]??0);if(!$rr)return false;$warehouse=(int)$rr->warehouse_id;$approved=(bool)$rr->approved_at&&hash_equals((string)$rr->source_revision,(string)$rr->approved_revision);if(($warehouse===0||!$approved)&&!$approver)return false;if($warehouse>0&&(!in_array($warehouse,$active,true)||($warehouses!==null&&!in_array($warehouse,$warehouses,true))))return false;return true;}));
    }
    private function call(Request $request,OrganizationContext $context,string $method,string $path):array{
        $user=(int)($request->user()?->id??0);$org=(int)$context->idOrFail();abort_unless($user>0,403);
        $params=['central_user_id'=>$user,'central_organization_id'=>$org,'target_app'=>'inventory'];if($method==='GET')$params['per_page']=50;
        $body=$method==='GET'?'':json_encode($params,JSON_THROW_ON_ERROR);if($method==='GET')$path.='?'.http_build_query($params);
        $secret=(string)config('solavel_sync.secret');$base=rtrim((string)config('sso.central_app_url'),'/');abort_unless($secret!==''&&$base!=='',503);$ts=time();$sig=hash_hmac('sha256',implode("\n",[$method,$path,(string)$ts,hash('sha256',$body)]),$secret);
        $pending=Http::timeout(8)->connectTimeout(3)->withoutRedirecting()->acceptJson()->withHeaders(['X-Solavel-App'=>'inventory','X-Solavel-Timestamp'=>(string)$ts,'X-Solavel-Signature'=>$sig]);
        $response=$method==='GET'?$pending->get($base.$path):$pending->withBody($body,'application/json')->post($base.$path);
        abort_unless($response->successful(),$response->status()===404?404:503);$data=$response->json();abort_unless(is_array($data),503);return$data;
    }
}
