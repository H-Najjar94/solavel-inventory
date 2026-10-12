<?php
// DB-free regression for browser error presentation and exact machine/customer data.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../app/Support/PublicUiErrors.php';
$browser=true;$messages=[];
function request(){return new class { public function is($pattern){return $GLOBALS['browser'];}};}
function __($key){return $GLOBALS['messages'];}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$english=require __DIR__.'/../lang/en/public_errors.php';
$locales=['en','ar','fr','ru','ur','de','es','zh','pt','tr','it'];$count=0;
foreach($locales as $locale){
 $messages=require __DIR__."/../lang/{$locale}/public_errors.php";
 check(array_keys($messages)===array_keys($english),"Incomplete {$locale} catalog");
 foreach($english as $source){
  $target=$messages[$source];check(is_string($target)&&trim($target)!=='',"Empty {$locale} target");
  if($locale!=='en')check($target!==$source,"English fallback {$locale}: {$source}");
  preg_match_all('/(?<![A-Za-z])Sola[A-Za-z]+(?![A-Za-z])/u',$source,$a);preg_match_all('/(?<![A-Za-z])Sola[A-Za-z]+(?![A-Za-z])/u',$target,$b);sort($a[0]);sort($b[0]);check($a[0]===$b[0],"Changed brand {$locale}");
  $response=new Illuminate\Http\JsonResponse(['message'=>$source,'errors'=>['quantity'=>[$source]],'error'=>['message'=>$source],'code'=>$source,'data'=>['name'=>$source]],409);
  App\Support\PublicUiErrors::localize($response);$d=$response->getData(true);
  check($d['message']===$target&&$d['errors']['quantity'][0]===$target&&$d['error']['message']===$target,"Untranslated browser error {$locale}");
  check($d['code']===$source&&$d['data']['name']===$source&&$response->getStatusCode()===409,"Changed contract/customer data {$locale}");
  $browser=false;$response=new Illuminate\Http\JsonResponse(['message'=>$source],409);App\Support\PublicUiErrors::localize($response);check($response->getData(true)['message']===$source,"Changed integration error {$locale}");$browser=true;
  $response=new Illuminate\Http\JsonResponse(['message'=>$source],200);App\Support\PublicUiErrors::localize($response);check($response->getData(true)['message']===$source,"Changed successful payload {$locale}");
  $count++;
 }
}
$messages=require __DIR__.'/../lang/ur/public_errors.php';
foreach($messages as $target)check(!preg_match('/[\p{Cyrillic}\p{Hiragana}\p{Katakana}]|\bmall\b|unbilled-return cost/u',$target),'Foreign residue in reviewed Urdu');
echo json_encode(['locales'=>count($locales),'messagesPerLocale'=>count($english),'browser409Cases'=>$count,'preservesMachineCustomerAndIntegrationData'=>true],JSON_PRETTY_PRINT)."\n";
