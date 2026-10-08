<?php
namespace App\Services\Returns;
use App\Models\Tenant\{SupplierReturn,IntegrationOrganizationMapping,IntegrationSetting};
use App\Services\Access\InventoryPermissionService;
use App\Services\Integration\{ApprovedFinanceIntegrationEntitlement,FinanceOnboardingReadiness,OrganizationAccountRequirements,SolaBooksOutboxDeliveryService};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fresh signed consumer admission before any physical transaction; no default-enabled capability. */
final class SupplierReturnReadiness
{
    public function prepare(SupplierReturn $return): ?array
    {
        $db=DB::connection('tenant');
        $maps=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$return->organization_id)
            ->where('tenant_database_identity',$db->getDatabaseName())->get();
        if($maps->isEmpty())return null;
        if($maps->count()!==1||$db->transactionLevel()!==0)$this->blocked();
        $map=$maps->sole();$this->assertMapping($map);
        $user=request()->user();
        abort_unless($user&&(int)$user->id>0&&app(InventoryPermissionService::class)->can($user,'inventory.manage_returns'),403);
        app(ApprovedFinanceIntegrationEntitlement::class)->assertApproved($map);
        app(FinanceOnboardingReadiness::class)->assertComplete((int)$map->central_organization_id);
        $this->assertSchemas();
        $proof=app(SolaBooksOutboxDeliveryService::class)->supplierReturnCapabilities($map,(int)$user->id,(int)$return->id,(int)$return->goods_receipt_id);
        if(!self::accepts($proof,$map,(int)$return->id,(int)$user->id,(int)$return->goods_receipt_id))$this->blocked();
        return $proof;
    }

    public function assertLocked(SupplierReturn $return,?array $proof): void
    {
        $db=DB::connection('tenant');
        $maps=IntegrationOrganizationMapping::query()->where('solastock_organization_id',$return->organization_id)
            ->where('tenant_database_identity',$db->getDatabaseName())->get();
        if($maps->isEmpty()){if($proof!==null)$this->blocked();return;}
        if($maps->count()!==1||$proof===null)$this->blocked();
        $map=$maps->sole();$this->assertMapping($map);$this->assertSchemas();
        $user=request()->user();
        if(!$user||!self::accepts($proof,$map,(int)$return->id,(int)$user->id,(int)$return->goods_receipt_id))$this->blocked();
        abort_unless(app(InventoryPermissionService::class)->can($user,'inventory.manage_returns'),403);
        app(OrganizationAccountRequirements::class)->assertOperationReady((int)$return->organization_id,'supplier_return.posted');
    }

    public static function accepts(array $proof,object $map,int $returnId,int $actorId,int $receiptId): bool
    {
        return ($proof['allowed']??false)===true&&($proof['contract_version']??null)==='supplier-return.v1'
            &&($proof['organization_mapping_uuid']??null)===$map->mapping_uuid
            &&(int)($proof['finance_organization_id']??0)===(int)$map->finance_organization_id
            &&(int)($proof['inventory_organization_id']??0)===(int)$map->solastock_organization_id
            &&(int)($proof['central_organization_id']??0)===(int)$map->central_organization_id
            &&(int)($proof['actor_id']??0)===$actorId&&$actorId>0
            &&(int)($proof['source_return_id']??0)===$returnId&&$returnId>0
            &&(int)($proof['source_receipt_id']??0)===$receiptId&&$receiptId>0
            &&array_values($proof['supported_branches']??[])===['unbilled','matched_physical','bridged_unmatched']
            &&($proof['base_currency_code']??null)===$map->base_currency_code
            &&array_values($proof['consumer_schemas']??[])===[186,190,193]
            &&in_array('supplier_return.posted',$proof['operations']??[],true)
            &&in_array('supplier_return.reversed',$proof['operations']??[],true);
    }
    private function assertMapping(object $map): void
    {
        $setting=IntegrationSetting::query()->where('organization_id',$map->solastock_organization_id)->where('integration','solabooks')->first();
        if($map->status!=='verified'||$map->activation_state!=='active'||!$setting||$setting->mode!=='active')$this->blocked();
    }
    private function assertSchemas(): void
    {
        $schema=DB::connection('tenant')->getSchemaBuilder();
        foreach([
            'supplier_returns'=>['return_uuid','goods_receipt_id','posted_at','reversal_id'],
            'supplier_return_lines'=>['source_stock_ledger_id','actual_return_cost_base','unit_conversion_hash'],
            'finance_supplier_returns'=>['reviewed_bill_journal_id','bridge_journal_id','stock_return_journal_id'],
            'finance_supplier_return_credit_allocations'=>['branch','state','actual_out_base','bill_journal_id','snapshot'],
            'finance_supplier_return_reversals'=>['stock_reversal_id','voided_note_journal_id','inverse_import_journal_id','source_hash'],
        ]as$table=>$columns)if(!$schema->hasTable($table)||!$schema->hasColumns($table,$columns))$this->blocked();
    }
    private function blocked():never {throw ValidationException::withMessages(['integration'=>__('return_reversal.supplier_return_setup_required')]);}
}
