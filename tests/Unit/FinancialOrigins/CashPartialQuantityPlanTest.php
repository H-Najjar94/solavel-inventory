<?php
namespace Tests\Unit\FinancialOrigins;
use App\Services\FinancialOrigins\CashPartialQuantityPlan;
use DomainException;
use PHPUnit\Framework\TestCase;
final class CashPartialQuantityPlanTest extends TestCase
{
    private function source():array { return ['requested'=>'10','fulfilled'=>'4','cancelled'=>'0','refunded_unshipped'=>'0','refunded_shipped'=>'0','returned'=>'0','held'=>'0']; }
    public function test_refund_cancels_unshipped_demand_without_fabricating_return():void {
        $p=(new CashPartialQuantityPlan)->split($this->source(),'7','0');
        self::assertSame(['cancel_unshipped_quantity'=>'6.0000','refund_shipped_quantity'=>'1.0000','physical_return_quantity'=>'0.0000','remaining_dispatch_quantity'=>'0.0000'],$p);
    }
    public function test_physical_return_is_independent_of_financial_refund():void {
        $p=(new CashPartialQuantityPlan)->split($this->source(),'2','3');
        self::assertSame('3.0000',$p['physical_return_quantity']); self::assertSame('0.0000',$p['refund_shipped_quantity']);
    }
    public function test_another_prepared_hold_cannot_turn_deferred_refund_into_shipped_refund():void {
        $s=$this->source();$s['held']='6';$this->expectException(DomainException::class);(new CashPartialQuantityPlan)->split($s,'5','0');
    }
    public function test_overreturn_rejected():void { $this->expectException(DomainException::class);(new CashPartialQuantityPlan)->split($this->source(),'1','5'); }
    public function test_quantity_rounding_is_not_silent():void { $this->expectException(DomainException::class);(new CashPartialQuantityPlan)->split($this->source(),'1.00001','0'); }
}
