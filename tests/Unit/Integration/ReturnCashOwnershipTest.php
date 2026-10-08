<?php
namespace Tests\Unit\Integration;
use App\Services\Sales\ReturnSourceOwnership;
use PHPUnit\Framework\TestCase;
final class ReturnCashOwnershipTest extends TestCase
{
    public function test_cash_source_requires_both_exact_native_command_and_request_identity():void
    {
        $command=['organization_id'=>16001,'shipment_id'=>12,'request_id'=>9,'status'=>'completed','source_document_type'=>'sales_receipt'];
        $request=['organization_id'=>16001,'id'=>9,'source_document_type'=>'sales_receipt'];
        $this->assertTrue(ReturnSourceOwnership::completedCashCommand($command,$request,16001,12));
        $this->assertFalse(ReturnSourceOwnership::completedCashCommand($command,$request,16002,12));
        $this->assertFalse(ReturnSourceOwnership::completedCashCommand($command,$request,16001,13));
        foreach (['organization_id'=>16002,'id'=>10,'source_document_type'=>'invoice'] as $field=>$value) {
            $different=$request;$different[$field]=$value;
            $this->assertFalse(ReturnSourceOwnership::completedCashCommand($command,$different,16001,12));
        }
        foreach (['organization_id'=>16002,'request_id'=>10,'source_document_type'=>'invoice','status'=>'pending'] as $field=>$value) {
            $different=$command;$different[$field]=$value;
            $this->assertFalse(ReturnSourceOwnership::completedCashCommand($different,$request,16001,12));
        }
        $this->assertFalse(ReturnSourceOwnership::completedCashCommand([],[],16001,12));
    }
}
