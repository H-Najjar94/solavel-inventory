<?php
namespace App\Http\Controllers\Api\V1;

use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** Authenticated server proxy: identity/org/app never come from browser input. */
final class PurchasingNotificationController
{
    public function index(Request $request,OrganizationContext $context){$data=$this->call($request,$context,'GET','/api/app-notifications');return response()->json(['success'=>true,'data'=>['items'=>$data['data']??[],'unread'=>(int)($data['meta']['unread_count']??$data['meta']['unread']??0)]])->header('Cache-Control','no-store');}
    public function read(Request $request,OrganizationContext $context,string $notification){abort_unless(preg_match('/^[a-f0-9-]{36}$/Di',$notification)===1,404);$this->call($request,$context,'POST','/api/app-notifications/'.$notification.'/read');return response()->json(['success'=>true])->header('Cache-Control','no-store');}
    private function call(Request $request,OrganizationContext $context,string $method,string $path):array{
        $user=(int)($request->user()?->id??0);$org=(int)$context->idOrFail();abort_unless($user>0,403);
        $params=['central_user_id'=>$user,'central_organization_id'=>$org,'target_app'=>'inventory'];if($method==='GET')$params['per_page']=10;
        $body=$method==='GET'?'':json_encode($params,JSON_THROW_ON_ERROR);if($method==='GET')$path.='?'.http_build_query($params);
        $secret=(string)config('solavel_sync.secret');$base=rtrim((string)config('sso.central_app_url'),'/');abort_unless($secret!==''&&$base!=='',503);$ts=time();$sig=hash_hmac('sha256',implode("\n",[$method,$path,(string)$ts,hash('sha256',$body)]),$secret);
        $pending=Http::timeout(8)->connectTimeout(3)->withoutRedirecting()->acceptJson()->withHeaders(['X-Solavel-App'=>'inventory','X-Solavel-Timestamp'=>(string)$ts,'X-Solavel-Signature'=>$sig]);
        $response=$method==='GET'?$pending->get($base.$path):$pending->withBody($body,'application/json')->post($base.$path);
        abort_unless($response->successful(),$response->status()===404?404:503);$data=$response->json();abort_unless(is_array($data),503);return$data;
    }
}
