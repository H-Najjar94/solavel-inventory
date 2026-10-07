<?php
namespace App\Services\Returns;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
/** Installed consumer proof, independent of commercial entitlements or event registry presence. */
final class SupplierReturnReadiness {
 public function __construct(private SolaBooksOutboxDeliveryService $transport){}
 public function check():array {
  try{$proof=$this->transport->supplierReturnCapability();}
  catch(\Throwable $error){return ['ready'=>false,'missing'=>['consumer_temporarily_unavailable']];}
  $missing=(array)($proof['missing']??[]);
  if(($proof['ready']??false)!==true)$missing[]='consumer_not_ready';
  foreach(['source_projection_ready','unbilled_bridge_ready','posted_bill_credit_ready']as$flag)if(($proof[$flag]??false)!==true)$missing[]=$flag;
  foreach(['inventory','grni','supplier_return_clearing','purchase_price_variance']as$role){$id=$proof['account_roles'][$role]??null;if(!is_numeric($id)||(int)$id<1)$missing[]='account_role_'.$role;}
  return ['ready'=>$missing===[],'missing'=>array_values(array_unique($missing)),'account_roles'=>$proof['account_roles']??[]];
 }
}
