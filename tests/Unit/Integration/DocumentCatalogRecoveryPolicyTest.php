<?php
namespace Tests\Unit\Integration;
require_once __DIR__.'/../../../app/Services/Integration/DocumentCatalogRecoveryPolicy.php';
use App\Services\Integration\DocumentCatalogRecoveryPolicy as Policy;
use PHPUnit\Framework\TestCase;
final class DocumentCatalogRecoveryPolicyTest extends TestCase {
 private function source():object{return(object)['status'=>'posted','posted_at'=>'2026-10-08 10:00:00','deleted_at'=>null,'reversed_at'=>null,'reversal_id'=>null];}
 private function payload():array{return['event_type'=>'purchasing.receipt.confirmed','receipt'=>['lines'=>[['item_id'=>71,'unit_id'=>9]]]];}
 private function answer():array{return['state'=>'needs_information','bill_id'=>null,'missing_information'=>['item_mapping_required']];}
 public function test_only_exact_missing_dependency_can_resume():void{$this->assertTrue(Policy::matches('item',71,$this->payload(),$this->answer(),$this->source()));$this->assertFalse(Policy::matches('item',72,$this->payload(),$this->answer(),$this->source()));$this->assertFalse(Policy::matches('unit',9,$this->payload(),$this->answer(),$this->source()));}
 public function test_existing_accountant_document_is_not_changed():void{$answer=$this->answer();$answer['bill_id']=14;$this->assertFalse(Policy::matches('item',71,$this->payload(),$answer,$this->source()));}
 public function test_uncertain_or_unrelated_failures_are_not_resumed():void{$answer=$this->answer();$answer['state']='unknown_outcome';$this->assertFalse(Policy::matches('item',71,$this->payload(),$answer,$this->source()));$answer=$this->answer();$answer['missing_information']=['dated_exchange_rate_required'];$this->assertFalse(Policy::matches('item',71,$this->payload(),$answer,$this->source()));}
 public function test_reversed_deleted_and_unposted_sources_remain_closed():void{foreach(['reversal_id'=>2,'deleted_at'=>'2026-10-08','posted_at'=>null,'reversal_sales_return_id'=>4]as$key=>$value){$source=$this->source();$source->$key=$value;$this->assertFalse(Policy::matches('item',71,$this->payload(),$this->answer(),$source));}}
 public function test_shipment_unit_intervention_is_distinct_from_receipt_or_reverse():void{$payload=['event_type'=>'sales.shipment.confirmed','shipment'=>['lines'=>[['item_id'=>71,'unit_id'=>9]]]];$answer=['state'=>'intervention','invoice_id'=>null,'missing_information'=>['item_or_unit_mapping_required']];$this->assertTrue(Policy::matches('unit',9,$payload,$answer,$this->source()));$payload['event_type']='sales.shipment.reversed';$this->assertFalse(Policy::matches('unit',9,$payload,$answer,$this->source()));}
}
