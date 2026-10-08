<?php
namespace Tests\Unit\Catalog;
use App\Services\Catalog\CatalogSourceSnapshot;
use PHPUnit\Framework\TestCase;
final class CatalogSourceSnapshotTest extends TestCase {
 public function test_local_prices_and_audit_timestamps_do_not_change_shared_revision():void {
  $row=['id'=>12,'name'=>'لوح','sku'=>'P12','base_unit_id'=>2,'category_id'=>4,'purchase_price'=>'490','selling_price'=>'550','updated_at'=>'yesterday'];
  $before=CatalogSourceSnapshot::fields('item',$row);$row['purchase_price']='0';$row['updated_at']='today';
  self::assertSame(CatalogSourceSnapshot::revision($before),CatalogSourceSnapshot::revision(CatalogSourceSnapshot::fields('item',$row)));
  $row['name']='Panel';self::assertNotSame(CatalogSourceSnapshot::revision($before),CatalogSourceSnapshot::revision(CatalogSourceSnapshot::fields('item',$row)));
 }
 public function test_conversion_dependencies_are_exact_deduplicated_source_ids():void {
  $fields=CatalogSourceSnapshot::fields('item',['id'=>3,'name'=>'Panel','base_unit_id'=>2,'category_id'=>4],[['id'=>8,'from_unit_id'=>2,'to_unit_id'=>7,'factor'=>'1000.00000000'],['id'=>6,'from_unit_id'=>7,'to_unit_id'=>2,'factor'=>'0.00100000']]);
  self::assertSame([6,8],array_column($fields['conversions'],'id'));self::assertSame([['category',4],['unit',2],['unit',7]],CatalogSourceSnapshot::dependencies($fields));
 }
 public function test_parent_category_is_dependency_and_deleted_source_remains_identifiable():void {
  $fields=CatalogSourceSnapshot::fields('category',['id'=>9,'name'=>'Child','parent_id'=>4,'deleted_at'=>'2026-10-08']);
  self::assertFalse($fields['active']);self::assertSame(9,$fields['source_id']);self::assertSame([['category',4]],CatalogSourceSnapshot::dependencies($fields));
 }
}
