<?php
namespace Tests\Unit;
use App\Services\Returns\SupplierReturnReadiness;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use PHPUnit\Framework\TestCase;
/** Explicit signed transport seam, not a live cross-app admission claim. */
final class SupplierReturnReadinessTest extends TestCase {
 private function proof():array{return ['ready'=>true,'missing'=>[],'source_projection_ready'=>true,'unbilled_bridge_ready'=>true,'posted_bill_credit_ready'=>true,'account_roles'=>['inventory'=>101,'grni'=>102,'supplier_return_clearing'=>103,'purchase_price_variance'=>104]];}
 private function service(array $proof):SupplierReturnReadiness{$transport=$this->createMock(SolaBooksOutboxDeliveryService::class);$transport->expects($this->once())->method('supplierReturnCapability')->willReturn($proof);return new SupplierReturnReadiness($transport);}
 public function test_only_all_qualified_native_consumers_and_accounts_can_be_ready():void{$result=$this->service($this->proof())->check();$this->assertTrue($result['ready']);$this->assertSame([],$result['missing']);}
 public function test_unknown_posted_credit_consumer_and_missing_account_stay_unready():void{$proof=$this->proof();unset($proof['posted_bill_credit_ready']);$proof['account_roles']['supplier_return_clearing']=null;$result=$this->service($proof)->check();$this->assertFalse($result['ready']);$this->assertContains('posted_bill_credit_ready',$result['missing']);$this->assertContains('account_role_supplier_return_clearing',$result['missing']);}
 public function test_signed_transport_foreign_scope_rejection_never_becomes_ready():void{$transport=$this->createMock(SolaBooksOutboxDeliveryService::class);$transport->expects($this->once())->method('supplierReturnCapability')->willThrowException(new \RuntimeException('Rejected scope'));$result=(new SupplierReturnReadiness($transport))->check();$this->assertFalse($result['ready']);$this->assertSame(['consumer_temporarily_unavailable'],$result['missing']);}
}
