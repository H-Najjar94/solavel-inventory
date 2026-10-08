<?php
namespace App\Services\Sales;

use App\Models\Tenant\{IntegrationOrganizationMapping,SalesReturn};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared-tenant source proof; lock in the same order as native Credit Note posting. */
final class SalesReturnFinancialReversalGuard
{
    public function lockAndAssert(SalesReturn $return): void
    {
        $db=DB::connection('tenant');
        if(!$db->getSchemaBuilder()->hasTable('finance_sales_returns'))return;
        $sources=$db->table('finance_sales_returns as r')->join('integration_organization_mappings as m',function($join){
            $join->on('m.mapping_uuid','=','r.organization_mapping_uuid')->on('m.finance_organization_id','=','r.organization_id');
        })->where('m.solastock_organization_id',$return->organization_id)->where('m.tenant_database_identity',$db->getDatabaseName())
            ->where('r.stock_return_id',$return->id)->get(['r.*']);
        if($sources->isEmpty())return;
        if($sources->count()!==1)$this->review();
        $hint=$sources->sole();
        $mapping=IntegrationOrganizationMapping::query()->where('mapping_uuid',$hint->organization_mapping_uuid)
            ->where('solastock_organization_id',$return->organization_id)->where('tenant_database_identity',$db->getDatabaseName())->firstOrFail();
        $sourceQuery=fn()=>$db->table('finance_sales_returns')->where('organization_id',$mapping->finance_organization_id)
            ->where('organization_mapping_uuid',$mapping->mapping_uuid)->where('stock_return_id',$return->id);
        if($hint->invoice_id){
            $invoice=$db->table('invoices')->where('organization_id',$mapping->finance_organization_id)->where('id',$hint->invoice_id)->lockForUpdate()->first();
            if(!$invoice)$this->review();
        }
        if($hint->credit_note_id){
            $credit=$db->table('credit_notes')->where('organization_id',$mapping->finance_organization_id)->where('id',$hint->credit_note_id)->lockForUpdate()->first();
            if(!$credit||!$hint->invoice_id)$this->review();
        }
        $current=$sourceQuery()->lockForUpdate()->first();
        if(!$current||$current->invoice_id!==$hint->invoice_id||$current->credit_note_id!==$hint->credit_note_id)$this->review();
        if(!$current->credit_note_id)return;
        $active=fn($query)=>$query->where('j.organization_id',$mapping->finance_organization_id)
            ->where('j.status','posted')->whereNotNull('j.posted_at')->whereNull('j.voided_at')->whereNull('j.deleted_at');
        $blocked=$active($db->table('journal_entries as j')->where('j.source','NOTE')
            ->where('j.source_type','App\\Models\\CreditNote')->where('j.source_id',$current->credit_note_id))->exists();
        if(!$blocked&&$db->getSchemaBuilder()->hasTable('finance_sales_credit_allocations')){
            $blocked=$active($db->table('finance_sales_credit_allocations as a')->join('journal_entries as j',function($join){
                $join->on('j.id','=','a.journal_entry_id')->on('j.organization_id','=','a.organization_id');
            })->where('a.organization_id',$mapping->finance_organization_id)->where('a.return_mapping_uuid',$current->return_mapping_uuid)
                ->where('a.credit_note_id',$current->credit_note_id)->where('a.state','posted'))->exists();
        }
        if($blocked)throw ValidationException::withMessages(['sales_return_id'=>__('return_reversal.credit_before_return')]);
    }
    private function review():never
    {
        throw ValidationException::withMessages(['sales_return_id'=>__('return_reversal.refresh_source')]);
    }
}
