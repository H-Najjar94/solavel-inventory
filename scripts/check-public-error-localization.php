<?php
// Exercise public presentation without booting Laravel or selecting a tenant DB.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../app/Support/PublicUiErrors.php';
$routeIsBrowser = true;
function request() { return new class { public function is($pattern) { return $GLOBALS['routeIsBrowser']; } }; }
function __($key) { return ['Entered quantity must be a positive decimal.' => 'La quantité saisie doit être un nombre décimal positif.']; }
$english = 'Entered quantity must be a positive decimal.';
$response = new Illuminate\Http\JsonResponse(['message'=>$english,'errors'=>['quantity'=>[$english]],'code'=>$english,'data'=>['name'=>$english]],422);
App\Support\PublicUiErrors::localize($response);
$data = $response->getData(true);
assert($data['message'] !== $english);
assert($data['errors']['quantity'][0] === $data['message']);
assert($data['code'] === $english && $data['data']['name'] === $english);
$routeIsBrowser = false;
$response = new Illuminate\Http\JsonResponse(['message'=>$english],422);
App\Support\PublicUiErrors::localize($response);
assert($response->getData(true)['message'] === $english);
$routeIsBrowser = true;
$response = new Illuminate\Http\JsonResponse(['message'=>$english],200);
App\Support\PublicUiErrors::localize($response);
assert($response->getData(true)['message'] === $english);
echo "Public errors translate validation only; codes, customer data, successful responses and integration routes remain exact.\n";
