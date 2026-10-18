<?php
namespace Tests\Feature\Stock;
use App\Services\Access\InventoryUiAccess;
use App\Services\Entitlements\InventoryCommercialEntitlementService;
use Tests\TestCase;
class InventoryUiAccessTest extends TestCase {
 private function service(bool $enforced):InventoryUiAccess {
  config(['inventory_entitlements.feature_enforcement'=>$enforced]);$entitlements=\Mockery::mock(InventoryCommercialEntitlementService::class);
  if($enforced){$entitlements->shouldReceive('checkPermission')->andReturnUsing(fn($permission)=>['allowed'=>$permission!=='inventory.view_traceability','reason_code'=>$permission==='inventory.view_traceability'?'feature_not_in_plan':'allowed']);$entitlements->shouldReceive('checkFeature')->andReturnUsing(fn($feature)=>['allowed'=>$feature!=='stock.transfers','reason_code'=>$feature==='stock.transfers'?'feature_not_in_plan':'allowed']);}
  else {$entitlements->shouldNotReceive('checkPermission');$entitlements->shouldNotReceive('checkFeature');}
  return new InventoryUiAccess($entitlements);
 }
 private function rule(array $result,string $path,string $method='GET'):array{return collect($result['routes'])->first(fn($rule)=>$rule['pattern']===$path&&$rule['method']===$method)??[];}
 public function test_plan_denied_role_is_removed_and_direct_recalls_route_is_denied():void {$result=$this->service(true)->describe(['inventory.view_traceability','inventory.view_stock']);$this->assertNotContains('inventory.view_traceability',$result['permissions']);$this->assertFalse($this->rule($result,'/recalls')['allowed']);$this->assertSame('feature_not_in_plan',$this->rule($result,'/recalls')['reason']);}
 public function test_route_feature_restriction_does_not_hide_unrelated_base_stock_access():void {$result=$this->service(true)->describe(['inventory.view_stock','inventory.transfer_stock']);$this->assertContains('inventory.view_stock',$result['permissions']);$this->assertFalse($this->rule($result,'/transfers','POST')['allowed']);$this->assertTrue($this->rule($result,'/balances')['allowed']);}
 public function test_missing_role_is_denied_even_when_plan_allows_feature():void {$result=$this->service(false)->describe([]);$this->assertFalse($this->rule($result,'/recalls')['allowed']);$this->assertSame('permission_denied',$this->rule($result,'/recalls')['reason']);}
 public function test_disabled_commercial_enforcement_preserves_role_based_access():void {$result=$this->service(false)->describe(['inventory.view_traceability']);$this->assertContains('inventory.view_traceability',$result['permissions']);$this->assertTrue($this->rule($result,'/recalls')['allowed']);}
}
