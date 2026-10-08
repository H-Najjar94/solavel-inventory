<?php
namespace Tests\Feature\Integration;

use App\Http\Controllers\Api\Tenancy\DocumentIncidentContextController;
use App\Services\Tenancy\TenantManager;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real signature and native landlord identity rejection; no mocked RPC or economic fixtures. */
final class DocumentIncidentContextAuthorizationTest extends TestCase
{
 private function invoke(Request $request):void {
  app(DocumentIncidentContextController::class)($request,app(TenantManager::class),app(OrganizationContext::class));
 }
 public function test_unsigned_context_cannot_use_testing_sync_bypass():void {
  config(['solavel_sync.secret'=>'isolated-incident-secret','solavel_sync.use_signed_sync'=>false]);
  $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
  $this->expectExceptionCode(0);
  $this->invoke(Request::create('/api/tenancy/document-incident-context','POST',[]));
 }
 public function test_correct_signature_cannot_substitute_another_client_for_the_organization():void {
  config(['solavel_sync.secret'=>'isolated-incident-secret','database.connections.incident_identity'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>''],'tenancy.central_connection'=>'incident_identity']);
  DB::connection('incident_identity')->statement('CREATE TABLE organizations (id INTEGER PRIMARY KEY,client_id INTEGER,is_active INTEGER,deleted_at TEXT)');
  DB::connection('incident_identity')->table('organizations')->insert(['id'=>930010,'client_id'=>930002,'is_active'=>1]);
  $body=json_encode(['client_id'=>930003,'organization_id'=>930010,'document_kind'=>'receipt','outbox_id'=>1,'nonce'=>(string)Str::uuid()],JSON_THROW_ON_ERROR);$ts=(string)time();
  $request=Request::create('/api/tenancy/document-incident-context','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_SOLAVEL_TIMESTAMP'=>$ts,'HTTP_X_SOLAVEL_SIGNATURE'=>'sha256='.hash_hmac('sha256',$ts.'.'.$body,'isolated-incident-secret')],$body);
  $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
  $this->invoke($request);
 }
 public function test_signed_caller_cannot_choose_recipients():void {
  config(['solavel_sync.secret'=>'isolated-incident-secret']);
  $body=json_encode(['client_id'=>87,'organization_id'=>165,'document_kind'=>'receipt','outbox_id'=>1,'nonce'=>(string)Str::uuid(),'user_ids'=>[999]],JSON_THROW_ON_ERROR);$ts=(string)time();
  $request=Request::create('/api/tenancy/document-incident-context','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_SOLAVEL_TIMESTAMP'=>$ts,'HTTP_X_SOLAVEL_SIGNATURE'=>hash_hmac('sha256',$ts.'.'.$body,'isolated-incident-secret')],$body);
  $this->expectException(\Illuminate\Validation\ValidationException::class);$this->invoke($request);
 }
}
