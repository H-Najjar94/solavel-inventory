<?php

namespace App\Services\Purchasing;

use App\Models\Tenant\IntegrationDocumentLifecycleMapping;
use App\Models\Tenant\IntegrationOrganizationMapping;
use App\Services\Stock\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PurchaseSourceOwnership
{
    public function newUsedBase(IntegrationOrganizationMapping $mapping, int $receiptId, int $lineId, string $factor, ?string $exceptSettlement = null): string
    {
        if (! Schema::connection('tenant')->hasTable('finance_purchase_settlements')) {
            return '0';
        }
        $receiptMapping = IntegrationDocumentLifecycleMapping::query()->where('organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('source_document_type', 'goods_receipt')->where('source_document_id', (string) $receiptId)->value('mapping_uuid');
        if (! $receiptMapping) {
            return '0';
        }
        $rows = DB::connection('tenant')->table('finance_purchase_settlements as s')
            ->join('finance_purchase_positions as p', function ($join) {
                $join->on('p.position_uuid', '=', 's.position_uuid')->on('p.organization_id', '=', 's.organization_id')
                    ->on('p.organization_mapping_uuid', '=', 's.organization_mapping_uuid');
            })->join('bills as b', function ($join) {
                $join->on('b.id', '=', 'p.bill_id')->on('b.organization_id', '=', 'p.organization_id')
                    ->on('b.journal_entry_id', '=', 'p.bill_journal_id');
            })->join('journal_entries as j', function ($join) {
                $join->on('j.id', '=', 'p.bill_journal_id')->on('j.organization_id', '=', 'p.organization_id');
            })->where('s.organization_id', $mapping->finance_organization_id)
            ->where('s.organization_mapping_uuid', $mapping->mapping_uuid)
            ->where('s.receipt_id', $receiptId)->where('s.receipt_line_id', $lineId)->where('s.receipt_mapping_uuid', $receiptMapping)
            ->where('j.status', 'posted')->whereNotNull('j.posted_at')->whereNull('j.voided_at')->whereNull('j.deleted_at')
            ->where(fn ($query) => $query->where('s.state', '<>', 'reversed')->orWhere('p.state', 'reversal_pending'))
            ->when($exceptSettlement, fn ($query) => $query->where('s.settlement_uuid', '<>', $exceptSettlement))
            ->get(['s.quantity']);
        $used = '0';
        foreach ($rows as $row) {
            $used = Decimal::add($used, Decimal::mul((string) $row->quantity, $factor, 8), 8);
        }

        return $used;
    }
}
