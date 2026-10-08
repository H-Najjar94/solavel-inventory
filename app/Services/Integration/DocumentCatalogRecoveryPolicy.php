<?php
namespace App\Services\Integration;
/** Admission for a current dependency recovery, never an ambiguous outcome or existing accountant document. */
final class DocumentCatalogRecoveryPolicy {
 public static function matches(string $type,int $id,array $payload,array $answer,object $native):bool {
  if(!in_array($type,['item','unit'],true)||$id<1||!in_array($payload['event_type']??null,['purchasing.receipt.confirmed','sales.shipment.confirmed'],true))return false;
  $sales=$payload['event_type']==='sales.shipment.confirmed';
  if(!in_array($answer['state']??null,['intervention','needs_information'],true)||!empty($answer[$sales?'invoice_id':'bill_id']))return false;
  if(empty($native->posted_at)||($native->status??null)!=='posted'||!empty($native->deleted_at)||!empty($native->reversed_at)||!empty($native->reversal_id)||!empty($native->reversal_sales_return_id))return false;
  $codes=(array)($answer['missing_information']??[]);if(!array_intersect($codes,[$type.'_mapping_required','item_or_unit_mapping_required']))return false;
  foreach($payload[$sales?'shipment':'receipt']['lines']??[]as$line)if((int)($line[$type.'_id']??0)===$id)return true;
  return false;
 }
}
