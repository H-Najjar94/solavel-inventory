<?php
// No application bootstrap or DB: stale sessions must not override the picker cookie.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../app/Http/Middleware/SetInventoryLocale.php';
$activeLocale = null; $accountNext = null; $writes = 0;
function app($abstract = null) {
    return new class {
        public function setLocale($value) { $GLOBALS['activeLocale'] = $value; }
        public function sync($request) { if ($GLOBALS['accountNext']) $request->session()->put('solastock_locale', $GLOBALS['accountNext']); return ['changed'=>false]; }
        public function save($id, $preferences) { $GLOBALS['writes']++; return true; }
    };
}
function redirect($url) { return new Illuminate\Http\RedirectResponse($url); }
function cookie($name, $value, $minutes, $path, $domain, $secure, $httpOnly, $raw, $sameSite) { return new Symfony\Component\HttpFoundation\Cookie($name,$value,time()+$minutes*60,$path,$domain,$secure,$httpOnly,$raw,$sameSite); }
function runCase($url, $cookie, $sessionLocale, $header = null) {
    $request = Illuminate\Http\Request::create($url,'GET',[],['solavel_locale'=>$cookie],[], $header ? ['HTTP_X_LOCALE'=>$header] : []);
    $session = new Illuminate\Session\Store('test',new Illuminate\Session\ArraySessionHandler(120));
    $request->setUserResolver(fn()=>(object)['central_user_id'=>42]);
    $session->start(); $session->put('solastock_locale',$sessionLocale); $request->setLaravelSession($session);
    $response = (new App\Http\Middleware\SetInventoryLocale)->handle($request,fn()=>new Illuminate\Http\Response('ok'));
    return [$response,$session];
}
foreach (['ar','fr','ru','ur','de','es','zh','pt','tr','it'] as $locale) {
    [$response,$session]=runCase('https://solavel.com/dashboard',$locale,'en');
    assert($GLOBALS['activeLocale']===$locale && $session->get('solastock_locale')===$locale);
    assert(count($response->headers->getCookies())===0);
    [$response,$session]=runCase('https://solavel.com/dashboard?locale='.$locale,'en','en');
    assert($response->getStatusCode()===302 && $response->headers->getCookies()[0]->getValue()===$locale);
}
[$response,$session]=runCase('https://solavel.com/api/v1/items','fr','en','en');
assert($GLOBALS['activeLocale']==='en' && $session->get('solastock_locale')==='fr');
assert(count($response->headers->getCookies())===0);
$accountNext='de'; [$response,$session]=runCase('https://solavel.com/dashboard','fr','en');
assert($GLOBALS['activeLocale']==='de' && $session->get('solastock_locale')==='de');
assert($response->headers->getCookies()[0]->getValue()==='de');
assert($writes===0);
$accountNext=null; runCase('https://solavel.com/dashboard?locale=fr&remember=1','en','en');
assert($writes===1);
echo "Ten picker languages survive stale sessions; old API headers preserve the browser choice; genuine account changes still win without account writes.\n";
