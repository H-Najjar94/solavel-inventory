<?php
namespace App\Services\PurchasingCredits;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Integration\SolaBooksOutboxDeliveryService;
use Illuminate\Support\Facades\{DB,Schema};
/** Dedicated positive-user signed closure; no warehouse login, selection or quantity operation. */
final class SupplierCreditReceiptRestoreWorkspaceService
{
 public function dispatch(array $input,IntegrationOrganizationMapping $mapping):array
 {
  $actor=(int)($input['actor_id']??0);$prefix='purchasing.credit-receipt-restore.';$action=substr((string)($input['action']??''),strlen($prefix));
  abort_unless($actor>0&&($input['action']??null)===$prefix.$action&&in_array($action,['prepare','apply','status','release'],true)
   &&($input['authority_kind']??null)==='posted_supplier_credit_receipt_restore'&&DB::connection('tenant')->transactionLevel()===0,403);
  foreach(['finance_purchase_credit_receipt_claims','supplier_credit_value_effects','purchase_valuation_holds']as$table)
   abort_unless(Schema::connection('tenant')->hasTable($table),409,'workspace_schema_not_ready');
  $rules=[];foreach(['organization_mapping_uuid','allocation_uuid','settlement_uuid','position_uuid','restore_operation_uuid']as$key)$rules[$key]='required|uuid';
  foreach(['debit_note_id','source_bill_id','bill_journal_id','original_journal_id','plan_revision']as$key)$rules[$key]='required|integer|min:1';
  foreach(['source_hash','claim_revision_hash']as$key)$rules[$key]='required|string|size:64';$rules['plan_fingerprint']='sometimes|string|size:64';
  $raw=(array)($input['data']??[]);abort_unless(!array_diff(array_keys($raw),array_keys($rules)),422);$facts=validator($raw,$rules)->validate();
  abort_unless($facts['organization_mapping_uuid']===$mapping->mapping_uuid,403);
  $proof=app(SolaBooksOutboxDeliveryService::class)->authorizeSupplierCreditReceiptRestore($facts,$action,$actor);
  return DB::connection('tenant')->transaction(function()use($facts,$proof,$mapping,$action,$actor){
   $authority=SupplierCreditReceiptRestoreAuthority::fromLocked($facts,$proof,$mapping,$action,$actor);
   return app(HeldSupplierCreditReceiptRestoreService::class)->executeLocked($authority);
  },5);
 }
}
