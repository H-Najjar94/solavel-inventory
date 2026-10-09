<?php
namespace Tests\Unit\Integration;
use App\Services\Integration\DocumentHandoffOutcome;
use PHPUnit\Framework\TestCase;
final class DocumentHandoffOutcomeTest extends TestCase
{
    public function test_accepted_receipt_without_bill_is_actionable_not_delivered():void
    {
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'needs_information','bill_id'=>null,'missing_information'=>['supplier_mapping_required']]],'purchasing.receipt.confirmed');
        $this->assertFalse($r['successful']);$this->assertTrue($r['intervention']);
        $this->assertSame(['supplier_mapping_required'],$r['data']['missing_information']);
    }
    public function test_shipment_intervention_does_not_count_as_created_invoice():void
    {
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'intervention','invoice_id'=>null]],'sales.shipment.confirmed');
        $this->assertFalse($r['successful']);$this->assertTrue($r['intervention']);
    }
    public function test_resolved_replay_accepts_actual_native_link():void
    {
        foreach (['purchasing.receipt.confirmed'=>'bill_id','sales.shipment.confirmed'=>'invoice_id','sales.return.confirmed'=>'credit_note_id'] as $event=>$field) {
            $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'draft_review',$field=>42,'replayed'=>true]],$event);
            $this->assertTrue($r['successful']);$this->assertFalse($r['intervention']);
        }
    }
    public function test_return_source_review_needs_actual_credit_draft():void
    {
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'source_review','credit_note_id'=>null,'missing_information'=>['original_invoice_required']]],'sales.return.confirmed');
        $this->assertFalse($r['successful']);$this->assertTrue($r['intervention']);
        $this->assertSame(['original_invoice_required'],$r['data']['missing_information']);
    }
    public function test_unknown_http_outcome_remains_retryable_even_with_untrusted_link():void
    {
        $r=DocumentHandoffOutcome::classify(['successful'=>false,'data'=>['state'=>'draft_review','invoice_id'=>42]],'sales.shipment.confirmed');
        $this->assertFalse($r['successful']);$this->assertFalse($r['intervention']);
    }
    public function test_reversal_and_other_existing_contracts_do_not_require_new_commercial_document():void
    {
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'reversed']],'purchasing.receipt.reversed');
        $this->assertTrue($r['successful']);
    }
    public function test_supplier_return_requires_native_draft_or_unbilled_bridge():void
    {
        foreach ([['state'=>'draft_review','source_id'=>11,'debit_note_id'=>12],
                  ['state'=>'linked_existing_review','source_id'=>11,'debit_note_id'=>12],
                  ['state'=>'credit_posted','source_id'=>11,'debit_note_id'=>12],
                  ['state'=>'unbilled_cleared','source_id'=>11,'journal_id'=>13]] as $data) {
            $this->assertTrue(DocumentHandoffOutcome::classify(['successful'=>true,'data'=>$data],'purchasing.return.confirmed')['successful']);
        }
        foreach ([['state'=>'source_review','source_id'=>11],['state'=>'draft_review','source_id'=>11],
                  ['state'=>'unbilled_cleared','source_id'=>11],['state'=>'unknown','source_id'=>11,'debit_note_id'=>12]] as $data) {
            $this->assertFalse(DocumentHandoffOutcome::classify(['successful'=>true,'data'=>$data],'purchasing.return.confirmed')['successful']);
        }
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'source_review','source_id'=>11]],'purchasing.return.confirmed');
        $this->assertTrue($r['intervention']);
        $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'credit_voided_review','source_id'=>11,'debit_note_id'=>12]],'purchasing.return.confirmed');
        $this->assertFalse($r['successful']);$this->assertTrue($r['intervention']);
    }
    public function test_supplier_return_reversal_requires_persisted_source_and_completed_state():void
    {
        $this->assertTrue(DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'reversed','source_id'=>11]],'purchasing.return.reversed')['successful']);
        foreach (['reversal_review','reversal_settlement_pending'] as $state) {
            $r=DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>$state,'source_id'=>11]],'purchasing.return.reversed');
            $this->assertFalse($r['successful']);$this->assertTrue($r['intervention']);
        }
        $this->assertFalse(DocumentHandoffOutcome::classify(['successful'=>true,'data'=>['state'=>'reversed']],'purchasing.return.reversed')['successful']);
    }
}
