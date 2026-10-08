<?php
namespace Tests\Feature\Sales;
use App\Services\InventoryWorkspace\WorkspaceSignature;
use Tests\TestCase;
final class SalesWorkspaceSignatureTest extends TestCase {
 protected function setUp():void {parent::setUp();config(['finance_workspace.secret'=>str_repeat('s',48),'cache.default'=>'array']);}
 public function test_unsigned_physical_sales_command_is_rejected_before_actor_or_tenant_admission():void {
  $this->postJson(WorkspaceSignature::PATH,['action'=>'sales.fulfillment.execute'])->assertStatus(403);$this->assertTrue(\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::covers('sales.request.reduce-demand'));$this->assertFalse(\App\Services\InventoryWorkspace\FinanceDocumentLifecycleAuthority::covers('sales.fulfillment.execute'));
 }
 public function test_valid_signed_sales_command_reaches_native_validation_and_transport_nonce_cannot_replay():void {
  $body=json_encode(['action'=>'sales.fulfillment.execute']);$ts=(string)time();$nonce=bin2hex(random_bytes(24));$headers=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTP_X_WORKSPACE_TIMESTAMP'=>$ts,'HTTP_X_WORKSPACE_NONCE'=>$nonce,'HTTP_X_WORKSPACE_SIGNATURE'=>WorkspaceSignature::sign($body,$ts,$nonce,str_repeat('s',48))];
  $this->call('POST',WorkspaceSignature::PATH,[],[],[],$headers,$body)->assertStatus(422)->assertJsonValidationErrors(['actor_id','organization_id','client_id','finance_organization_id']);
  $this->call('POST',WorkspaceSignature::PATH,[],[],[],$headers,$body)->assertStatus(409);
 }
}
