<?php
namespace Tests\Unit\Returns;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,3).'/app/Services/Returns/SupplierReturnReadiness.php';
use App\Services\Returns\SupplierReturnReadiness;
final class SupplierReturnReadinessTest extends TestCase
{
 public function test_exact_signed_paired_capability_only():void
 {
  $map=(object)['mapping_uuid'=>'immutable-map','finance_organization_id'=>20,'solastock_organization_id'=>94,'central_organization_id'=>94,'base_currency_code'=>'JOD'];
  $proof=['allowed'=>true,'contract_version'=>'supplier-return.v1','organization_mapping_uuid'=>'immutable-map','finance_organization_id'=>20,'inventory_organization_id'=>94,'central_organization_id'=>94,'actor_id'=>335,'source_return_id'=>10,'source_receipt_id'=>9,'supported_branches'=>['unbilled','matched_physical','bridged_unmatched'],'base_currency_code'=>'JOD','consumer_schemas'=>[186,190,193],'operations'=>['supplier_return.posted','supplier_return.reversed']];
  self::assertTrue(SupplierReturnReadiness::accepts($proof,$map,10,335,9));
  foreach(['allowed'=>false,'actor_id'=>0,'source_return_id'=>11,'source_receipt_id'=>8,'supported_branches'=>['unbilled'],'inventory_organization_id'=>95,'finance_organization_id'=>21,'central_organization_id'=>95,'organization_mapping_uuid'=>'other','base_currency_code'=>'AED','consumer_schemas'=>[186,203],'operations'=>['supplier_return.posted']] as $field=>$value){self::assertFalse(SupplierReturnReadiness::accepts(array_replace($proof,[$field=>$value]),$map,10,335,9),$field);}
 }
}
