<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Support\Facades\{DB,Schema};
/** Dedicated closed human-authorized native value bridge; physical Stock privileges remain separate. */
final class SupplierCreditValueWorkspaceService
{
 public function dispatch(array $input,IntegrationOrganizationMapping $mapping):array
 {
  $actor=(int)($input['actor_id']??0);$action=substr((string)($input['action']??''),strlen('purchasing.credit-value.'));
  abort_unless($actor>0 && ($input['action']??null)==='purchasing.credit-value.'.$action
   && in_array($action,['prepare','apply','status','release','reverse'],true)
   && ($input['authority_kind']??null)==='posted_supplier_credit_value' && DB::connection('tenant')->transactionLevel()===0,403);
  foreach(['finance_purchase_credit_allocations','purchase_valuation_holds','integration_purchase_cost_adjustments','integration_purchase_cost_adjustment_components']as$table)
   abort_unless(Schema::connection('tenant')->hasTable($table),409,'workspace_schema_not_ready');
  $rules=['allocation_uuid'=>'required|uuid','operation_uuid'=>'required|uuid','organization_mapping_uuid'=>'required|uuid','position_uuid'=>'required|uuid',
   'direction'=>'required|in:forward,reverse','plan_revision'=>'required|integer|min:1','plan_fingerprint'=>'sometimes|string|size:64'];
  foreach(['debit_note_id','debit_note_line_id','source_bill_id','bill_line_id','bill_journal_id']as$key)$rules[$key]='required|integer|min:1';
  foreach(['note_revision','bill_revision','source_hash']as$key)$rules[$key]='required|string|size:64';
  $raw=(array)($input['data']??[]);abort_unless(!array_diff(array_keys($raw),array_keys($rules)),422);
  $facts=validator($raw,$rules)->validate();
  abort_unless($facts['organization_mapping_uuid']===$mapping->mapping_uuid,403);
  // Signed remote authorization is completed before native source/valuation locks; timeout leaves intent pending.
  $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeSupplierCreditValue($facts,$action,$actor);
  return DB::connection('tenant')->transaction(function()use($facts,$proof,$mapping,$action,$actor){
   $authority=SupplierCreditCostAuthority::fromLockedNativeProvenance($facts,$proof,$mapping,$action,$actor);
   return app(HeldSupplierCreditValueService::class)->executeLocked($authority);
  },5);
 }
}
