<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read the shared Finance intent; Stock remains the sole warehouse writer. */
final class PendingInvoiceAmendment
{
    public function assertAvailable(int $financeOrganization,int $invoiceId,?string $ownOperation=null): void
    {
        $db=DB::connection('tenant');
        if(!$db->getSchemaBuilder()->hasTable('finance_sales_events'))return;
        if(!$db->transactionLevel())throw new \LogicException('Invoice amendment admission requires the native mutation transaction.');
        $query=$db->table('finance_sales_events')->where('organization_id',$financeOrganization)
            ->where('event_type','sales.invoice_amendment.v1')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(result, '$.invoice_id')) = ?",[(string)$invoiceId])
            ->whereNotIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(result, '$.state'))"),['completed','cancelled']);
        if($ownOperation!==null)$query->where('source_key','<>','invoice-amendment:'.$ownOperation);
        if($query->orderBy('id')->lockForUpdate()->exists())throw ValidationException::withMessages([
            'invoice'=>__('inventory.sales_handoff.invoice_amendment_pending'),
        ]);
    }
}
