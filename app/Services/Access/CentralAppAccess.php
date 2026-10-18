<?php
namespace App\Services\Access;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

/** Request-local introspection: neither subscription nor integration credentials grant a human access. */
class CentralAppAccess
{
    public function decision(int $userId,int $organizationId,string $appKey): array
    {
        $identity=['user_id'=>$userId,'organization_id'=>$organizationId,'app_key'=>$appKey];
        if ($userId<1 || $organizationId<1) return ['allowed'=>false,'reason'=>'membership_inactive'];
        $key='central.app-access.'.hash('sha256',json_encode($identity));
        if (request()->attributes->has($key)) return request()->attributes->get($key);
        $denial=['allowed'=>false,'reason'=>'temporarily_unavailable'];
        $secret=(string) config('sso.shared_secret');
        $base=rtrim((string)config('sso.central_app_url'),'/');
        if (strlen($secret)<32 || $base==='') return $denial;
        try {
            $response=Http::timeout(5)->acceptJson()->withHeaders(['X-SSO-SECRET'=>$secret])
                ->post($base.'/api/sso/session-access',$identity);
            $data=$response->json();
            if (is_array($data) && (int)($data['user_id']??0)===$userId && (int)($data['organization_id']??0)===$organizationId && ($data['app_key']??null)===$appKey) {
                if ($response->successful() && ($data['allowed']??null)===true) $denial=$data;
                elseif ($response->status()===403) $denial=['allowed'=>false,'reason'=>(string)($data['reason']??'access_required')];
            }
        } catch (\Throwable $e) { /* Indeterminate authorization never permits a protected operation. */ }
        request()->attributes->set($key,$denial);
        return $denial;
    }

    public function deny(Request $request,array $decision,string $appKey)
    {
        $reason=(string)($decision['reason']??'access_required');
        $status=$reason==='temporarily_unavailable'?503:403;
        $appName=['finance'=>'SolaCount','inventory'=>'SolaStock','projects'=>'SolaProjects','hr'=>'SolaHR'][$appKey]??'Solavel';
        $ar=app()->getLocale()==='ar';
        $message=match($reason){
            'temporarily_unavailable'=>(trans('access')['Application access could not be checked. Please try again shortly.']),
            'email_verification_required'=>(trans('access')['Verify your email before opening applications.']),
            'setup_incomplete'=>(trans('access')['Application setup is incomplete. Contact your organization owner.']),
            'membership_inactive'=>(trans('access')['Your organization membership is inactive. Contact your organization owner.']),
            'membership_or_setup_unavailable'=>(trans('access')['Your organization membership is inactive or application setup is incomplete. Contact your organization owner.']),
            'no_subscription','app_not_purchased','subscription_missing_project','expired_entitlement'=>(trans('access')['Your organization does not have a current plan for this application. Contact your organization owner.']),
            'action_forbidden'=>(trans('access')['Your current role does not permit this action.']),
            default=>(trans('access')['Your account has not been granted access to this application. Contact your organization owner or administrator.']),
        };
        if ($request->expectsJson() || $request->is('api/*')) return response()->json(['message'=>$message,'code'=>$reason,'app'=>$appKey],$status);
        // Branded error page (errors/_branded-403 maps the reason code to its denial variant).
        return response()->view($status===503?'errors.503':'errors.403',['appName'=>$appName,'appKey'=>$appKey,'reason'=>$reason,'variant'=>$status===503?'access_check':null,'permission'=>$decision['permission']??null,'permission_label'=>$decision['permission_label']??null,'feature'=>$decision['feature']??null],$status)->header('Cache-Control','private, no-store');
    }
}
