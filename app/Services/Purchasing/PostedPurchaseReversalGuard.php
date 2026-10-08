<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Models\Tenant\ReceivingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PostedPurchaseReversalGuard
{
    public function assertReceiptReversible(GoodsReceipt $receipt): void
    {
        if (! Schema::connection('tenant')->hasTable('finance_purchase_positions')) {
            return; // Existing receipts/older schemas retain their established guards.
        }
        $mapping = IntegrationOrganizationMapping::query()->where('solastock_organization_id', $receipt->organization_id)
            ->where('tenant_database_identity', DB::connection('tenant')->getDatabaseName())->first();
        if (! $mapping) {
            return;
        }
        $base = fn () => DB::connection('tenant')->table('finance_purchase_positions as p')
            ->join('bills as b', function ($join) {
                $join->on('b.id', '=', 'p.bill_id')->on('b.organization_id', '=', 'p.organization_id')
                    ->on('b.journal_entry_id', '=', 'p.bill_journal_id');
            })->join('journal_entries as j', function ($join) {
                $join->on('j.id', '=', 'p.bill_journal_id')->on('j.organization_id', '=', 'p.organization_id');
            })->where('p.organization_id', $mapping->finance_organization_id)
            ->where('p.organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('j.status', 'posted')->whereNotNull('j.posted_at')->whereNull('j.voided_at')->whereNull('j.deleted_at');
        $blocked = false;
        if ($receipt->receiving_request_id) {
            $request = ReceivingRequest::query()->findOrFail($receipt->receiving_request_id);
            $blocked = $base()->where('p.bill_id', $request->source_bill_id)->exists();
        }
        if (! $blocked && Schema::connection('tenant')->hasTable('finance_purchase_settlements')) {
            $blocked = $base()->join('finance_purchase_settlements as s', function ($join) {
                $join->on('s.position_uuid', '=', 'p.position_uuid')->on('s.organization_id', '=', 'p.organization_id')
                    ->on('s.organization_mapping_uuid', '=', 'p.organization_mapping_uuid');
            })->where('s.receipt_id', $receipt->id)->where('s.state', '<>', 'reversed')->exists();
        }
        // Also close the receipt-first Bill-post -> first settlement-worker window.
        if (! $blocked && Schema::connection('tenant')->hasTable('finance_purchase_receipts')) {
            $blocked = $base()->join('finance_purchase_receipts as r', function ($join) {
                $join->on('r.bill_id', '=', 'p.bill_id')->on('r.organization_id', '=', 'p.organization_id')
                    ->on('r.organization_mapping_uuid', '=', 'p.organization_mapping_uuid');
            })->where('r.stock_receipt_id', $receipt->id)->exists();
        }
        if ($blocked) {
            throw ValidationException::withMessages(['goods_receipt_id' => __('receiving.financial_reversal_required')]);
        }
    }
}
