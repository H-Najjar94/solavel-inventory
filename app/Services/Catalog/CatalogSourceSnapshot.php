<?php
namespace App\Services\Catalog;
use Illuminate\Support\Facades\DB;
/** Canonical commercial revision; timestamps and local purchase/selling prices never sync. */
final class CatalogSourceSnapshot {
 public const TABLES=['item'=>'items','unit'=>'units','category'=>'item_categories'];
 public static function fields(string $type,array $row,array $conversions=[]):array {
  if(!isset(self::TABLES[$type]))throw new \InvalidArgumentException('Unsupported catalog source');
  $common=['entity_type'=>$type,'source_id'=>(int)$row['id'],'name'=>(string)$row['name'],'active'=>empty($row['deleted_at'])&&(bool)($row['is_active']??true)];
  return $common+match($type){
   'unit'=>['symbol'=>(string)($row['code']??''),'kind'=>(string)($row['kind']??'count')],
   'category'=>['parent_id'=>empty($row['parent_id'])?null:(int)$row['parent_id']],
   'item'=>['sku'=>(string)($row['sku']??''),'category_id'=>empty($row['category_id'])?null:(int)$row['category_id'],'base_unit_id'=>(int)$row['base_unit_id'],
    'tracking_type'=>(string)($row['tracking_type']??'none'),'valuation_method'=>(string)($row['costing_method']??'average'),'conversions'=>self::conversions($conversions)],
  };
 }
 public static function conversions(array $rows):array {
  usort($rows,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);$result=[];
  foreach($rows as$r)$result[]=['id'=>(int)$r['id'],'from_unit_id'=>(int)$r['from_unit_id'],'to_unit_id'=>(int)$r['to_unit_id'],'factor'=>(string)$r['factor'],'version'=>(int)($r['version']??1),'active'=>(bool)($r['is_active']??true)];return $result;
 }
 public function capture(string $type,int $id,int $org):?array {
  $row=DB::connection('tenant')->table(self::TABLES[$type]??throw new \InvalidArgumentException('Unsupported source'))->where('organization_id',$org)->where('id',$id)->first();if(!$row)return null;
  $conversions=$type==='item'?DB::connection('tenant')->table('unit_conversions')->where('organization_id',$org)->where('item_id',$id)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all():[];
  return self::fields($type,(array)$row,$conversions);
 }
 public static function encode(array $fields):string{return json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 public static function revision(array $fields):string{return hash('sha256',self::encode($fields));}
 public static function dependencies(array $fields):array {
  $refs=[];if($fields['entity_type']==='category'&&!empty($fields['parent_id']))$refs[]=['category',(int)$fields['parent_id']];
  if($fields['entity_type']==='item'){
   if(!empty($fields['category_id']))$refs[]=['category',(int)$fields['category_id']];$refs[]=['unit',(int)$fields['base_unit_id']];
   foreach($fields['conversions']as$c){$refs[]=['unit',$c['from_unit_id']];$refs[]=['unit',$c['to_unit_id']];}
  }
  $unique=[];foreach($refs as[$type,$id])if($id>0)$unique[$type.':'.$id]=[$type,$id];return array_values($unique);
 }
}
